<?php

declare(strict_types=1);

namespace App\Envelope;

use App\Auth\Actor;
use App\Bill\Schedule;
use App\Category\SystemBuckets;
use App\Money\CurrencyCatalog;
use App\PayCycle\Period;
use App\Support\Dates;
use App\Trip\Drive;
use App\Trip\TripService;
use PDO;

/**
 * Writes bill dues into the oldest active pay cycle.
 * A closed span is left alone. Mark paid does not call this.
 */
final class BillFill
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
        private readonly Actor $actor,
        private readonly ?Drive $drive = null,
    ) {
    }

    public function apply(string $today): void
    {
        $today = $this->day($today);
        $home = $this->home();
        if ($home === null) {
            return;
        }
        SystemBuckets::ensure($this->pdo, $this->actor->id());
        $open = $this->openSpan($home, $today);
        $next = $this->nextSpan($home, $today);
        $openId = $this->storePeriod($home, $open['start'], $open['end']);
        $nextId = null;
        if ($next !== null) {
            $nextId = $this->storePeriod($home, $next['start'], $next['end']);
        }
        $picture = $this->picture($today, true);
        $this->write($openId, $this->withFuel($picture['open'], $open['start'], $today));
        if ($nextId !== null && $next !== null) {
            $this->write($nextId, $this->withFuel($picture['next'], $next['start'], $today));
        }
        $this->syncAmounts($today);
    }

    /**
     * Bills that make up the fill for one span.
     *
     * @return array{bills: array<int, list<array{id:int,name:string,amountMinor:int}>>, leftOut: list<string>}
     */
    public function explain(string $today, string $start, ?string $end, bool $overdue): array
    {
        $picture = $this->tally($this->day($today), $start, $end, $overdue);

        return ['bills' => $picture['bills'], 'leftOut' => $picture['leftOut']];
    }

    /**
     * @return array{open: array<int, int>, next: array<int, int>}
     */
    private function picture(string $today, bool $withNext): array
    {
        $home = $this->home();
        if ($home === null) {
            return ['open' => [], 'next' => []];
        }
        $open = $this->openSpan($home, $today);
        $openTally = $this->tally($today, $open['start'], $open['end'], true);
        $nextFilled = [];
        if ($withNext) {
            $next = $this->nextSpan($home, $today);
            if ($next !== null) {
                $nextTally = $this->tally($today, $next['start'], $next['end'], false);
                $nextFilled = $nextTally['filled'];
            }
        }

        return ['open' => $openTally['filled'], 'next' => $nextFilled];
    }

    /**
     * @return array{filled: array<int, int>, bills: array<int, list<array{id:int,name:string,amountMinor:int}>>, leftOut: list<string>}
     */
    private function tally(string $today, string $start, ?string $end, bool $overdue): array
    {
        unset($today);
        $filled = [];
        $bills = [];
        foreach ($this->bills() as $bill) {
            if ((string) $bill['status'] !== 'active') {
                continue;
            }
            $count = $this->count($bill, $start, $end, $overdue);
            if ($count < 1) {
                continue;
            }
            $name = $this->label($bill);
            $portion = (int) $bill['amount_minor'] * $count;
            $categoryId = (int) $bill['category_id'];
            $filled[$categoryId] = ($filled[$categoryId] ?? 0) + $portion;
            $bills[$categoryId][] = [
                'id' => (int) $bill['id'],
                'name' => $name,
                'amountMinor' => $portion,
            ];
        }
        return ['filled' => $filled, 'bills' => $bills, 'leftOut' => []];
    }

    /** @param array<string, mixed> $bill */
    private function count(array $bill, string $start, ?string $end, bool $overdue): int
    {
        $next = (string) $bill['next_due_date'];
        $bound = $end ?? Dates::parse($start)->modify('+366 days')->format('Y-m-d');
        $count = 0;
        if ($overdue && $next < $start) {
            $count = 1;
        }
        $recurrence = (string) $bill['recurrence'];
        if ($recurrence === 'once') {
            if ($next >= $start && $next <= $bound) {
                $count++;
            }

            return $count;
        }
        $cursor = $next;
        $anchor = (string) $bill['anchor_date'];
        for ($guard = 0; $guard < 400 && $cursor <= $bound; $guard++) {
            if ($cursor >= $start) {
                $count++;
            }
            $advanced = Schedule::advance($recurrence, $anchor, $cursor, max(1, (int) ($bill['every_count'] ?? 1)));
            if ($advanced <= $cursor) {
                break;
            }
            $cursor = $advanced;
        }

        return $count;
    }

    /** @param array<string, mixed> $bill */
    private function label(array $bill): string
    {
        $type = (string) $bill['type'];
        $other = $bill['other_label'] ?? null;
        if (($type === 'other' || $type === 'misc') && is_string($other) && $other !== '') {
            return $other;
        }

        return (string) $bill['name'];
    }

    /** @return list<array<string, mixed>> */
    private function bills(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.name, b.type, b.other_label, b.category_id, b.amount_minor, b.currency,
                    b.recurrence, b.every_count, b.anchor_date, b.next_due_date, b.status
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.category_id IS NOT NULL'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Trip fuel joins the fuel bucket's auto-assigned amount.
     * The bill list stays bills only; the screen adds the same stays beside them.
     *
     * @param array<int, int> $filled
     * @return array<int, int>
     */
    private function withFuel(array $filled, string $day, string $today): array
    {
        $month = Dates::parse($day);
        $fuelId = SystemBuckets::idForType($this->pdo, $this->actor->id(), 'fuel');
        $trips = new TripService($this->pdo, $this->actor, $this->currencies, $this->drive ?? new Drive(false));
        foreach ($trips->fuelEstimates($month->format('Y-m-01'), $month->format('Y-m-t'), $today) as $stay) {
            $filled[$fuelId] = ($filled[$fuelId] ?? 0) + $stay['costMinor'];
        }

        return $filled;
    }

    /** @param array<int, int> $filled */
    private function write(int $periodId, array $filled): void
    {
        $existing = $this->pdo->prepare(
            'SELECT category_id, amount_minor, filled_minor FROM assignments WHERE pay_period_id = ?'
        );
        $existing->execute([$periodId]);
        $rows = [];
        while ($row = $existing->fetch()) {
            if (is_array($row)) {
                $rows[(int) $row['category_id']] = $row;
            }
        }
        $categories = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ?');
        $categories->execute([$this->actor->id()]);
        $ids = $categories->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($ids)) {
            return;
        }
        $delete = $this->pdo->prepare('DELETE FROM assignments WHERE pay_period_id = ? AND category_id = ?');
        $upsert = $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(pay_period_id, category_id) DO UPDATE SET
                amount_minor = excluded.amount_minor,
                filled_minor = excluded.filled_minor'
        );
        foreach ($ids as $id) {
            $categoryId = (int) $id;
            $portion = $filled[$categoryId] ?? 0;
            $row = $rows[$categoryId] ?? null;
            $oldAmount = $row === null ? 0 : (int) $row['amount_minor'];
            $oldFilled = $row === null ? 0 : (int) $row['filled_minor'];
            $envelope = max(0, $oldAmount - $oldFilled);
            $increase = max(0, $portion - $oldFilled);
            $envelope = max(0, $envelope - $increase);
            $amount = $portion + $envelope;
            if ($amount === 0 && $portion === 0) {
                if ($row !== null) {
                    $delete->execute([$periodId, $categoryId]);
                }
                continue;
            }
            $upsert->execute([$periodId, $categoryId, $amount, $portion]);
        }
    }

    /** @return ?array<string, mixed> */
    private function home(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pay_cycles WHERE user_id = ? AND archived_at IS NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute([$this->actor->id()]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $cycle
     * @return array{start:string,end:?string,next:?string}
     */
    private function openSpan(array $cycle, string $today): array
    {
        $length = (string) $cycle['length'];
        $second = $cycle['second_day'] === null ? null : (int) $cycle['second_day'];
        $nextPayday = $cycle['next_payday'] === null ? null : (string) $cycle['next_payday'];
        $cursor = (string) $cycle['payday'];
        $last = null;
        for ($i = 0; $i < 2400; $i++) {
            $open = Period::open($cursor, $length, $second, $length === 'irregular' ? $nextPayday : null, $cursor);
            $last = [
                'start' => $open['start'],
                'end' => $this->dayBefore($open['next']),
                'next' => $open['next'],
            ];
            if ($open['next'] === null || $today < $open['next']) {
                break;
            }
            $cursor = $open['next'];
            $nextPayday = null;
        }

        return $last ?? ['start' => $cursor, 'end' => null, 'next' => null];
    }

    /**
     * @param array<string, mixed> $cycle
     * @return ?array{start:string,end:?string}
     */
    private function nextSpan(array $cycle, string $today): ?array
    {
        $open = $this->openSpan($cycle, $today);
        if ($open['next'] === null) {
            return null;
        }
        $following = Period::open(
            $open['next'],
            (string) $cycle['length'],
            $cycle['second_day'] === null ? null : (int) $cycle['second_day'],
            null,
            $open['next'],
        );

        return [
            'start' => $following['start'],
            'end' => $this->dayBefore($following['next']),
        ];
    }

    /** @param array<string, mixed> $cycle */
    private function storePeriod(array $cycle, string $start, ?string $end): int
    {
        $existing = $this->pdo->prepare(
            'SELECT id FROM pay_periods WHERE pay_cycle_id = ? AND period_start = ?'
        );
        $existing->execute([(int) $cycle['id'], $start]);
        $found = $existing->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO pay_periods (user_id, pay_cycle_id, period_start, period_end, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->actor->id(), $cycle['id'], $start, $end, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function syncAmounts(string $today): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pay_cycles WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$this->actor->id()]);
        $cycles = $stmt->fetchAll();
        if (!is_array($cycles)) {
            return;
        }
        $sums = [];
        foreach ($cycles as $cycle) {
            if (!is_array($cycle) || $cycle['archived_at'] !== null) {
                continue;
            }
            $open = $this->openSpan($cycle, $today);
            $rows = $this->pdo->prepare(
                'SELECT a.category_id, a.amount_minor
                 FROM assignments a
                 JOIN pay_periods p ON p.id = a.pay_period_id
                 WHERE p.pay_cycle_id = ? AND p.period_start = ?'
            );
            $rows->execute([(int) $cycle['id'], $open['start']]);
            while ($row = $rows->fetch()) {
                if (!is_array($row)) {
                    break;
                }
                $id = (int) $row['category_id'];
                $sums[$id] = ($sums[$id] ?? 0) + max(0, (int) $row['amount_minor']);
            }
        }
        $current = $this->pdo->prepare('SELECT id, amount_minor FROM categories WHERE user_id = ?');
        $current->execute([$this->actor->id()]);
        $update = $this->pdo->prepare(
            'UPDATE categories SET amount_minor = ? WHERE id = ? AND user_id = ? AND amount_minor != ?'
        );
        while ($row = $current->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $id = (int) $row['id'];
            $next = $sums[$id] ?? 0;
            if ((int) $row['amount_minor'] === $next) {
                continue;
            }
            $update->execute([$next, $id, $this->actor->id(), $next]);
        }
    }

    private function day(string $today): string
    {
        try {
            return Dates::parse($today)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return gmdate('Y-m-d');
        }
    }

    private function dayBefore(?string $next): ?string
    {
        if ($next === null) {
            return null;
        }

        return Dates::parse($next)->modify('-1 day')->format('Y-m-d');
    }
}
