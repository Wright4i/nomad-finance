<?php

declare(strict_types=1);

namespace App\Trip;

use App\Auth\Actor;
use App\Category\SystemBuckets;
use App\Envelope\BillFill;
use App\Money\CurrencyCatalog;
use PDO;

/**
 * One deposit bill and one amount-due bill per stay. Mark paid leaves balances typed
 * and lowers the deposit still due on the stay.
 */
final class TripLodgingSync
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly CurrencyCatalog $currencies,
        private readonly ?Drive $drive = null,
    ) {
    }

    public function depositPaidMinor(int $tripId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM bills
             WHERE trip_id = ? AND type = 'lodging' AND lodging_part = 'deposit' AND status = 'paid'"
        );
        $stmt->execute([$tripId]);

        return (int) $stmt->fetchColumn();
    }

    public function dueSettled(int $tripId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT status FROM bills WHERE trip_id = ? AND type = 'lodging' AND lodging_part = 'due' LIMIT 1"
        );
        $stmt->execute([$tripId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            return false;
        }

        return (string) $status === 'paid';
    }

    /**
     * @param array<string, mixed> $data placeName, arriveOn, accountId, totalMinor, amountDueMinor, balanceDueOn
     */
    public function syncAll(int $tripId, array $data, string $today): void
    {
        $this->syncDeposit($tripId, $data);
        $this->syncDue($tripId, $data);
        $this->refill($today);
    }

    public function refreshTrip(int $tripId, string $today): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT place_name, arrive_on, account_id, total_minor, amount_due_minor, balance_due_on
             FROM trips WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$tripId, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }
        $data = [
            'placeName' => (string) $row['place_name'],
            'arriveOn' => (string) $row['arrive_on'],
            'accountId' => $row['account_id'] === null ? null : (int) $row['account_id'],
            'totalMinor' => (int) $row['total_minor'],
            'amountDueMinor' => (int) $row['amount_due_minor'],
            'balanceDueOn' => $row['balance_due_on'] === null || $row['balance_due_on'] === ''
                ? null
                : (string) $row['balance_due_on'],
        ];
        $this->syncAll($tripId, $data, $today);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncDeposit(int $tripId, array $data): void
    {
        $nominal = (int) $data['totalMinor'] - (int) $data['amountDueMinor'];
        $remaining = max(0, $nominal - $this->depositPaidMinor($tripId));
        $accountId = $data['accountId'];
        $accountId = is_int($accountId) ? $accountId : null;
        $stmt = $this->pdo->prepare(
            "SELECT id, status FROM bills WHERE trip_id = ? AND type = 'lodging' AND lodging_part = 'deposit'"
        );
        $stmt->execute([$tripId]);
        $existing = $stmt->fetch();
        $billId = is_array($existing) ? (int) $existing['id'] : null;
        $status = is_array($existing) ? (string) $existing['status'] : '';
        if ($billId !== null && $status === 'paid') {
            return;
        }
        if ($remaining < 1 || $accountId === null || $nominal < 1) {
            if ($billId !== null && in_array($status, ['active', 'paused'], true)) {
                $this->pdo->prepare('DELETE FROM bills WHERE id = ? AND trip_id = ?')->execute([$billId, $tripId]);
            }

            return;
        }
        $dueOn = (string) $data['arriveOn'];
        $currencyStmt = $this->pdo->prepare('SELECT currency FROM accounts WHERE id = ? AND user_id = ?');
        $currencyStmt->execute([$accountId, $this->actor->id()]);
        $currency = $currencyStmt->fetchColumn();
        $currency = is_string($currency) && $currency !== '' ? $currency : 'USD';
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), 'lodging');
        $now = gmdate('c');
        $name = (string) $data['placeName'];
        if ($billId === null) {
            $this->pdo->prepare(
                'INSERT INTO bills (
                    name, type, account_id, category_id, amount_minor, currency,
                    recurrence, anchor_date, next_due_date, autopay, status, created_at, updated_at,
                    trip_id, lodging_part
                 ) VALUES (?, \'lodging\', ?, ?, ?, ?, \'once\', ?, ?, 0, \'active\', ?, ?, ?, \'deposit\')'
            )->execute([$name, $accountId, $categoryId, $remaining, $currency, $dueOn, $dueOn, $now, $now, $tripId]);
        } else {
            $this->pdo->prepare(
                'UPDATE bills SET
                    name = ?, account_id = ?, category_id = ?, amount_minor = ?, currency = ?,
                    anchor_date = ?, next_due_date = ?, updated_at = ?
                 WHERE id = ? AND trip_id = ? AND lodging_part = \'deposit\''
            )->execute([$name, $accountId, $categoryId, $remaining, $currency, $dueOn, $dueOn, $now, $billId, $tripId]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncDue(int $tripId, array $data): void
    {
        $amount = (int) $data['amountDueMinor'];
        $dueOn = is_string($data['balanceDueOn'] ?? null) ? $data['balanceDueOn'] : '';
        $accountId = $data['accountId'];
        $accountId = is_int($accountId) ? $accountId : null;
        $stmt = $this->pdo->prepare(
            "SELECT id, status FROM bills WHERE trip_id = ? AND type = 'lodging' AND lodging_part = 'due'"
        );
        $stmt->execute([$tripId]);
        $existing = $stmt->fetch();
        $billId = is_array($existing) ? (int) $existing['id'] : null;
        $status = is_array($existing) ? (string) $existing['status'] : '';
        if ($amount < 1 || $dueOn === '' || $accountId === null) {
            if ($billId !== null) {
                $this->pdo->prepare('DELETE FROM bills WHERE id = ? AND trip_id = ?')->execute([$billId, $tripId]);
            }

            return;
        }
        if ($billId !== null && $status === 'paid') {
            return;
        }
        $currencyStmt = $this->pdo->prepare('SELECT currency FROM accounts WHERE id = ? AND user_id = ?');
        $currencyStmt->execute([$accountId, $this->actor->id()]);
        $currency = $currencyStmt->fetchColumn();
        $currency = is_string($currency) && $currency !== '' ? $currency : 'USD';
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), 'lodging');
        $now = gmdate('c');
        $name = (string) $data['placeName'];
        if ($billId === null) {
            $this->pdo->prepare(
                'INSERT INTO bills (
                    name, type, account_id, category_id, amount_minor, currency,
                    recurrence, anchor_date, next_due_date, autopay, status, created_at, updated_at,
                    trip_id, lodging_part
                 ) VALUES (?, \'lodging\', ?, ?, ?, ?, \'once\', ?, ?, 0, \'active\', ?, ?, ?, \'due\')'
            )->execute([$name, $accountId, $categoryId, $amount, $currency, $dueOn, $dueOn, $now, $now, $tripId]);
        } else {
            $this->pdo->prepare(
                'UPDATE bills SET
                    name = ?, account_id = ?, category_id = ?, amount_minor = ?, currency = ?,
                    anchor_date = ?, next_due_date = ?, updated_at = ?
                 WHERE id = ? AND trip_id = ? AND lodging_part = \'due\''
            )->execute([$name, $accountId, $categoryId, $amount, $currency, $dueOn, $dueOn, $now, $billId, $tripId]);
        }
    }

    private function refill(string $today): void
    {
        (new BillFill($this->pdo, $this->currencies, $this->actor, $this->drive ?? new Drive(true)))->apply($today);
    }
}
