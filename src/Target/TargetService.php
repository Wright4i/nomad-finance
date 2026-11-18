<?php

declare(strict_types=1);

namespace App\Target;

use App\Auth\Actor;
use App\Http\ApiException;
use App\PayCycle\Period;
use App\Support\Dates;
use PDO;

final class TargetService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    public function decorate(array $categories, ?string $today, bool $observe): array
    {
        if ($categories === []) {
            return [];
        }
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = (int) $category['id'];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.category_id, t.kind, t.amount_minor AS target_amount, t.target_date,
                    t.pay_cycle_id, t.celebrated_at, c.amount_minor AS held,
                    p.payor, p.payday, p.length, p.second_day, p.next_payday
             FROM targets t
             JOIN categories c ON c.id = t.category_id
             LEFT JOIN pay_cycles p ON p.id = t.pay_cycle_id
             WHERE t.user_id = ? AND t.category_id IN ($marks)"
        );
        $stmt->execute([$this->actor->id(), ...$ids]);
        $byCategory = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $byCategory[(int) $row['category_id']] = $row;
        }
        $day = $this->day($today);
        $listed = [];
        foreach ($categories as $category) {
            $stored = $byCategory[(int) $category['id']] ?? null;
            $category['target'] = is_array($stored) ? $this->figure($stored, $day, $observe) : null;
            $listed[] = $category;
        }

        return $listed;
    }

    public function replace(int $categoryId, mixed $input): void
    {
        $owned = $this->pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ?');
        $owned->execute([$categoryId, $this->actor->id()]);
        if ($owned->fetch() === false) {
            throw new ApiException(404, 'Bucket not found.');
        }
        if ($input === null) {
            $this->pdo->prepare('DELETE FROM targets WHERE category_id = ? AND user_id = ?')
                ->execute([$categoryId, $this->actor->id()]);

            return;
        }
        if (!is_array($input)) {
            throw new ApiException(422, 'Check the highlighted fields.', ['target' => 'Choose a target.']);
        }

        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        if (!in_array($kind, ['needed', 'balance'], true)) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'target' => 'Choose needed by date or a target balance.',
            ]);
        }

        $errors = [];
        $amount = null;
        $date = null;
        $cycleId = null;
        if ($kind === 'needed' || $kind === 'balance') {
            $amount = $this->asInt($input['amountMinor'] ?? null);
            if ($amount === null || $amount < 1) {
                $errors['targetAmount'] = 'Enter an amount.';
            }
        }
        if ($kind === 'needed') {
            try {
                $date = Dates::parse(is_string($input['targetDate'] ?? null) ? $input['targetDate'] : '')->format('Y-m-d');
            } catch (\InvalidArgumentException) {
                $errors['targetDate'] = 'Use a YYYY-MM-DD date.';
            }
            $cycleId = $this->asInt($input['payCycleId'] ?? null);
            if ($cycleId === null) {
                $errors['payCycleId'] = 'Choose a pay cycle.';
            } else {
                $cycle = $this->pdo->prepare(
                    'SELECT archived_at FROM pay_cycles WHERE id = ? AND user_id = ?'
                );
                $cycle->execute([$cycleId, $this->actor->id()]);
                $found = $cycle->fetch();
                if (!is_array($found)) {
                    $errors['payCycleId'] = 'Choose a pay cycle.';
                } elseif ($found['archived_at'] !== null) {
                    throw new ApiException(409, 'Unarchive this pay cycle before using it for a target.');
                }
            }
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        $existing = $this->pdo->prepare(
            'SELECT kind, amount_minor, target_date, pay_cycle_id, celebrated_at
             FROM targets WHERE category_id = ? AND user_id = ?'
        );
        $existing->execute([$categoryId, $this->actor->id()]);
        $previous = $existing->fetch();
        $celebrated = null;
        if (is_array($previous)) {
            $sameKind = (string) $previous['kind'] === $kind;
            $sameAmount = ($previous['amount_minor'] === null ? null : (int) $previous['amount_minor']) === $amount;
            $sameDate = ($previous['target_date'] === null ? null : (string) $previous['target_date']) === $date;
            $sameCycle = ($previous['pay_cycle_id'] === null ? null : (int) $previous['pay_cycle_id']) === $cycleId;
            if ($sameKind && $sameAmount && $sameDate && $sameCycle) {
                $celebrated = $previous['celebrated_at'] === null ? null : (string) $previous['celebrated_at'];
            }
        }

        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO targets (
                user_id, category_id, kind, amount_minor, target_date, pay_cycle_id, celebrated_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(category_id) DO UPDATE SET
                kind = excluded.kind,
                amount_minor = excluded.amount_minor,
                target_date = excluded.target_date,
                pay_cycle_id = excluded.pay_cycle_id,
                celebrated_at = excluded.celebrated_at,
                updated_at = excluded.updated_at'
        )->execute([$this->actor->id(), $categoryId, $kind, $amount, $date, $cycleId, $celebrated, $now, $now]);
    }

    /** @param array<string, mixed> $cycle */
    public function periodsLeft(array $cycle, string $today, string $targetDate): int
    {
        $date = Dates::parse($targetDate)->format('Y-m-d');
        $length = (string) $cycle['length'];
        $second = $cycle['second_day'] === null || $cycle['second_day'] === '' ? null : (int) $cycle['second_day'];
        $nextPayday = $cycle['next_payday'] === null || $cycle['next_payday'] === '' ? null : (string) $cycle['next_payday'];
        $open = Period::open((string) $cycle['payday'], $length, $second, $nextPayday, $today);
        if ($open['start'] > $date) {
            return 0;
        }
        $count = 1;
        $cursor = $open['next'];
        for ($i = 0; $i < 6000 && is_string($cursor); $i++) {
            if ($cursor > $date) {
                return $count;
            }
            $count++;
            if ($length === 'irregular') {
                return $count;
            }
            $span = Period::open($cursor, $length, $second, null, $cursor);
            $cursor = $span['next'];
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function figure(array $row, ?string $today, bool $observe): array
    {
        $kind = (string) $row['kind'];
        $targetAmount = $row['target_amount'] === null ? null : (int) $row['target_amount'];
        $held = (int) $row['held'];
        $date = $row['target_date'] === null ? null : (string) $row['target_date'];
        $gap = $targetAmount === null ? null : max(0, $targetAmount - $held);
        $periods = null;
        $needed = null;
        $passed = false;
        $met = false;

        if ($kind === 'balance') {
            $needed = $gap;
            $met = $gap === 0;
        } elseif ($kind === 'needed') {
            $met = $gap === 0;
            $passed = $today !== null && $date !== null && $today > $date;
            if ($today !== null && $date !== null && $row['payday'] !== null) {
                $periods = $this->periodsLeft($row, $today, $date);
                $needed = $gap === 0 ? 0 : ($periods > 0 ? intdiv($gap + intdiv($periods, 2), $periods) : $gap);
            } else {
                $needed = $gap;
            }
        }

        $celebrate = false;
        $celebratedAt = $row['celebrated_at'] === null ? null : (string) $row['celebrated_at'];
        if ($observe && $kind === 'needed' && $met && !$passed && $celebratedAt === null) {
            $celebratedAt = gmdate('c');
            $this->pdo->prepare('UPDATE targets SET celebrated_at = ? WHERE id = ?')
                ->execute([$celebratedAt, $row['id']]);
            $celebrate = true;
        }

        return [
            'kind' => $kind,
            'amountMinor' => $targetAmount,
            'targetDate' => $date,
            'payCycleId' => $row['pay_cycle_id'] === null ? null : (int) $row['pay_cycle_id'],
            'payor' => $row['payor'] === null ? null : (string) $row['payor'],
            'periodsLeft' => $periods,
            'neededMinor' => $needed,
            'met' => $met,
            'passed' => $passed,
            'celebrate' => $celebrate,
        ];
    }

    private function day(?string $today): ?string
    {
        if ($today === null || trim($today) === '') {
            return null;
        }
        try {
            return Dates::parse($today)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
