<?php

declare(strict_types=1);

namespace App\Demo;

use App\Category\SystemBuckets;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use PDO;

final class DemoLibrary
{
    public const NAME = 'Demo';

    public const PASSWORD = 'demo-books-open';

    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
    ) {
    }

    public function ensure(): void
    {
        $owner = $this->pdo->query("SELECT id FROM user WHERE role = 'owner'")->fetchColumn();
        if ($owner === false) {
            return;
        }
        if ($this->demoId() !== null) {
            return;
        }

        $id = $this->insertDemo();
        $this->seed($id);
    }

    public function resetDemo(): void
    {
        $id = $this->demoId();
        if ($id === null) {
            throw new ApiException(409, 'Set up the app first.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM assignments WHERE pay_period_id IN (SELECT id FROM pay_periods WHERE user_id = ?)'
            )->execute([$id]);
            $this->pdo->prepare('DELETE FROM pay_periods WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare(
                'DELETE FROM bills WHERE account_id IN (SELECT id FROM accounts WHERE user_id = ?)'
            )->execute([$id]);
            $this->pdo->prepare('DELETE FROM trips WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM calendar_entries WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM vehicles WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM accounts WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM targets WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM categories WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare(
                'DELETE FROM payroll_entries WHERE pay_cycle_id IN (SELECT id FROM pay_cycles WHERE user_id = ?)'
            )->execute([$id]);
            $this->pdo->prepare('DELETE FROM pay_cycles WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare(
                'UPDATE user SET name = ?, password_hash = ?, base_currency = ?, theme = ? WHERE id = ? AND role = \'demo\''
            )->execute([self::NAME, password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'USD', 'dark', $id]);
            $this->seed($id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function insertDemo(): int
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'USD\', \'dark\', \'demo\', ?)'
        )->execute([self::NAME, password_hash(self::PASSWORD, PASSWORD_DEFAULT), $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)'
        )->execute([$id]);

        return $id;
    }

    private function demoId(): ?int
    {
        $id = $this->pdo->query("SELECT id FROM user WHERE role = 'demo'")->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    private function seed(int $userId): void
    {
        $now = gmdate('c');
        SystemBuckets::ensure($this->pdo, $userId);
        $this->pdo->prepare(
            'UPDATE user SET horizon_days = 60 WHERE id = ?'
        )->execute([$userId]);
        $checking = $this->account($userId, [
            'name' => 'Checking 1', 'type' => 'checking', 'side' => 'asset', 'currency' => 'USD', 'balance' => 249000,
        ], $now);
        $this->account($userId, [
            'name' => 'Checking 2', 'type' => 'checking', 'side' => 'asset', 'currency' => 'USD', 'balance' => 100, 'archived' => true,
        ], $now);
        $savings = $this->account($userId, [
            'name' => 'Savings 1', 'type' => 'savings', 'side' => 'asset', 'currency' => 'USD', 'balance' => 1000000,
        ], $now);
        $this->account($userId, [
            'name' => 'Savings 2', 'type' => 'savings', 'side' => 'asset', 'currency' => 'USD', 'balance' => 20000,
        ], $now);
        $cash = $this->account($userId, [
            'name' => 'Cash 1', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 500,
        ], $now);
        $this->account($userId, [
            'name' => 'Cash 2', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 100000,
        ], $now);
        $this->account($userId, [
            'name' => 'Cash 3', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 10000,
        ], $now);
        $card = $this->account($userId, [
            'name' => 'Card 1', 'type' => 'credit', 'side' => 'liability', 'currency' => 'USD', 'balance' => 40000,
            'interest' => 1999, 'minimum' => 2500, 'dueDay' => 15, 'limit' => 200000, 'autopay' => 'minimum',
        ], $now);
        $loan = $this->account($userId, [
            'name' => 'Loan 1', 'type' => 'loan', 'side' => 'liability', 'currency' => 'USD', 'balance' => 500000,
            'interest' => 699, 'minimum' => 15000, 'dueDay' => 1, 'autopay' => 'balance',
        ], $now);
        $this->account($userId, [
            'name' => 'Fuel 1', 'type' => 'fuel', 'side' => 'liability', 'currency' => 'USD', 'balance' => 8000,
            'interest' => 0, 'minimum' => 2500, 'dueDay' => 20, 'limit' => 50000,
        ], $now);
        $otherAsset = $this->account($userId, [
            'name' => 'Other 1', 'type' => 'other', 'side' => 'asset', 'currency' => 'USD', 'balance' => 75000,
        ], $now);
        $this->account($userId, [
            'name' => 'Other 2', 'type' => 'other', 'side' => 'liability', 'currency' => 'USD', 'balance' => 12000,
        ], $now);

        $this->bill($checking, [
            'name' => 'One-Time Bill', 'type' => 'rent', 'amount' => 8000,
            'recurrence' => 'once', 'anchor' => '2026-11-10', 'next' => '2026-11-10', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Recurring Weekly Bill', 'type' => 'electricity', 'amount' => 2500,
            'recurrence' => 'weekly', 'anchor' => '2026-11-01', 'next' => '2026-11-08', 'status' => 'active',
        ], $now);
        $this->bill($card, [
            'name' => 'Recurring Monthly Bill', 'type' => 'subscriptions', 'amount' => 1599,
            'recurrence' => 'monthly', 'anchor' => '2026-11-15', 'next' => '2026-11-15', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($loan, [
            'name' => 'Recurring Yearly Bill', 'type' => 'healthcare', 'amount' => 120000,
            'recurrence' => 'yearly', 'anchor' => '2026-11-20', 'next' => '2026-11-20', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Tax Bill', 'type' => 'debt', 'amount' => 45000,
            'recurrence' => 'monthly', 'anchor' => '2026-12-01', 'next' => '2026-12-01', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Phone Bill', 'type' => 'cellular', 'amount' => 4500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-12', 'next' => '2026-11-12', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Medical Bill', 'type' => 'healthcare', 'amount' => 7500,
            'recurrence' => 'once', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Transport Bill', 'type' => 'vehicle', 'amount' => 3200,
            'recurrence' => 'monthly', 'anchor' => '2026-11-08', 'next' => '2026-11-08', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Education Bill', 'type' => 'entertainment', 'amount' => 50000,
            'recurrence' => 'yearly', 'anchor' => '2026-12-05', 'next' => '2026-12-05', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Childcare Bill', 'type' => 'shopping', 'amount' => 12000,
            'recurrence' => 'weekly', 'anchor' => '2026-11-06', 'next' => '2026-11-06', 'status' => 'active',
        ], $now);
        $this->bill($cash, [
            'name' => 'Other Bill', 'type' => 'other', 'otherLabel' => 'Custom 1', 'amount' => 2000,
            'recurrence' => 'once', 'anchor' => '2026-11-18', 'next' => '2026-11-18', 'status' => 'active',
        ], $now);
        $this->bill($otherAsset, [
            'name' => 'Overdue Monthly Bill', 'type' => 'rent', 'amount' => 30000,
            'recurrence' => 'monthly', 'anchor' => '2026-10-15', 'next' => '2026-10-15', 'status' => 'active',
        ], $now);
        $this->bill($savings, [
            'name' => 'Paused Weekly Bill', 'type' => 'electricity', 'amount' => 4000,
            'recurrence' => 'weekly', 'anchor' => '2026-11-05', 'next' => '2026-11-12', 'status' => 'paused',
        ], $now);
        $this->bill($checking, [
            'name' => 'Paid One-Time Bill', 'type' => 'healthcare', 'amount' => 9900,
            'recurrence' => 'once', 'anchor' => '2026-10-01', 'next' => '2026-10-01', 'status' => 'paid', 'paidOn' => '2026-10-01',
        ], $now);
        $this->bill($checking, [
            'name' => 'Archived Bill', 'type' => 'groceries', 'amount' => 1100,
            'recurrence' => 'once', 'anchor' => '2026-10-02', 'next' => '2026-10-02', 'status' => 'archived',
        ], $now);
        $this->bill($card, [
            'name' => 'Card 1', 'type' => 'debt', 'amount' => 2500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-15', 'next' => '2026-11-15', 'status' => 'active',
            'sourceAccountId' => $card,
        ], $now);
        $this->bill($loan, [
            'name' => 'Loan 1', 'type' => 'debt', 'amount' => 500000,
            'recurrence' => 'monthly', 'anchor' => '2026-12-01', 'next' => '2026-12-01', 'status' => 'active',
            'sourceAccountId' => $loan,
        ], $now);
        $this->bill($checking, [
            'name' => 'Paid Weekly Bill', 'type' => 'electricity', 'amount' => 2468,
            'recurrence' => 'weekly', 'anchor' => '2026-11-04', 'next' => '2026-11-11', 'status' => 'active', 'paidOn' => '2026-11-04',
        ], $now);
        $this->bill($checking, [
            'name' => 'Lodging Bill', 'type' => 'lodging', 'amount' => 6400,
            'recurrence' => 'monthly', 'anchor' => '2026-11-03', 'next' => '2026-11-03', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Water Bill', 'type' => 'water', 'amount' => 2800,
            'recurrence' => 'monthly', 'anchor' => '2026-11-09', 'next' => '2026-11-09', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Internet Bill', 'type' => 'internet', 'amount' => 7000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-11', 'next' => '2026-11-11', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Fuel Bill', 'type' => 'fuel', 'amount' => 3600,
            'recurrence' => 'monthly', 'anchor' => '2026-11-14', 'next' => '2026-11-14', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Groceries Bill', 'type' => 'groceries', 'amount' => 5400,
            'recurrence' => 'weekly', 'anchor' => '2026-11-07', 'next' => '2026-11-07', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Restaurants Bill', 'type' => 'restaurants', 'amount' => 2200,
            'recurrence' => 'monthly', 'anchor' => '2026-11-16', 'next' => '2026-11-16', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Travel Bill', 'type' => 'travel', 'amount' => 8800,
            'recurrence' => 'yearly', 'anchor' => '2026-11-19', 'next' => '2026-11-19', 'status' => 'active',
        ], $now);
        foreach ([
            'propane' => 'Propane Bill',
            'maintenance' => 'Maintenance Bill',
            'insurance' => 'Insurance Bill',
            'roadside' => 'Roadside Bill',
            'mailbox' => 'Mailbox Bill',
            'laundry' => 'Laundry Bill',
            'mortgage' => 'Mortgage Bill',
            'storage' => 'Storage Bill',
            'memberships' => 'Memberships Bill',
            'software' => 'Software Bill',
            'pets' => 'Pets Bill',
            'emergency' => 'Emergency Bill',
            'repairs' => 'Repairs Bill',
            'investments' => 'Investments Bill',
            'liquidities' => 'Liquidities Bill',
        ] as $type => $name) {
            $this->bill($checking, [
                'name' => $name, 'type' => $type, 'amount' => 1000,
                'recurrence' => 'monthly', 'anchor' => '2026-11-20', 'next' => '2026-11-20', 'status' => 'active',
            ], $now);
        }
        $this->bill($checking, [
            'name' => 'Misc Bill', 'type' => 'misc', 'otherLabel' => 'Custom 2', 'amount' => 1200,
            'recurrence' => 'monthly', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active',
        ], $now);

        $this->category($userId, 'Needs 1', 'needs', $now);
        $this->category($userId, 'Wants 1', 'wants', $now);
        $this->category($userId, 'Savings 1', 'savings', $now);
        $this->category($userId, 'Needed By Date 1', 'savings', $now);
        $this->category($userId, 'Target Balance 1', 'savings', $now);
        $this->category($userId, 'Archived Category', 'needs', $now, true);
        $this->payCycle($userId, 'Weekly Payor', '2026-11-01', 'weekly', $now, null, null, false, 'hourly', 2500, 4000);
        $this->payCycle($userId, 'Biweekly Payor', '2026-11-01', 'biweekly', $now, null, null, false, 'hourly', 3000, 8000);
        $this->payCycle($userId, 'Semimonthly Payor', '2026-11-01', 'semimonthly', $now, 15, null, false, 'salary', 6000000);
        $this->payCycle($userId, 'Weekday Payor', '2026-11-01', 'semimonthly', $now, 15, null, false, 'hourly', 2500, null, 'weekdays');
        $averaged = $this->payCycle($userId, 'Averaged Payor', '2026-11-01', 'semimonthly', $now, 15, null, false, 'fixed', 10000, null, 'typed', true);
        $this->payroll($averaged, '2026-10-01', 300000);
        $this->payroll($averaged, '2026-10-15', 500000);
        $this->payroll($averaged, '2026-11-01', 400000);
        $this->payCycle($userId, 'Monthly Payor', '2026-11-01', 'monthly', $now, null, null, false, 'salary', 8400000);
        $this->payCycle($userId, 'Monthly Weekday Payor', '2026-11-01', 'monthly', $now, null, null, false, 'hourly', 11000, 800, 'weekdays');
        $this->payCycle($userId, 'Irregular Payor', '2026-11-01', 'irregular', $now, null, '2026-12-15', false, 'fixed', 150000);
        $this->payCycle($userId, 'Archived Payor', '2026-11-01', 'monthly', $now, null, null, true, 'fixed', 10000);
        $this->seedEnvelopes($userId, $now);
        $this->seedTargets($userId, $now);
        $this->seedLife($userId, $now);
        $this->pdo->prepare(
            'INSERT INTO calendar_entries (user_id, text, start_on, end_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, 'Calendar entry 1', '2026-11-05', '2026-11-06', $now, $now]);
    }

    private function seedTargets(int $userId, string $now): void
    {
        $category = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ?');
        $cycle = $this->pdo->prepare('SELECT id FROM pay_cycles WHERE user_id = ? AND payor = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO targets (
                user_id, category_id, kind, amount_minor, target_date, pay_cycle_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $category->execute([$userId, 'Needed By Date 1']);
        $cycle->execute([$userId, 'Monthly Payor']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'needed', 120000, '2027-02-01', (int) $cycle->fetchColumn(), $now, $now]);
        $category->execute([$userId, 'Target Balance 1']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'balance', 50000, null, null, $now, $now]);
    }

    private function seedLife(int $userId, string $now): void
    {
        $account = $this->pdo->prepare('SELECT id FROM accounts WHERE user_id = ? AND name = ?');
        $category = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ?');
        $account->execute([$userId, 'Checking 1']);
        $checking = (int) $account->fetchColumn();
        $account->execute([$userId, 'Cash 1']);
        $cash = (int) $account->fetchColumn();
        $account->execute([$userId, 'Savings 1']);
        $savings = (int) $account->fetchColumn();
        $fuel = SystemBuckets::idForType($this->pdo, $userId, 'fuel');

        $this->pdo->prepare('UPDATE accounts SET interest_bps = ? WHERE id = ?')->execute([450, $savings]);

        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, note, arrive_on, depart_on, latitude, longitude,
                city, region, address, account_id, total_minor, amount_due_minor, balance_due_on,
                rating_half_stars, rating_comment, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, 'Stay 1', null, '2026-11-20', '2026-11-24', '36.1699', '-115.1398', 'Las Vegas', 'NV', '1 Fremont St, Las Vegas, NV 89101', $checking, 12000, 12000, '2026-11-22', null, null, $now, $now]);
        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, note, arrive_on, depart_on, latitude, longitude,
                city, region, address, account_id, total_minor, amount_due_minor, balance_due_on,
                rating_half_stars, rating_comment, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, 'Stay 2', 'Site 34', '2026-12-01', '2026-12-05', '34.0522', '-118.2437', 'Los Angeles', 'CA', '1 Main St, Los Angeles, CA 90012', $cash, 8000, 7000, '2026-12-01', 7, 'Comment 1', $now, $now]);
        $vehicle = $this->vehicle($userId, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-09-15', 1000, 9980, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-10-01', 600, 10000, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-10-01', 400, 10000, 400, $now);
        $this->fuelBill($cash, $fuel, null, '2026-10-15', 500, null, null, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-01', 2000, 10100, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-08', 1000, 10300, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-18', 1000, 10500, 400, $now);
    }

    private function fuelBill(
        int $accountId,
        int $categoryId,
        ?int $vehicleId,
        string $date,
        int $amount,
        ?int $odometer,
        ?int $price,
        string $now,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, last_paid_on, autopay, status,
                created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor
             ) VALUES (\'Fuel 1\', \'fuel\', ?, ?, ?, \'USD\', \'once\', ?, ?, ?, 0, \'paid\', ?, ?, ?, ?, ?)'
        )->execute([
            $accountId, $categoryId, $amount, $date, $date, $date, $now, $now, $vehicleId, $odometer, $price,
        ]);
    }

    private function seedEnvelopes(int $userId, string $now): void
    {
        $needs = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ?');
        $needs->execute([$userId, 'Needs 1']);
        $categoryId = (int) $needs->fetchColumn();
        $cycle = $this->pdo->prepare('SELECT id FROM pay_cycles WHERE user_id = ? AND payor = ?');
        $cycle->execute([$userId, 'Monthly Payor']);
        $cycleId = (int) $cycle->fetchColumn();
        $this->period($userId, $cycleId, '2026-10-01', '2026-10-31', $categoryId, 20000, $now);
        $this->period($userId, $cycleId, '2026-11-01', '2026-11-30', $categoryId, 50000, $now);
        $this->pdo->prepare('UPDATE categories SET amount_minor = 50000 WHERE id = ?')->execute([$categoryId]);
        $this->pdo->prepare(
            'UPDATE bills SET category_id = ?
             WHERE name = ? AND account_id IN (SELECT id FROM accounts WHERE user_id = ?)'
        )->execute([$categoryId, 'One-Time Bill', $userId]);
    }

    private function period(
        int $userId,
        int $cycleId,
        string $start,
        string $end,
        int $categoryId,
        int $amount,
        string $now,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO pay_periods (user_id, pay_cycle_id, period_start, period_end, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $cycleId, $start, $end, $now]);
        $periodId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor) VALUES (?, ?, ?)'
        )->execute([$periodId, $categoryId, $amount]);

        return $periodId;
    }

    /** @param array{name:string,type:string,side:string,currency:string,balance:int,interest?:int,minimum?:int,dueDay?:int,limit?:int,archived?:bool} $row */
    private function account(int $userId, array $row, string $now): int
    {
        $this->currencies->assert($row['currency']);
        $this->pdo->prepare(
            'INSERT INTO accounts (
                user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
                due_day, credit_limit_minor, autopay_choice, archived_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $row['name'],
            $row['type'],
            $row['side'],
            $row['currency'],
            $row['balance'],
            $row['interest'] ?? null,
            $row['minimum'] ?? null,
            $row['dueDay'] ?? null,
            $row['limit'] ?? null,
            $row['autopay'] ?? 'none',
            !empty($row['archived']) ? $now : null,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{name:string,type:string,amount:int,recurrence:string,anchor:string,next:string,status:string,otherLabel?:string,autopay?:bool,currency?:string,paidOn?:string} $row */
    private function bill(int $accountId, array $row, string $now): void
    {
        $owner = $this->pdo->prepare('SELECT user_id, currency FROM accounts WHERE id = ?');
        $owner->execute([$accountId]);
        $account = $owner->fetch();
        $userId = is_array($account) ? (int) $account['user_id'] : 0;
        $currency = $row['currency'] ?? (is_array($account) ? (string) $account['currency'] : 'USD');
        $this->currencies->assert($currency);
        $bucketId = SystemBuckets::idForType($this->pdo, $userId, $row['type']);
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, other_label, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, last_paid_on, autopay, status, created_at, updated_at,
                source_account_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['name'],
            $row['type'],
            $row['otherLabel'] ?? null,
            $accountId,
            $bucketId,
            $row['amount'],
            $currency,
            $row['recurrence'],
            $row['anchor'],
            $row['next'],
            $row['paidOn'] ?? null,
            !empty($row['autopay']) ? 1 : 0,
            $row['status'],
            $now,
            $now,
            $row['sourceAccountId'] ?? null,
        ]);
    }

    private function vehicle(int $userId, string $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO vehicles (
                user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred,
                current_odometer_miles, origin_odometer_miles, fuel_price_minor, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 1, 10500, 9800, 400, ?, ?)'
        )->execute([$userId, 'Vehicle 1', 'rv', 60, 120, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function category(
        int $userId,
        string $name,
        string $group,
        string $now,
        bool $archived = false,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO categories (
                user_id, name, category_group, amount_minor, archived_at, created_at, updated_at
             ) VALUES (?, ?, ?, 0, ?, ?, ?)'
        )->execute([$userId, $name, $group, $archived ? $now : null, $now, $now]);
    }

    private function payCycle(
        int $userId,
        string $payor,
        string $payday,
        string $length,
        string $now,
        ?int $secondDay = null,
        ?string $nextPayday = null,
        bool $archived = false,
        string $kind = 'fixed',
        int $rate = 0,
        ?int $hours = null,
        string $hoursMode = 'typed',
        bool $averageOn = false,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO pay_cycles (
                user_id, payor, payday, length, second_day, next_payday,
                pay_kind, rate_minor, hours_hundredths, hours_mode, average_on,
                archived_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $payor,
            $payday,
            $length,
            $secondDay,
            $nextPayday,
            $kind,
            $rate,
            $hours,
            $hoursMode,
            $averageOn ? 1 : 0,
            $archived ? $now : null,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function payroll(int $cycleId, string $payday, int $amount): void
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_entries (pay_cycle_id, payday, amount_minor) VALUES (?, ?, ?)'
        )->execute([$cycleId, $payday, $amount]);
    }

}
