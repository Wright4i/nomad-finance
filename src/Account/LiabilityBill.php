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

/**
 * The one monthly bill a credit card, loan, or fuel account keeps for autopay.
 * None, a zero minimum, or a zero balance hides it. Paid dates stay on the bill.
 */
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
        $amount = self::amount($choice, $account['minimum_due_minor'], (int) $account['balance_minor']);
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

        $billType = $type === 'fuel' ? 'fuel' : 'debt';
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), $billType);
        $dueDay = (int) $account['due_day'];
        $now = gmdate('c');
        $day = $today ?? gmdate('Y-m-d');
        if ($existing === null) {
            $due = self::upcoming($dueDay, $day, null);
            $this->pdo->prepare(
                'INSERT INTO bills (
                    name, type, account_id, category_id, amount_minor, currency,
                    recurrence, anchor_date, next_due_date, autopay, status, created_at, updated_at,
                    source_account_id
                 ) VALUES (?, ?, ?, ?, ?, \'USD\', \'monthly\', ?, ?, 0, \'active\', ?, ?, ?)'
            )->execute([
                (string) $account['name'],
                $billType,
                $accountId,
                $categoryId,
                $amount,
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
        if ($anchorDay === $dueDay) {
            $anchor = (string) $existing['anchor_date'];
            $next = (string) $existing['next_due_date'];
        } else {
            $paid = $existing['last_paid_on'] === null ? null : (string) $existing['last_paid_on'];
            $next = self::upcoming($dueDay, $day, $paid);
            $anchor = $next;
        }
        $status = (string) $existing['status'] === 'hidden' ? 'active' : (string) $existing['status'];
        if (!in_array($status, ['active', 'hidden'], true)) {
            $status = 'active';
        }
        $this->pdo->prepare(
            'UPDATE bills SET
                name = ?, type = ?, account_id = ?, category_id = ?, amount_minor = ?,
                anchor_date = ?, next_due_date = ?, status = ?, updated_at = ?,
                vehicle_id = NULL, odometer_miles = NULL, price_per_gallon_minor = NULL
             WHERE id = ?'
        )->execute([
            (string) $account['name'],
            $billType,
            $accountId,
            $categoryId,
            $amount,
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

    /**
     * @param array<string, mixed> $account
     */
    public static function amount(string $choice, mixed $minimum, int $balance): ?int
    {
        if ($choice === 'minimum') {
            $due = is_int($minimum) ? $minimum : (is_numeric($minimum) ? (int) $minimum : 0);

            return $due > 0 ? $due : null;
        }
        if ($choice === 'balance') {
            return $balance > 0 ? $balance : null;
        }

        return null;
    }

    public static function upcoming(int $dueDay, string $today, ?string $lastPaid): string
    {
        $month = Dates::parse($today)->modify('first day of this month');
        $anchor = self::clamp($month, $dueDay);
        $candidate = $anchor;
        if ($lastPaid !== null && $candidate <= $lastPaid) {
            $candidate = Schedule::advance('monthly', $anchor, $candidate);
        }
        if ($candidate < $today && ($lastPaid === null || $candidate <= $lastPaid)) {
            $candidate = Schedule::advance('monthly', $anchor, $candidate);
        }
        if ($lastPaid !== null && $candidate <= $lastPaid) {
            $candidate = Schedule::advance('monthly', $anchor, $lastPaid);
        }

        return $candidate;
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
