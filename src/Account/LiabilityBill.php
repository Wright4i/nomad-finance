<?php

declare(strict_types=1);

namespace App\Account;

use App\Auth\Actor;
use App\Bill\Schedule;
use App\Category\SystemBuckets;
use App\Envelope\BillFill;
use App\Money\CurrencyCatalog;
use App\Support\Dates;
use PDO;

final class LiabilityBill
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /** @param array<string, mixed> $account */
    public function sync(array $account, ?string $today = null): void
    {
        $accountId = (int) $account['id'];
        $type = (string) $account['type'];
        if (!in_array($type, ['credit', 'loan', 'fuel'], true) || $account['archived_at'] !== null) {
            $this->hide($accountId);
            $this->refill($today);

            return;
        }

        $choice = (string) ($account['autopay_choice'] ?? 'none');
        $amount = (int) ($account['bank_autopay'] ?? 0) === 1
            ? self::amount($choice, $account['minimum_due_minor'], (int) $account['balance_minor'])
            : null;
        $existing = $this->find($accountId);
        if ($amount === null) {
            if ($existing !== null && (string) $existing['status'] !== 'hidden') {
                $this->pdo->prepare(
                    "UPDATE bills SET status = 'hidden', updated_at = ? WHERE id = ?"
                )->execute([gmdate('c'), (int) $existing['id']]);
            }
            $this->refill($today);

            return;
        }

        $billType = match ($type) {
            'fuel' => 'fuel',
            'loan' => in_array((string) ($account['liability_bill_type'] ?? 'debt'), ['debt', 'vehicle'], true)
                ? (string) $account['liability_bill_type']
                : 'debt',
            default => 'debt',
        };
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), $billType);
        $drawFrom = $this->drawFrom($accountId);
        $dueDay = (int) ($account['due_day'] ?? 1);
        $recurrence = (string) ($account['bill_recurrence'] ?? 'monthly');
        if (!in_array($recurrence, ['daily', 'monthly', 'yearly'], true)) {
            $recurrence = 'monthly';
        }
        $every = max(1, (int) ($account['bill_every'] ?? 1));
        $now = gmdate('c');
        $day = $today ?? gmdate('Y-m-d');
        if ($existing === null) {
            $due = self::nextDue($recurrence, $every, $dueDay, $day, null, null);
            $this->pdo->prepare(
                'INSERT INTO bills (
                    name, type, account_id, category_id, amount_minor, currency,
                    recurrence, every_count, anchor_date, next_due_date, autopay, status, created_at, updated_at,
                    source_account_id
                 ) VALUES (?, ?, ?, ?, ?, \'USD\', ?, ?, ?, ?, 1, \'active\', ?, ?, ?)'
            )->execute([
                (string) $account['name'],
                $billType,
                $drawFrom,
                $categoryId,
                $amount,
                $recurrence,
                $every,
                $due,
                $due,
                $now,
                $now,
                $accountId,
            ]);
            $this->refill($today);

            return;
        }

        $anchorDay = (int) Dates::parse((string) $existing['anchor_date'])->format('j');
        $storedEvery = max(1, (int) ($existing['every_count'] ?? 1));
        $storedRecurrence = (string) $existing['recurrence'];
        $scheduleChanged = $storedRecurrence !== $recurrence
            || $storedEvery !== $every
            || ($recurrence !== 'daily' && $anchorDay !== $dueDay);
        if (!$scheduleChanged) {
            $anchor = (string) $existing['anchor_date'];
            $next = (string) $existing['next_due_date'];
        } else {
            $paid = $existing['last_paid_on'] === null ? null : (string) $existing['last_paid_on'];
            $next = self::nextDue($recurrence, $every, $dueDay, $day, $paid, (string) $existing['anchor_date']);
            $anchor = $next;
        }
        $status = (string) $existing['status'] === 'hidden' ? 'active' : (string) $existing['status'];
        if (!in_array($status, ['active', 'hidden'], true)) {
            $status = 'active';
        }
        $this->pdo->prepare(
            'UPDATE bills SET
                name = ?, type = ?, account_id = ?, category_id = ?, amount_minor = ?,
                recurrence = ?, every_count = ?, autopay = 1,
                anchor_date = ?, next_due_date = ?, status = ?, updated_at = ?,
                vehicle_id = NULL, odometer_miles = NULL, price_per_gallon_minor = NULL
             WHERE id = ?'
        )->execute([
            (string) $account['name'],
            $billType,
            $drawFrom,
            $categoryId,
            $amount,
            $recurrence,
            $every,
            $anchor,
            $next,
            $status === 'hidden' ? 'active' : $status,
            $now,
            (int) $existing['id'],
        ]);
        $this->refill($today);
    }

    public function hide(int $accountId): void
    {
        $this->pdo->prepare(
            "UPDATE bills SET status = 'hidden', updated_at = ?
             WHERE source_account_id = ? AND status != 'hidden'"
        )->execute([gmdate('c'), $accountId]);
    }

    /** @param array<string, mixed> $account */
    public static function amount(string $choice, mixed $minimum, int $balance): ?int
    {
        unset($balance);
        if ($choice !== 'minimum') {
            return null;
        }
        $due = is_int($minimum) ? $minimum : (is_numeric($minimum) ? (int) $minimum : 0);

        return $due > 0 ? $due : null;
    }

    public static function upcoming(int $dueDay, string $today, ?string $lastPaid, int $every = 1): string
    {
        $every = max(1, $every);
        $month = Dates::parse($today)->modify('first day of this month');
        $anchor = self::clamp($month, $dueDay);
        $candidate = $anchor;
        if ($lastPaid !== null && $candidate <= $lastPaid) {
            $candidate = Schedule::advance('monthly', $anchor, $candidate, $every);
        }
        if ($candidate < $today && ($lastPaid === null || $candidate <= $lastPaid)) {
            $candidate = Schedule::advance('monthly', $anchor, $candidate, $every);
        }
        if ($lastPaid !== null && $candidate <= $lastPaid) {
            $candidate = Schedule::advance('monthly', $anchor, $lastPaid, $every);
        }

        return $candidate;
    }

    public static function nextDue(string $recurrence, int $every, int $dueDay, string $today, ?string $lastPaid, ?string $anchor): string
    {
        $every = max(1, $every);
        if ($recurrence === 'daily') {
            if ($lastPaid === null) {
                return $today;
            }
            $cursor = $lastPaid;
            $guard = 0;
            do {
                $cursor = Schedule::advance('daily', $lastPaid, $cursor, $every);
                $guard++;
            } while ($cursor < $today && $guard < 400);

            return $cursor;
        }
        if ($recurrence === 'yearly') {
            $basis = $anchor ?? $today;
            $month = (int) Dates::parse($basis)->format('n');
            $year = (int) Dates::parse($today)->format('Y');
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $candidate = self::clamp($start, max(1, $dueDay));
            $kept = $anchor ?? $candidate;
            if ($lastPaid !== null && $candidate <= $lastPaid) {
                $candidate = Schedule::advance('yearly', $kept, $candidate, $every);
            }
            if ($candidate < $today && ($lastPaid === null || $candidate <= $lastPaid)) {
                $candidate = Schedule::advance('yearly', $kept, $candidate, $every);
            }

            return $candidate;
        }

        return self::upcoming($dueDay, $today, $lastPaid, $every);
    }

    private static function clamp(\DateTimeImmutable $monthStart, int $day): string
    {
        $last = (int) $monthStart->format('t');
        $use = min(max($day, 1), $last);

        return $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            $use,
        )->format('Y-m-d');
    }

    private function drawFrom(int $fallback): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM accounts WHERE user_id = ? AND asset_favorite = 1 AND archived_at IS NULL'
        );
        $stmt->execute([$this->actor->id()]);
        $found = $stmt->fetchColumn();

        return $found === false ? $fallback : (int) $found;
    }

    /** @return ?array<string, mixed> */
    private function find(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bills WHERE source_account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function refill(?string $today): void
    {
        (new BillFill($this->pdo, new CurrencyCatalog(), $this->actor))->apply($today ?? gmdate('Y-m-d'));
    }
}
