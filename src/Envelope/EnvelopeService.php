<?php

declare(strict_types=1);

namespace App\Envelope;

use App\Auth\Actor;
use App\Envelope\BillFill;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\Period;
use App\Support\Dates;
use App\Target\TargetService;
use PDO;
use Throwable;

final class EnvelopeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly CurrencyCatalog $currencies,
        private readonly TargetService $targets,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(string $today, ?int $payCycleId = null, ?int $periodId = null, bool $upcoming = false): array
    {
        $today = $this->day($today);
        $this->fillIfWaiting($today);
        $this->prepare($today);

        return $this->present($today, $payCycleId, $periodId, $upcoming, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $today = $this->day(is_string($input['today'] ?? null) ? $input['today'] : '');
        $this->prepare($today);
        $cycle = $this->cycle($this->asInt($input['payCycleId'] ?? null) ?? 0);
        if ($cycle['archived_at'] !== null) {
            throw new ApiException(409, 'Unarchive this pay cycle before assigning.');
        }

        $upcoming = ($input['upcoming'] ?? false) === true || ($input['upcoming'] ?? null) === 1 || ($input['upcoming'] ?? null) === '1';
        if ($upcoming) {
            $next = $this->nextSpan($cycle, $today);
            if ($next === null) {
                throw new ApiException(422, 'This pay cycle has no next period to plan.');
            }
            $periodId = $this->storePeriod($cycle, $next['start'], $next['end']);
            $period = ['id' => $periodId];
        } else {
            $open = $this->openSpan($cycle, $today);
            $period = $this->periodRow((int) $cycle['id'], $open['start']);
            if ($period === null) {
                throw new ApiException(404, 'Pay period not found.');
            }
        }

        $amounts = $this->amounts(is_array($input['amounts'] ?? null) ? $input['amounts'] : null);
        $maximums = array_key_exists('maximums', $input)
            ? $this->maximums(is_array($input['maximums']) ? $input['maximums'] : null)
            : null;
        $this->pdo->beginTransaction();
        try {
            $kept = $this->pdo->prepare('SELECT category_id, amount_minor, filled_minor FROM assignments WHERE pay_period_id = ?');
            $kept->execute([(int) $period['id']]);
            $prior = [];
            while ($row = $kept->fetch()) {
                if (is_array($row)) {
                    $prior[(int) $row['category_id']] = [
                        'amount' => (int) $row['amount_minor'],
                        'filled' => (int) $row['filled_minor'],
                    ];
                }
            }
            $lockedIds = [];
            $locked = $this->pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND role = 'locked'");
            $locked->execute([$this->actor->id()]);
            while ($row = $locked->fetch()) {
                if (is_array($row)) {
                    $lockedIds[(int) $row['id']] = true;
                }
            }
            $this->pdo->prepare('DELETE FROM assignments WHERE pay_period_id = ?')->execute([(int) $period['id']]);
            $insert = $this->pdo->prepare(
                'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor) VALUES (?, ?, ?, ?)'
            );
            $ids = array_unique([...array_keys($amounts), ...array_keys($prior)]);
            foreach ($ids as $categoryId) {
                $keptRow = $prior[$categoryId] ?? ['amount' => 0, 'filled' => 0];
                $amount = isset($lockedIds[$categoryId])
                    ? max(0, $keptRow['amount'])
                    : ($amounts[$categoryId] ?? 0);
                $portion = $keptRow['filled'];
                if ($amount === 0 && $portion === 0) {
                    continue;
                }
                $insert->execute([(int) $period['id'], $categoryId, $amount, $portion]);
            }
            $this->syncAmounts($today);
            if ($maximums !== null) {
                $this->saveMaximums($maximums);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->present($today, (int) $cycle['id'], null, $upcoming, true);
    }

    private function prepare(string $today): void
    {
        foreach ($this->cycles() as $cycle) {
            $rolled = $this->roll($cycle, $today);
            foreach ($this->spans($rolled, $today) as $span) {
                $this->storePeriod($rolled, $span['start'], $span['end']);
            }
        }
        $this->adoptExistingAmounts($today);
        $this->syncAmounts($today);
    }

    /**
     * A category amount typed before envelopes existed becomes the assignment
     * on the first open period, once, when no assignment has been stored yet.
     */
    private function adoptExistingAmounts(string $today): void
    {
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.user_id = ?'
        );
        $count->execute([$this->actor->id()]);
        if ((int) $count->fetchColumn() > 0) {
            return;
        }

        $cycles = array_values(array_filter(
            $this->cycles(),
            static fn (array $cycle): bool => $cycle['archived_at'] === null,
        ));
        if ($cycles === []) {
            return;
        }

        $open = $this->openSpan($cycles[0], $today);
        $period = $this->periodRow((int) $cycles[0]['id'], $open['start']);
        if ($period === null) {
            return;
        }

        $categories = $this->pdo->prepare(
            'SELECT id, amount_minor FROM categories
             WHERE user_id = ? AND archived_at IS NULL AND amount_minor > 0'
        );
        $categories->execute([$this->actor->id()]);
        $rows = $categories->fetchAll();
        if (!is_array($rows)) {
            return;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor) VALUES (?, ?, ?)'
        );
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $insert->execute([(int) $period['id'], (int) $row['id'], (int) $row['amount_minor']]);
        }
    }

    /**
     * @param array<string, mixed> $cycle
     * @return array<string, mixed>
     */
    private function roll(array $cycle, string $today): array
    {
        if ($cycle['length'] !== 'irregular' || $cycle['next_payday'] === null) {
            return $cycle;
        }

        $open = Period::open(
            (string) $cycle['payday'],
            'irregular',
            null,
            (string) $cycle['next_payday'],
            $today,
        );
        if (!$open['rolled']) {
            return $cycle;
        }

        $closing = Period::open(
            (string) $cycle['payday'],
            'irregular',
            null,
            (string) $cycle['next_payday'],
            (string) $cycle['payday'],
        );
        $this->storePeriod($cycle, $closing['start'], $this->dayBefore($closing['next']));
        $this->pdo->prepare(
            'UPDATE pay_cycles SET payday = ?, next_payday = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$open['start'], gmdate('c'), $cycle['id'], $this->actor->id()]);

        return $this->cycle((int) $cycle['id']);
    }

    /**
     * @param array<string, mixed> $cycle
     * @return list<array{start:string,end:?string,next:?string}>
     */
    private function spans(array $cycle, string $today): array
    {
        $length = (string) $cycle['length'];
        $second = $cycle['second_day'] === null ? null : (int) $cycle['second_day'];
        $nextPayday = $cycle['next_payday'] === null ? null : (string) $cycle['next_payday'];
        $cursor = (string) $cycle['payday'];
        $spans = [];

        for ($i = 0; $i < 2400; $i++) {
            $open = Period::open($cursor, $length, $second, $length === 'irregular' ? $nextPayday : null, $cursor);
            $spans[] = [
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

        return $spans;
    }

    /**
     * @param array<string, mixed> $cycle
     * @return array{start:string,end:?string,next:?string}
     */
    private function openSpan(array $cycle, string $today): array
    {
        $spans = $this->spans($cycle, $today);
        $last = $spans[array_key_last($spans)];

        return $last;
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
        $existing = $this->periodRow((int) $cycle['id'], $start);
        if ($existing !== null) {
            $stored = $existing['period_end'] === null ? null : (string) $existing['period_end'];
            if ($stored !== $end) {
                $this->pdo->prepare('UPDATE pay_periods SET period_end = ? WHERE id = ?')
                    ->execute([$end, $existing['id']]);
            }

            return (int) $existing['id'];
        }

        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO pay_periods (user_id, pay_cycle_id, period_start, period_end, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->actor->id(), $cycle['id'], $start, $end, $now]);
        $id = (int) $this->pdo->lastInsertId();

        $previous = $this->pdo->prepare(
            'SELECT id FROM pay_periods
             WHERE pay_cycle_id = ? AND period_start < ?
             ORDER BY period_start DESC LIMIT 1'
        );
        $previous->execute([$cycle['id'], $start]);
        $previousId = $previous->fetchColumn();
        if ($previousId !== false) {
            $this->pdo->prepare(
                'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor)
                 SELECT ?, a.category_id, a.amount_minor, a.filled_minor
                 FROM assignments a
                 WHERE a.pay_period_id = ?
                   AND a.amount_minor > 0'
            )->execute([$id, $previousId]);
        }

        return $id;
    }

    private function syncAmounts(string $today): void
    {
        if ($this->cycles() === []) {
            return;
        }

        $sums = [];
        foreach ($this->cycles() as $cycle) {
            $open = $this->openSpan($cycle, $today);
            $stmt = $this->pdo->prepare(
                'SELECT a.category_id, a.amount_minor
                 FROM assignments a
                 JOIN pay_periods p ON p.id = a.pay_period_id
                 WHERE p.pay_cycle_id = ? AND p.period_start = ?'
            );
            $stmt->execute([$cycle['id'], $open['start']]);
            $rows = $stmt->fetchAll();
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) $row['category_id'];
                $sums[$id] = ($sums[$id] ?? 0) + max(0, (int) $row['amount_minor']);
            }
        }

        $current = $this->pdo->prepare('SELECT id, amount_minor FROM categories WHERE user_id = ?');
        $current->execute([$this->actor->id()]);
        $rows = $current->fetchAll();
        $update = $this->pdo->prepare(
            'UPDATE categories SET amount_minor = ?, updated_at = ? WHERE id = ? AND user_id = ?'
        );
        $now = gmdate('c');
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) $row['id'];
            $next = $sums[$id] ?? 0;
            if ((int) $row['amount_minor'] === $next) {
                continue;
            }
            $update->execute([$next, $now, $id, $this->actor->id()]);
        }
    }

    /** @return array<string, mixed> */
    private function present(string $today, ?int $payCycleId, ?int $periodId, bool $upcoming, bool $observe): array
    {
        $base = $this->actor->baseCurrency();
        $pool = $this->pool();
        $cycles = array_values(array_filter(
            $this->cycles(),
            static fn (array $cycle): bool => $cycle['archived_at'] === null,
        ));
        $selected = $this->selectCycle($cycles, $payCycleId, $today);
        $assigned = $this->assignedOpen($today, $selected);
        $available = $pool['minor'] - $assigned;

        $period = null;
        $categories = [];
        $closed = [];
        $next = null;
        $previousPeriod = null;
        $stepNext = null;
        $celebrate = false;

        if ($selected !== null) {
            $open = $this->openSpan($selected, $today);
            $openRow = $this->periodRow((int) $selected['id'], $open['start']);
            $closed = $this->closedPeriods((int) $selected['id'], $open['start']);
            $next = $this->nextSpan($selected, $today);

            $viewUpcoming = $upcoming && $next !== null;
            if ($viewUpcoming) {
                $plan = $this->periodRow((int) $selected['id'], $next['start']);
                $period = [
                    'id' => $plan === null ? null : (int) $plan['id'],
                    'start' => $next['start'],
                    'end' => $next['end'],
                    'editable' => true,
                    'upcoming' => true,
                ];
                $categories = $plan === null
                    ? $this->previewCategories($openRow === null ? null : (int) $openRow['id'])
                    : $this->categories((int) $plan['id']);
                $previousPeriod = $openRow === null ? null : $this->cursor($openRow, false);
            } else {
                $view = $openRow;
                if ($periodId !== null) {
                    $requested = $this->periodById((int) $selected['id'], $periodId);
                    if ($requested === null) {
                        throw new ApiException(404, 'Pay period not found.');
                    }
                    $view = $requested;
                }
                if ($view !== null) {
                    $editable = (string) $view['period_start'] === $open['start'];
                    if ($observe && $editable && $available === 0 && $view['celebrated_at'] === null) {
                        $this->pdo->prepare('UPDATE pay_periods SET celebrated_at = ? WHERE id = ?')
                            ->execute([gmdate('c'), $view['id']]);
                        $view['celebrated_at'] = gmdate('c');
                        $celebrate = true;
                    }
                    $period = [
                        'id' => (int) $view['id'],
                        'start' => (string) $view['period_start'],
                        'end' => $view['period_end'] === null ? null : (string) $view['period_end'],
                        'editable' => $editable,
                        'upcoming' => false,
                    ];
                    $categories = $this->categories((int) $view['id']);
                    if ($editable) {
                        $previousPeriod = isset($closed[0]) ? $this->cursorList($closed[0]) : null;
                        $stepNext = $next === null ? null : [
                            'id' => null,
                            'start' => $next['start'],
                            'end' => $next['end'],
                            'upcoming' => true,
                        ];
                    } else {
                        $index = 0;
                        foreach ($closed as $i => $item) {
                            if ((int) $item['id'] === (int) $view['id']) {
                                $index = $i;
                                break;
                            }
                        }
                        $previousPeriod = isset($closed[$index + 1]) ? $this->cursorList($closed[$index + 1]) : null;
                        $stepNext = $index === 0
                            ? ($openRow === null ? null : $this->cursor($openRow, false))
                            : $this->cursorList($closed[$index - 1]);
                    }
                }
            }
        }

        return [
            'baseCurrency' => $base,
            'exponent' => $this->currencies->exponent($base),
            'payCycleId' => $selected === null ? null : (int) $selected['id'],
            'payors' => array_map(static fn (array $cycle): array => [
                'id' => (int) $cycle['id'],
                'payor' => (string) $cycle['payor'],
                'length' => (string) $cycle['length'],
            ], $cycles),
            'period' => $period,
            'nextPeriod' => $next,
            'previousPeriod' => $previousPeriod,
            'stepNext' => $stepNext,
            'closed' => $closed,
            'availableMinor' => $available,
            'assignedMinor' => $assigned,
            'poolMinor' => $pool['minor'],
            'leftOut' => $pool['leftOut'],
            'categories' => $this->withBills(
                $this->targets->decorate(
                    array_map(static function (array $category): array {
                        $category['remainingMinor'] = (int) ($category['amountMinor'] ?? 0);

                        return $category;
                    }, $categories),
                    $today,
                    $observe,
                ),
                is_array($period) ? $period : null,
                $today,
            ),
            'billLeftOut' => $this->billLeftOut(is_array($period) ? $period : null, $today),
            'groups' => $this->groups($categories),
            'celebrate' => $celebrate,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cycles
     * @return ?array<string, mixed>
     */
    private function selectCycle(array $cycles, ?int $requested, string $today): ?array
    {
        if ($cycles === []) {
            return null;
        }
        if ($requested !== null) {
            foreach ($cycles as $cycle) {
                if ((int) $cycle['id'] === $requested) {
                    return $cycle;
                }
            }
            throw new ApiException(404, 'Pay cycle not found.');
        }

        $best = null;
        $bestAmount = 0;
        foreach ($cycles as $cycle) {
            $open = $this->openSpan($cycle, $today);
            $amount = $this->periodTotal((int) $cycle['id'], $open['start']);
            if ($amount > $bestAmount) {
                $best = $cycle;
                $bestAmount = $amount;
            }
        }

        return $best ?? $cycles[0];
    }

    /** @return array{minor:int,leftOut:list<empty>} */
    private function pool(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT balance_minor FROM accounts
             WHERE user_id = ? AND archived_at IS NULL AND type IN (\'checking\', \'savings\', \'cash\')'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        $minor = 0;
        if (!is_array($rows)) {
            return ['minor' => 0, 'leftOut' => []];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $minor += (int) $row['balance_minor'];
        }

        return ['minor' => $minor, 'leftOut' => []];
    }

    private function periodTotal(int $cycleId, string $start): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.amount_minor), 0)
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.pay_cycle_id = ? AND p.period_start = ?'
        );
        $stmt->execute([$cycleId, $start]);

        return (int) $stmt->fetchColumn();
    }

    /** @param ?array<string, mixed> $billCycle */
    private function assignedOpen(string $today, ?array $billCycle): int
    {
        $total = 0;
        foreach ($this->cycles() as $cycle) {
            $open = $this->openSpan($cycle, $today);
            $stmt = $this->pdo->prepare(
                'SELECT a.amount_minor
                 FROM assignments a
                 JOIN pay_periods p ON p.id = a.pay_period_id
                 JOIN categories c ON c.id = a.category_id
                 WHERE p.pay_cycle_id = ? AND p.period_start = ? AND c.role != \'locked\' AND a.amount_minor > 0'
            );
            $stmt->execute([(int) $cycle['id'], $open['start']]);
            $rows = $stmt->fetchAll();
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $total += (int) $row['amount_minor'];
            }
        }
        if ($billCycle !== null) {
            $total += $this->lockedBills($billCycle, $today);
        }

        return $total;
    }

    /** @param array<string, mixed> $cycle */
    private function lockedBills(array $cycle, string $today): int
    {
        $open = $this->openSpan($cycle, $today);
        $end = $open['end'] ?? null;
        $picture = (new BillFill($this->pdo, $this->currencies, $this->actor))->explain(
            $today,
            $open['start'],
            is_string($end) ? $end : null,
            true,
        );
        $locked = [];
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND role = \'locked\'');
        $stmt->execute([$this->actor->id()]);
        while ($id = $stmt->fetchColumn()) {
            $locked[(int) $id] = true;
        }
        $total = 0;
        foreach ($picture['bills'] as $categoryId => $bills) {
            if (!isset($locked[(int) $categoryId])) {
                continue;
            }
            foreach ($bills as $bill) {
                $total += (int) $bill['amountMinor'];
            }
        }

        return $total;
    }

    /** @return list<array<string, mixed>> */
    private function previewCategories(?int $sourcePeriodId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.category_group, c.role, c.archived_at,
                    CASE WHEN a.amount_minor > 0 THEN a.amount_minor ELSE 0 END AS amount_minor,
                    COALESCE(a.filled_minor, 0) AS filled_minor
             FROM categories c
             LEFT JOIN assignments a ON a.category_id = c.id AND a.pay_period_id = ?
             WHERE c.user_id = ? AND (c.archived_at IS NULL OR a.id IS NOT NULL)
             ORDER BY CASE c.category_group WHEN \'needs\' THEN 0 WHEN \'wants\' THEN 1 ELSE 2 END,
                      c.name COLLATE NOCASE, c.id'
        );
        $stmt->execute([$sourcePeriodId ?? 0, $this->actor->id()]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $listed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $listed[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'group' => (string) $row['category_group'],
                'role' => (string) ($row['role'] ?? 'goal'),
                'amountMinor' => (int) $row['amount_minor'],
                'filledMinor' => (int) $row['filled_minor'],
                'archivedAt' => $row['archived_at'] === null ? null : (string) $row['archived_at'],
            ];
        }

        return $listed;
    }

    /**
     * Goal amounts in the period are the denominator. Needs is 50%, Wants 30%, Savings 20%.
     * A locked bucket's bills stay on the tile and in available-to-assign.
     *
     * @param list<array<string, mixed>> $categories
     * @return list<array{group:string,targetPercent:int,amountMinor:int,shareBasisPoints:int,over:bool}>
     */
    private function groups(array $categories): array
    {
        $targets = ['needs' => 50, 'wants' => 30, 'savings' => 20];
        $amounts = ['needs' => 0, 'wants' => 0, 'savings' => 0];
        foreach ($categories as $category) {
            if (($category['role'] ?? '') === 'locked') {
                continue;
            }
            $group = $category['group'] ?? '';
            if (!is_string($group) || !isset($amounts[$group])) {
                continue;
            }
            $amount = (int) ($category['amountMinor'] ?? 0);
            if ($amount > 0) {
                $amounts[$group] += $amount;
            }
        }
        $total = $amounts['needs'] + $amounts['wants'] + $amounts['savings'];
        $listed = [];
        foreach ($targets as $group => $target) {
            $amount = $amounts[$group];
            $listed[] = [
                'group' => $group,
                'targetPercent' => $target,
                'amountMinor' => $amount,
                'shareBasisPoints' => $total === 0 ? 0 : intdiv($amount * 10000 + intdiv($total, 2), $total),
                'over' => $total > 0 && $amount * 100 > $target * $total,
            ];
        }

        return $listed;
    }

    /** @return list<array<string, mixed>> */
    private function categories(int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.category_group, c.role, c.archived_at,
                    CASE WHEN a.amount_minor > 0 THEN a.amount_minor ELSE 0 END AS amount_minor,
                    COALESCE(a.filled_minor, 0) AS filled_minor
             FROM categories c
             LEFT JOIN assignments a ON a.category_id = c.id AND a.pay_period_id = ?
             WHERE c.user_id = ? AND (c.archived_at IS NULL OR a.id IS NOT NULL)
             ORDER BY CASE c.category_group WHEN \'needs\' THEN 0 WHEN \'wants\' THEN 1 ELSE 2 END,
                      c.name COLLATE NOCASE, c.id'
        );
        $stmt->execute([$periodId, $this->actor->id()]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $listed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $listed[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'group' => (string) $row['category_group'],
                'role' => (string) ($row['role'] ?? 'goal'),
                'amountMinor' => (int) $row['amount_minor'],
                'filledMinor' => (int) $row['filled_minor'],
                'archivedAt' => $row['archived_at'] === null ? null : (string) $row['archived_at'],
            ];
        }

        return $listed;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:?int,start:string,end:?string,upcoming:bool}
     */
    private function cursor(array $row, bool $upcoming): array
    {
        return [
            'id' => $upcoming ? null : (int) $row['id'],
            'start' => (string) $row['period_start'],
            'end' => $row['period_end'] === null ? null : (string) $row['period_end'],
            'upcoming' => $upcoming,
        ];
    }

    /**
     * @param array{id:int,start:string,end:?string} $item
     * @return array{id:?int,start:string,end:?string,upcoming:bool}
     */
    private function cursorList(array $item): array
    {
        return [
            'id' => $item['id'],
            'start' => $item['start'],
            'end' => $item['end'],
            'upcoming' => false,
        ];
    }

    /** @return list<array{id:int,start:string,end:?string}> */
    private function closedPeriods(int $cycleId, string $openStart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, period_start, period_end FROM pay_periods
             WHERE pay_cycle_id = ? AND period_start < ?
             ORDER BY period_start DESC'
        );
        $stmt->execute([$cycleId, $openStart]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $listed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $listed[] = [
                'id' => (int) $row['id'],
                'start' => (string) $row['period_start'],
                'end' => $row['period_end'] === null ? null : (string) $row['period_end'],
            ];
        }

        return $listed;
    }

    /**
     * @param ?list<mixed> $rows
     * @return array<int, int>
     */
    private function amounts(?array $rows): array
    {
        if ($rows === null) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'amounts' => 'Enter an amount for each bucket.',
            ]);
        }

        $amounts = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors['amounts'] = 'Enter an amount for each bucket.';
                continue;
            }
            $categoryId = $this->asInt($row['categoryId'] ?? null) ?? 0;
            $amount = $this->asInt($row['amountMinor'] ?? null);
            $key = 'amount-' . ($categoryId > 0 ? (string) $categoryId : (string) $index);
            if ($categoryId < 1 || !$this->ownsCategory($categoryId)) {
                $errors[$key] = 'Choose a bucket.';
                continue;
            }
            if (isset($amounts[$categoryId])) {
                $errors[$key] = 'Each bucket is listed once.';
                continue;
            }
            if ($amount === null || $amount < 0) {
                $errors[$key] = 'Enter an amount of zero or more.';
                continue;
            }
            $amounts[$categoryId] = $amount;
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return $amounts;
    }

    /**
     * A blank maximum clears a balance target. A dated target keeps its date and only the amount changes.
     *
     * @param list<mixed>|null $rows
     * @return array<int, ?int>
     */
    private function maximums(?array $rows): array
    {
        if ($rows === null) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'maximums' => 'Enter a maximum for each bucket.',
            ]);
        }

        $maximums = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors['maximums'] = 'Enter a maximum for each bucket.';
                continue;
            }
            $categoryId = $this->asInt($row['categoryId'] ?? null) ?? 0;
            $key = 'maximum-' . ($categoryId > 0 ? (string) $categoryId : (string) $index);
            if ($categoryId < 1 || !$this->ownsCategory($categoryId)) {
                $errors[$key] = 'Choose a bucket.';
                continue;
            }
            if (array_key_exists($categoryId, $maximums)) {
                $errors[$key] = 'Each bucket is listed once.';
                continue;
            }
            if (!array_key_exists('amountMinor', $row) || $row['amountMinor'] === null) {
                $maximums[$categoryId] = null;
                continue;
            }
            $amount = $this->asInt($row['amountMinor']);
            if ($amount === null || $amount < 0) {
                $errors[$key] = 'Enter an amount.';
                continue;
            }
            $maximums[$categoryId] = $amount;
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return $maximums;
    }

    /** @param array<int, ?int> $maximums */
    private function saveMaximums(array $maximums): void
    {
        if ($maximums === []) {
            return;
        }
        $role = $this->pdo->prepare('SELECT role FROM categories WHERE id = ? AND user_id = ?');
        $existing = $this->pdo->prepare(
            'SELECT kind, target_date, pay_cycle_id FROM targets WHERE category_id = ? AND user_id = ?'
        );
        foreach ($maximums as $categoryId => $amount) {
            $role->execute([$categoryId, $this->actor->id()]);
            $roleName = (string) $role->fetchColumn();
            if ($roleName !== 'goal' && $roleName !== 'locked') {
                continue;
            }
            $existing->execute([$categoryId, $this->actor->id()]);
            $previous = $existing->fetch();
            if ($amount === null || $amount < 1) {
                if (is_array($previous) && (string) $previous['kind'] === 'balance') {
                    $this->targets->replace($categoryId, null);
                }
                continue;
            }
            if (is_array($previous) && (string) $previous['kind'] === 'needed') {
                $this->targets->replace($categoryId, [
                    'kind' => 'needed',
                    'amountMinor' => $amount,
                    'targetDate' => (string) $previous['target_date'],
                    'payCycleId' => (int) $previous['pay_cycle_id'],
                ]);
                continue;
            }
            $this->targets->replace($categoryId, ['kind' => 'balance', 'amountMinor' => $amount]);
        }
    }

    private function ownsCategory(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM categories WHERE id = ? AND user_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$id, $this->actor->id()]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    private function cycles(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pay_cycles WHERE user_id = ? ORDER BY payor COLLATE NOCASE, id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $listed = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $listed[] = $row;
            }
        }

        return $listed;
    }

    /** @return array<string, mixed> */
    private function cycle(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pay_cycles WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Pay cycle not found.');
        }

        return $row;
    }

    /** @return ?array<string, mixed> */
    private function periodRow(int $cycleId, string $start): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pay_periods WHERE pay_cycle_id = ? AND period_start = ? AND user_id = ?'
        );
        $stmt->execute([$cycleId, $start, $this->actor->id()]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return ?array<string, mixed> */
    private function periodById(int $cycleId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pay_periods WHERE id = ? AND pay_cycle_id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $cycleId, $this->actor->id()]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @param ?array<string, mixed> $period
     * @return list<array<string, mixed>>
     */
    private function withBills(array $categories, ?array $period, string $today): array
    {
        $picture = $this->billPicture($period, $today);
        foreach ($categories as $index => $category) {
            $list = $picture['bills'][(int) $category['id']] ?? [];
            $categories[$index]['bills'] = $list;
            $bills = 0;
            foreach ($list as $bill) {
                $bills += (int) $bill['amountMinor'];
            }
            $categories[$index]['billMinor'] = $bills;
            if (($category['role'] ?? '') === 'locked') {
                $categories[$index]['amountMinor'] = $bills;
                $categories[$index]['remainingMinor'] = $bills;
            }
        }

        return $categories;
    }

    /** @param ?array<string, mixed> $period
     *  @return list<string>
     */
    private function billLeftOut(?array $period, string $today): array
    {
        return $this->billPicture($period, $today)['leftOut'];
    }

    /**
     * @param ?array<string, mixed> $period
     * @return array{bills: array<int, list<array{id:int,name:string,amountMinor:int}>>, leftOut: list<string>}
     */
    private function billPicture(?array $period, string $today): array
    {
        if ($period === null) {
            return ['bills' => [], 'leftOut' => []];
        }
        $end = $period['end'] ?? null;
        $overdue = ($period['editable'] ?? false) === true && ($period['upcoming'] ?? false) !== true;

        return (new BillFill($this->pdo, $this->currencies, $this->actor))->explain(
            $today,
            (string) $period['start'],
            is_string($end) ? $end : null,
            $overdue,
        );
    }

    /** A bill saved before any pay period existed fills on the next load. */
    private function fillIfWaiting(string $today): void
    {
        $periods = $this->pdo->prepare('SELECT COUNT(*) FROM pay_periods WHERE user_id = ?');
        $periods->execute([$this->actor->id()]);
        if ((int) $periods->fetchColumn() > 0) {
            return;
        }
        $cycles = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pay_cycles WHERE user_id = ? AND archived_at IS NULL'
        );
        $cycles->execute([$this->actor->id()]);
        if ((int) $cycles->fetchColumn() === 0) {
            return;
        }
        $bills = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.status = \'active\''
        );
        $bills->execute([$this->actor->id()]);
        if ((int) $bills->fetchColumn() === 0) {
            return;
        }
        (new BillFill($this->pdo, $this->currencies, $this->actor))->apply($today);
    }

    private function day(string $value): string
    {
        try {
            return Dates::parse($value)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            throw new ApiException(422, 'Check the highlighted fields.', ['today' => 'Use a YYYY-MM-DD date.']);
        }
    }

    private function dayBefore(?string $next): ?string
    {
        if ($next === null) {
            return null;
        }

        return Dates::parse($next)->modify('-1 day')->format('Y-m-d');
    }

    private function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
