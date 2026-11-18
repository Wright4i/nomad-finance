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
            $this->pdo->prepare('DELETE FROM acct_period_assignments WHERE user_id = ?')->execute([$id]);
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
                'UPDATE user SET name = ?, password_hash = ?, base_currency = ?, theme = ?, envelope_adopted = 0 WHERE id = ? AND role = \'demo\''
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
            'UPDATE user SET acct_period_start = ?, acct_period_length = \'1 month\' WHERE id = ?'
        )->execute([gmdate('Y-m-01'), $userId]);
        // Anonymized full-timer. Two live jobs: a $100,000 salary and a lower hourly side job.
        // The other pay lengths stay archived. Accounts are a short mix, every type still present.
        $checking = $this->account($userId, [
            'name' => 'Checking', 'type' => 'checking', 'side' => 'asset', 'currency' => 'USD', 'balance' => 266000,
            'holder' => 'Demo', 'institution' => 'Credit union', 'assetFavorite' => true,
        ], $now);
        $this->account($userId, [
            'name' => 'Old checking', 'type' => 'checking', 'side' => 'asset', 'currency' => 'USD', 'balance' => 1840,
            'institution' => 'Credit union', 'archived' => true,
        ], $now);
        $savings = $this->account($userId, [
            'name' => 'Savings', 'type' => 'savings', 'side' => 'asset', 'currency' => 'USD', 'balance' => 5000000,
            'interest' => 425, 'institution' => 'Online bank', 'holder' => 'Demo',
        ], $now);
        $cash = $this->account($userId, [
            'name' => 'Center console', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 11500,
        ], $now);
        $card = $this->account($userId, [
            'name' => 'Travel card', 'type' => 'credit', 'side' => 'liability', 'currency' => 'USD', 'balance' => 173300,
            'shoppingFavorite' => true, 'interest' => 1800, 'minimum' => 3500, 'dueDay' => 8, 'limit' => 500000,
            'autopay' => 'balance', 'bankAutopay' => true, 'institution' => 'Card issuer', 'holder' => 'Demo',
        ], $now);
        $loan = $this->account($userId, [
            'name' => 'Truck loan', 'type' => 'loan', 'side' => 'liability', 'currency' => 'USD', 'balance' => 350000,
            'interest' => 699, 'minimum' => 10000, 'dueDay' => 16, 'autopay' => 'minimum', 'bankAutopay' => true,
            'institution' => 'Credit union', 'liabilityBillType' => 'vehicle',
        ], $now);
        $trailer = $this->account($userId, [
            'name' => 'Trailer plan', 'type' => 'loan', 'side' => 'liability', 'currency' => 'USD', 'balance' => 220000,
            'interest' => 0, 'minimum' => 11000, 'dueDay' => 21, 'autopay' => 'minimum', 'bankAutopay' => true,
            'institution' => 'Lender',
        ], $now);
        $fuelCard = $this->account($userId, [
            'name' => 'Fuel card', 'type' => 'fuel', 'side' => 'liability', 'currency' => 'USD', 'balance' => 0, 'gasFavorite' => true,
            'interest' => 0, 'minimum' => 0, 'limit' => 100000,
            'autopay' => 'balance', 'bankAutopay' => true, 'billRecurrence' => 'daily', 'billEvery' => 7,
            'institution' => 'Fuel network', 'holder' => 'Demo',
        ], $now);
        $this->account($userId, [
            'name' => 'Park deposit', 'type' => 'other', 'side' => 'asset', 'currency' => 'USD', 'balance' => 50000,
            'inBudget' => false,
        ], $now);
        $this->account($userId, [
            'name' => 'Camp tab', 'type' => 'other', 'side' => 'liability', 'currency' => 'USD', 'balance' => 8500,
        ], $now);

        $this->bill($checking, [
            'name' => 'Site week', 'type' => 'rent', 'amount' => 38500,
            'recurrence' => 'once', 'anchor' => '2026-11-10', 'next' => '2026-11-10', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Camp rent', 'type' => 'rent', 'amount' => 42000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-15', 'next' => '2026-11-15', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Shore power', 'type' => 'electricity', 'amount' => 1800,
            'recurrence' => 'weekly', 'anchor' => '2026-11-01', 'next' => '2026-11-08', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Phone', 'type' => 'cellular', 'amount' => 26500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-23', 'next' => '2026-11-23', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Prescription', 'type' => 'healthcare', 'amount' => 2900,
            'recurrence' => 'monthly', 'anchor' => '2026-11-17', 'next' => '2026-11-17', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Health plan', 'type' => 'healthcare', 'amount' => 7900,
            'recurrence' => 'monthly', 'anchor' => '2026-12-27', 'next' => '2026-12-27', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Auto insurance', 'type' => 'insurance', 'amount' => 18200,
            'recurrence' => 'monthly', 'anchor' => '2026-11-26', 'next' => '2026-11-26', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'RV insurance', 'type' => 'insurance', 'amount' => 10100,
            'recurrence' => 'monthly', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Hotspot', 'type' => 'internet', 'amount' => 1500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-01', 'next' => '2026-12-01', 'status' => 'active', 'autopay' => true, 'paidOn' => '2026-11-01',
        ], $now);
        $this->bill($checking, [
            'name' => 'Camp data', 'type' => 'internet', 'amount' => 8000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-04', 'next' => '2026-12-04', 'status' => 'active', 'autopay' => true, 'paidOn' => '2026-11-04',
        ], $now);
        $this->bill($checking, [
            'name' => 'Starlink', 'type' => 'internet', 'amount' => 17500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-17', 'next' => '2026-11-17', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Registration', 'type' => 'vehicle', 'amount' => 12000,
            'recurrence' => 'yearly', 'every' => 2, 'anchor' => '2027-06-15', 'next' => '2027-06-15', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Code host', 'type' => 'subscriptions', 'amount' => 1000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-26', 'next' => '2026-11-26', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($cash, [
            'name' => 'Shower tokens', 'type' => 'other', 'otherLabel' => 'Shower tokens', 'amount' => 800,
            'recurrence' => 'once', 'anchor' => '2026-11-18', 'next' => '2026-11-18', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Pedestal', 'type' => 'electricity', 'amount' => 2200,
            'recurrence' => 'weekly', 'anchor' => '2026-11-05', 'next' => '2026-11-12', 'status' => 'paused',
        ], $now);
        $this->bill($checking, [
            'name' => 'Urgent care', 'type' => 'healthcare', 'amount' => 8500,
            'recurrence' => 'once', 'anchor' => '2026-10-01', 'next' => '2026-10-01', 'status' => 'paid', 'paidOn' => '2026-10-01',
        ], $now);
        $this->bill($checking, [
            'name' => 'Old meal kit', 'type' => 'groceries', 'amount' => 1200,
            'recurrence' => 'once', 'anchor' => '2026-10-02', 'next' => '2026-10-02', 'status' => 'archived',
        ], $now);
        $this->bill($checking, [
            'name' => 'Travel card', 'type' => 'debt', 'amount' => 3500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-08', 'next' => '2026-11-08', 'status' => 'active', 'autopay' => true,
            'sourceAccountId' => $card,
        ], $now);
        $this->bill($checking, [
            'name' => 'Truck loan', 'type' => 'vehicle', 'amount' => 10000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-16', 'next' => '2026-11-16', 'status' => 'active', 'autopay' => true,
            'sourceAccountId' => $loan,
        ], $now);
        $this->bill($checking, [
            'name' => 'Trailer plan', 'type' => 'debt', 'amount' => 11000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active',
            'sourceAccountId' => $trailer,
        ], $now);
        $this->bill($checking, [
            'name' => 'Fuel card', 'type' => 'fuel', 'amount' => 4000,
            'recurrence' => 'daily', 'every' => 7, 'anchor' => '2026-11-07', 'next' => '2026-11-07', 'status' => 'active', 'autopay' => true,
            'sourceAccountId' => $fuelCard,
        ], $now);
        $this->bill($checking, [
            'name' => 'Metered electric', 'type' => 'electricity', 'amount' => 1840,
            'recurrence' => 'weekly', 'anchor' => '2026-11-04', 'next' => '2026-11-11', 'status' => 'active', 'paidOn' => '2026-11-04',
        ], $now);
        $this->bill($checking, [
            'name' => 'County night', 'type' => 'lodging', 'amount' => 2500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-03', 'next' => '2026-11-03', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Water', 'type' => 'water', 'amount' => 21400,
            'recurrence' => 'once', 'anchor' => '2026-11-19', 'next' => '2026-11-19', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Fuel stop', 'type' => 'fuel', 'amount' => 8500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-14', 'next' => '2026-11-14', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Groceries', 'type' => 'groceries', 'amount' => 11500,
            'recurrence' => 'weekly', 'anchor' => '2026-11-07', 'next' => '2026-11-07', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Diner', 'type' => 'restaurants', 'amount' => 2800,
            'recurrence' => 'monthly', 'anchor' => '2026-11-16', 'next' => '2026-11-16', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Annual pass', 'type' => 'travel', 'amount' => 8000,
            'recurrence' => 'yearly', 'anchor' => '2026-11-19', 'next' => '2026-11-19', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Park pass', 'type' => 'entertainment', 'amount' => 8000,
            'recurrence' => 'yearly', 'anchor' => '2026-12-05', 'next' => '2026-12-05', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Camp store', 'type' => 'shopping', 'amount' => 2200,
            'recurrence' => 'weekly', 'anchor' => '2026-11-06', 'next' => '2026-11-06', 'status' => 'active',
        ], $now);
        foreach ([
            'propane' => ['Propane', 3500],
            'maintenance' => ['Rig service', 4500],
            'roadside' => ['Roadside', 900],
            'mailbox' => ['Mailbox', 1000],
            'laundry' => ['Laundry', 1600],
            'mortgage' => ['Lot fee', 5000],
            'storage' => ['Storage', 37500],
            'memberships' => ['GPS plan', 849],
            'software' => ['Software tools', 2000],
            'pets' => ['Pet insurance', 5400],
            'emergency' => ['Emergency move', 5000],
            'repairs' => ['Repair parts', 7500],
            'investments' => ['Broker sweep', 10000],
            'liquidities' => ['Cash buffer', 2500],
        ] as $type => [$name, $amount]) {
            $this->bill($checking, [
                'name' => $name, 'type' => $type, 'amount' => $amount,
                'recurrence' => 'monthly', 'anchor' => '2026-11-20', 'next' => '2026-11-20', 'status' => 'active',
            ], $now);
        }
        $this->bill($checking, [
            'name' => 'Rescue plan', 'type' => 'memberships', 'amount' => 3995,
            'recurrence' => 'yearly', 'anchor' => '2027-08-20', 'next' => '2027-08-20', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Bookkeeping', 'type' => 'software', 'amount' => 2900,
            'recurrence' => 'monthly', 'anchor' => '2026-11-01', 'next' => '2026-12-01', 'status' => 'active', 'autopay' => true, 'paidOn' => '2026-11-01',
        ], $now);
        $this->bill($checking, [
            'name' => 'Camp soap', 'type' => 'misc', 'otherLabel' => 'Camp soap', 'amount' => 800,
            'recurrence' => 'monthly', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active',
        ], $now);

        $this->category($userId, 'Road cushion', 'needs', $now);
        $this->category($userId, 'Fun money', 'wants', $now);
        $this->category($userId, 'Next rig', 'savings', $now);
        $this->category($userId, 'New trailer', 'savings', $now);
        $this->category($userId, 'Tires', 'savings', $now);
        $this->category($userId, 'Repair reserve', 'savings', $now);
        $this->category($userId, 'Old gym', 'needs', $now, true);
        $this->payCycle($userId, 'Day job', '2026-11-01', 'monthly', $now, null, null, false, 'salary', 10000000);
        $side = $this->payCycle($userId, 'Side job', '2026-11-01', 'semimonthly', $now, 16, null, false, 'hourly', 2200, 2000, 'typed', true);
        $this->payroll($side, '2026-10-01', 38000);
        $this->payroll($side, '2026-10-16', 50000);
        $this->payroll($side, '2026-11-01', 44000);
        $this->payCycle($userId, 'Weekend shift', '2026-11-01', 'weekly', $now, null, null, true, 'hourly', 1600, 800);
        $this->payCycle($userId, 'Market day', '2026-11-01', 'biweekly', $now, null, null, true, 'hourly', 1500, 800);
        $this->payCycle($userId, 'Old retainer', '2026-11-01', 'semimonthly', $now, 15, null, true, 'salary', 960000);
        $this->payCycle($userId, 'Weekday contract', '2026-11-01', 'semimonthly', $now, 15, null, true, 'hourly', 1200, null, 'weekdays');
        $averaged = $this->payCycle($userId, 'Month average', '2026-11-01', 'semimonthly', $now, 15, null, true, 'fixed', 10000, null, 'typed', true);
        $this->payroll($averaged, '2026-10-01', 80000);
        $this->payroll($averaged, '2026-10-15', 110000);
        $this->payroll($averaged, '2026-11-01', 95000);
        $this->payCycle($userId, 'Shop days', '2026-11-01', 'monthly', $now, null, null, true, 'hourly', 2000, 300, 'weekdays');
        $this->payCycle($userId, 'Odd jobs', '2026-11-01', 'irregular', $now, null, '2026-12-15', true, 'fixed', 45000);
        $this->payCycle($userId, 'Warehouse shift', '2026-11-01', 'monthly', $now, null, null, true, 'fixed', 40000);
        $this->seedEnvelopes($userId, $now);
        $this->seedTargets($userId, $now);
        $this->seedLife($userId, $now);
        $calendar = $this->pdo->prepare(
            'INSERT INTO calendar_entries (user_id, text, start_on, end_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ([
            ['Grocery run', '2026-08-10', '2026-08-10'],
            ['Mail pickup', '2026-09-05', '2026-09-05'],
            ['Dump and fill', '2026-10-06', '2026-10-07'],
        ] as [$text, $start, $end]) {
            $calendar->execute([$userId, $text, $start, $end, $now, $now]);
        }
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
        $category->execute([$userId, 'Tires']);
        $cycle->execute([$userId, 'Day job']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'needed', 120000, '2027-02-01', (int) $cycle->fetchColumn(), $now, $now]);
        $category->execute([$userId, 'New trailer']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'balance', 5000000, null, null, $now, $now]);
        $category->execute([$userId, 'Repair reserve']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'balance', 50000, null, null, $now, $now]);
    }

    private function seedLife(int $userId, string $now): void
    {
        $account = $this->pdo->prepare('SELECT id FROM accounts WHERE user_id = ? AND name = ?');
        $account->execute([$userId, 'Checking']);
        $checking = (int) $account->fetchColumn();
        $account->execute([$userId, 'Center console']);
        $cash = (int) $account->fetchColumn();
        $account->execute([$userId, 'Travel card']);
        $card = (int) $account->fetchColumn();
        $fuel = SystemBuckets::idForType($this->pdo, $userId, 'fuel');

        // Home through the Atlanta conference. Every day in that span is on a site.
        // The house address is a city pin. The Anaheim conference note and street are left off.
        $this->stay($userId, $now, 'Home base', null, '2026-01-01', '2026-06-15', '33.8753', '-117.5664', 'Corona', 'CA', 'Corona, CA', $checking, 0, 0, '2026-01-01', null, null);
        $this->stay($userId, $now, 'Oak Creek RV Park', null, '2026-06-14', '2026-06-15', '36.83658', '-118.22897', 'Independence', 'CA', '131 N US-395, Independence, CA 93526', $checking, 0, 0, '2026-06-14', null, null);
        $this->stay($userId, $now, 'Lake Tahoe KOA', null, '2026-06-15', '2026-06-16', '38.84678', '-120.02868', 'South Lake Tahoe', 'CA', '760 North Highway 50, South Lake Tahoe, CA 96150', $checking, 0, 0, '2026-06-15', 8, 'Lake and pines.');
        $this->stay($userId, $now, 'Oroville / Feather Falls Casino KOA Journey', null, '2026-06-16', '2026-06-18', '39.46530', '-121.51817', 'Oroville', 'CA', '3 Alverda Dr., Oroville, CA 95966', $checking, 0, 0, '2026-06-16', null, null);
        $this->stay($userId, $now, 'Klamath Falls KOA Journey', null, '2026-06-18', '2026-06-20', '42.21399', '-121.74666', 'Klamath Falls', 'OR', '3435 Shasta Way, Klamath Falls, OR 97603', $checking, 0, 0, '2026-06-18', null, null);
        $this->stay($userId, $now, 'Imperial River Company', null, '2026-06-20', '2026-06-22', '45.17201', '-121.07518', 'Maupin', 'OR', 'Bakeoven Rd, Maupin, OR 97037', $checking, 0, 0, '2026-06-20', null, null);
        $this->stay($userId, $now, 'Ellensburg KOA Journey', null, '2026-06-22', '2026-06-25', '47.00603', '-120.59493', 'Ellensburg', 'WA', '32 S. Thorp Hwy., Ellensburg, WA 98926', $checking, 0, 0, '2026-06-22', null, null);
        $this->stay($userId, $now, 'Gorge Amphiteater Event Camping', null, '2026-06-25', '2026-06-29', '47.10123', '-119.99560', 'Quincy', 'WA', '22936 Rd 1 NW, Quincy, WA 98848', $checking, 0, 0, '2026-06-25', null, null);
        $this->stay($userId, $now, 'Ellensburg KOA Journey', null, '2026-06-29', '2026-07-01', '47.00603', '-120.59493', 'Ellensburg', 'WA', '32 S. Thorp Hwy., Ellensburg, WA 98926', $checking, 0, 0, '2026-06-29', null, null);
        $this->stay($userId, $now, 'Lake Easton State Park', null, '2026-07-01', '2026-07-02', '47.24464', '-121.18541', 'Easton', 'WA', '150 Lake Easton State Park Road, Easton, WA 98925', $checking, 0, 0, '2026-07-01', null, null);
        $this->stay($userId, $now, 'Sun Outdoors Gig Harbor', null, '2026-07-02', '2026-07-05', '47.34449', '-122.59909', 'Gig Harbor', 'WA', '9515 Burnham Dr, Gig Harbor, WA 98332', $checking, 0, 0, '2026-07-02', null, null);
        $this->stay($userId, $now, 'Dosewallips State Park', null, '2026-07-05', '2026-07-09', '47.68558', '-122.90034', 'Brinnon', 'WA', '306996 Highway 101, Brinnon, WA 98320', $checking, 0, 0, '2026-07-05', null, null);
        $this->stay($userId, $now, 'Bayshore RV Park', null, '2026-07-09', '2026-07-12', '46.70800', '-123.98620', 'Tokeland', 'WA', '2941 Kindred Ave, Tokeland, WA 98590', $checking, 0, 0, '2026-07-09', null, null);
        $this->stay($userId, $now, 'Blue Heron French Cheese', null, '2026-07-12', '2026-07-13', '45.46694', '-123.84247', 'Tillamook', 'OR', 'Blue Heron Rd, Tillamook, OR 97141', $checking, 0, 0, '2026-07-12', null, null);
        $this->stay($userId, $now, 'Lincoln City KOA Holiday', null, '2026-07-13', '2026-07-16', '44.99319', '-123.97893', 'Otis', 'OR', '5298 Northeast Park Lane, Otis, OR 97368', $checking, 0, 0, '2026-07-13', null, null);
        $this->stay($userId, $now, 'Spirit Mountain Casino RV Park', null, '2026-07-16', '2026-07-18', '45.05912', '-123.57953', 'Grand Ronde', 'OR', '27100 SW Salmon River Hwy, Grand Ronde, OR 97347', $checking, 0, 0, '2026-07-16', null, null);
        $this->stay($userId, $now, 'John Neal Memorial Park', null, '2026-07-18', '2026-07-21', '44.78298', '-122.60836', 'Lyons', 'OR', '820 Neal Park Rd., Lyons, OR 97358', $checking, 0, 0, '2026-07-18', null, null);
        $this->stay($userId, $now, 'Reeder Beach Resort', null, '2026-07-21', '2026-07-26', '45.71249', '-122.77311', 'Portland', 'OR', '26048 NW Reeder Rd, Portland, OR 97231', $checking, 0, 0, '2026-07-21', null, null);
        $this->stay($userId, $now, 'OCEAN User Group', null, '2026-07-23', '2026-07-25', '33.8029445', '-117.9161528', 'Anaheim', 'CA', 'Anaheim, CA', $checking, 0, 0, '2026-07-23', null, null, 'fly');
        $this->stay($userId, $now, 'Albany / Corvallis KOA Journey', null, '2026-07-26', '2026-07-27', '44.56360', '-123.15345', 'Albany', 'OR', '33775 Oakville Road South, Albany, OR 97321', $checking, 0, 0, '2026-07-26', null, null);
        $this->stay($userId, $now, 'Bish\'s Service Center', null, '2026-07-27', '2026-07-30', '44.21445', '-123.20590', 'Junction City', 'OR', 'W 1st Ave, Junction City, OR 97448', $checking, 0, 0, '2026-07-27', null, null);
        $this->stay($userId, $now, 'Umpqua\'s Last Resort', null, '2026-07-30', '2026-08-02', '43.29582', '-122.60678', 'Idleyld Park', 'OR', '115 Elk Ridge Ln, Idleyld Park, OR 97447', $checking, 0, 0, '2026-07-30', null, null);
        $this->stay($userId, $now, 'Mystic Forest RV Park', null, '2026-08-02', '2026-08-06', '41.57660', '-124.07391', 'Klamath', 'CA', '15875 US Highway 101 N, Klamath, CA 95548', $checking, 0, 0, '2026-08-02', null, null);
        $this->stay($userId, $now, 'Redwoods River Resort', null, '2026-08-06', '2026-08-09', '39.91939', '-123.76602', 'Leggett', 'CA', '75000 US-101, Leggett, CA 95585', $checking, 0, 0, '2026-08-06', null, null);
        $this->stay($userId, $now, 'San Francisco North / Petaluma KOA', 'SPOT J145', '2026-08-09', '2026-08-13', '38.27180', '-122.67744', 'Petaluma', 'CA', '20 Rainsville Road, Petaluma, CA 94952', $checking, 0, 0, '2026-08-09', null, null);
        $this->stay($userId, $now, 'Yanks RV Resort', null, '2026-08-13', '2026-08-14', '36.34239', '-121.25402', 'Greenfield', 'CA', '1005 Yanks Way, Greenfield, CA 93927', $checking, 0, 0, '2026-08-13', null, null);
        $this->stay($userId, $now, 'Avila Pismo RV Resort & Campground', 'SPOT 21', '2026-08-14', '2026-08-16', '35.18289', '-120.70363', 'San Luis Obispo', 'CA', '7075 Ontario Road, San Luis Obispo, CA 93405', $checking, 0, 0, '2026-08-14', null, null);
        $this->stay($userId, $now, 'Prado Regional Park', 'SPOT 75', '2026-08-16', '2026-08-20', '33.94618', '-117.65165', 'Chino', 'CA', '16700 Euclid Ave, Chino, CA 91708', $checking, 0, 0, '2026-08-16', null, null);
        $this->stay($userId, $now, 'Canyon RV Park', 'SITE 54', '2026-08-20', '2026-08-30', '33.86801', '-117.71243', 'Anaheim', 'CA', '24001 Santa Ana Canyon Rd, Anaheim, CA 92887', $checking, 0, 0, '2026-08-20', null, null);
        $this->stay($userId, $now, 'Pechanga RV Resort & Casino', 'SITE C1', '2026-08-30', '2026-09-04', '33.45186', '-117.10111', 'Temecula', 'CA', '45000 Pechanga Pkwy, Temecula, CA 92592', $checking, 0, 0, '2026-08-30', null, null);
        $this->stay($userId, $now, 'Temecula / Vail Lake KOA', 'Site 201', '2026-09-04', '2026-09-07', '33.46833', '-116.99788', 'Temecula', 'CA', '38000 Hwy 79 South, Temecula, CA 92592', $checking, 0, 0, '2026-09-04', null, null);
        $this->stay($userId, $now, 'Launch Pointe Recreation Destination & RV Park', 'ST 133', '2026-09-07', '2026-09-14', '33.67565', '-117.37396', 'Lake Elsinore', 'CA', '32040 Riverside Dr, Lake Elsinore, CA 92530', $checking, 0, 0, '2026-09-07', null, null);
        $this->stay($userId, $now, 'Prado Regional Park', 'Site 70', '2026-09-14', '2026-09-24', '33.94618', '-117.65165', 'Chino', 'CA', '16700 Euclid Ave, Chino, CA 91708', $checking, 0, 0, '2026-09-14', null, null);
        $this->stay($userId, $now, 'Bonelli Bluffs RV Resort & Campground', 'Preferred D PD022', '2026-09-24', '2026-10-01', '34.07997', '-117.78979', 'San Dimas', 'CA', '1440 Camper View Rd, San Dimas, CA 91773', $checking, 0, 0, '2026-09-27', null, null);
        $this->stay($userId, $now, 'Tinnemaha Creek Campground', 'SITE 007', '2026-10-01', '2026-10-04', '37.05222', '-118.26962', 'Big Pine', 'CA', 'Tinnemaha Rd., Big Pine, CA 93513', $checking, 0, 0, '2026-10-01', null, null);
        $this->stay($userId, $now, 'Longstreet Hotel Casino & RV Resort', null, '2026-10-04', '2026-10-05', '36.41090', '-116.42255', 'Amargosa Valley', 'NV', 'Amargosa Valley, NV', $checking, 0, 0, '2026-10-04', null, null);
        $this->stay($userId, $now, 'Oasis Las Vegas RV Resort', null, '2026-10-05', '2026-10-09', '36.04268', '-115.17677', 'Las Vegas', 'NV', 'Las Vegas, NV', $checking, 0, 0, '2026-10-05', null, null);
        $this->stay($userId, $now, 'Lake Mead RV Village at Echo Bay', 'SPOT 7', '2026-10-09', '2026-10-13', '36.31020', '-114.43205', 'Overton', 'NV', 'Valley of Fire Hwy, Overton, NV 89040', $checking, 0, 0, '2026-10-09', null, null);
        $this->stay($userId, $now, 'Ely KOA Journey', 'STE 23', '2026-10-13', '2026-10-15', '39.21545', '-114.85431', 'Ely', 'NV', '1596 S Hwy 93, Ely, NV 89301', $checking, 0, 0, '2026-10-13', null, null);
        $this->stay($userId, $now, 'Twin Falls / Jerome KOA Holiday', 'STE 15', '2026-10-15', '2026-10-21', '42.66002', '-114.44472', 'Jerome', 'ID', '441 S Liberty Lane, Jerome, ID 83338', $checking, 0, 0, '2026-10-15', null, null);
        $this->stay($userId, $now, 'Ely KOA Journey', null, '2026-10-21', '2026-10-23', '39.21545', '-114.85431', 'Ely', 'NV', '1596 S Hwy 93, Ely, NV 89301', $checking, 0, 0, '2026-10-21', null, null);
        $this->stay($userId, $now, 'Las Vegas RV Resort', 'Site 314', '2026-10-23', '2026-10-24', '36.1202672', '-115.0620385', 'Las Vegas', 'NV', '3890 South Nellis Boulevard, Las Vegas, NV 89121', $card, 86124, 0, '2026-10-23', null, null);
        $this->stay($userId, $now, 'IBM TechXchange 2026', 'Georgia World Conference', '2026-10-24', '2026-10-28', '33.7649102', '-84.3893243', 'Atlanta', 'GA', '45 Ivan Allen Junior Boulevard, Atlanta, GA 30308', $checking, 0, 0, '2026-10-24', null, null, 'fly');
        $vehicle = $this->vehicle($userId, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-09-15', 22000, 64400, 550, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-10-01', 6000, 64800, 550, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-10-01', 4000, 64800, 550, $now);
        $this->fuelBill($cash, $fuel, null, '2026-10-15', 5000, null, null, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-07', 12200, 64900, 550, $now);
    }

    private function stay(
        int $userId,
        string $now,
        string $place,
        ?string $note,
        string $arrive,
        string $depart,
        string $latitude,
        string $longitude,
        string $city,
        string $region,
        string $address,
        int $accountId,
        int $total,
        int $due,
        ?string $balanceDue,
        ?int $rating,
        ?string $comment,
        string $travel = 'drive',
    ): void {
        $next = $this->pdo->prepare('SELECT COALESCE(MAX(route_order), -1) + 1 FROM trips WHERE user_id = ?');
        $next->execute([$userId]);
        $routeOrder = (int) $next->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, note, arrive_on, depart_on, latitude, longitude,
                city, region, address, account_id, total_minor, amount_due_minor, balance_due_on,
                rating_half_stars, rating_comment, travel, route_order, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $place, $note, $arrive, $depart, $latitude, $longitude,
            $city, $region, $address, $accountId, $total, $due, $balanceDue,
            $rating, $comment, $travel, $routeOrder, $now, $now,
        ]);
        $tripId = (int) $this->pdo->lastInsertId();
        $deposit = $total - $due;
        if ($deposit > 0) {
            $this->stayLodgingBill($userId, $accountId, $tripId, $place, $deposit, $arrive, $now, 'deposit');
        }
        if ($due > 0 && $balanceDue !== null && $balanceDue !== '') {
            $this->stayLodgingBill($userId, $accountId, $tripId, $place, $due, $balanceDue, $now, 'due');
        }
    }

    /** A lodging reminder for a seeded stay. It does not move the account balance. */
    private function stayLodgingBill(
        int $userId,
        int $accountId,
        int $tripId,
        string $place,
        int $amount,
        string $dueOn,
        string $now,
        string $part,
    ): void {
        $owner = $this->pdo->prepare('SELECT currency FROM accounts WHERE id = ?');
        $owner->execute([$accountId]);
        $currency = $owner->fetchColumn();
        $currency = is_string($currency) && $currency !== '' ? $currency : 'USD';
        $bucketId = SystemBuckets::idForType($this->pdo, $userId, 'lodging');
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, autopay, status, created_at, updated_at,
                trip_id, lodging_part
             ) VALUES (?, \'lodging\', ?, ?, ?, ?, \'once\', ?, ?, 0, \'active\', ?, ?, ?, ?)'
        )->execute([$place, $accountId, $bucketId, $amount, $currency, $dueOn, $dueOn, $now, $now, $tripId, $part]);
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
             ) VALUES (\'Fill-up\', \'fuel\', ?, ?, ?, \'USD\', \'once\', ?, ?, ?, 0, \'paid\', ?, ?, ?, ?, ?)'
        )->execute([
            $accountId, $categoryId, $amount, $date, $date, $date, $now, $now, $vehicleId, $odometer, $price,
        ]);
    }

    private function seedEnvelopes(int $userId, string $now): void
    {
        $needs = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ?');
        $needs->execute([$userId, 'Road cushion']);
        $categoryId = (int) $needs->fetchColumn();
        $cycle = $this->pdo->prepare('SELECT id FROM pay_cycles WHERE user_id = ? AND payor = ?');
        $cycle->execute([$userId, 'Day job']);
        $cycleId = (int) $cycle->fetchColumn();
        $this->period($userId, $cycleId, '2026-10-01', '2026-10-31', $categoryId, 20000, $now);
        $this->period($userId, $cycleId, '2026-11-01', '2026-11-30', $categoryId, 50000, $now);
        $this->acctAssignment($userId, '2026-10-01', $categoryId, 20000);
        $this->acctAssignment($userId, '2026-11-01', $categoryId, 50000);
        $this->pdo->prepare('UPDATE categories SET amount_minor = 50000 WHERE id = ?')->execute([$categoryId]);
        $this->pdo->prepare(
            'UPDATE bills SET category_id = ?
             WHERE name = ? AND account_id IN (SELECT id FROM accounts WHERE user_id = ?)'
        )->execute([$categoryId, 'Site week', $userId]);
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

    private function acctAssignment(int $userId, string $start, int $categoryId, int $amount): void
    {
        $this->pdo->prepare(
            'INSERT INTO acct_period_assignments (user_id, acct_period_start, acct_period_length, category_id, amount_minor)
             VALUES (?, ?, \'1 month\', ?, ?)
             ON CONFLICT(user_id, acct_period_start, acct_period_length, category_id) DO UPDATE SET amount_minor = excluded.amount_minor'
        )->execute([$userId, $start, $categoryId, $amount]);
    }

    /** @param array{name:string,type:string,side:string,currency:string,balance:int,interest?:int,minimum?:int,dueDay?:int,limit?:int,archived?:bool,autopay?:string,gasFavorite?:bool,shoppingFavorite?:bool,assetFavorite?:bool,bankAutopay?:bool,billRecurrence?:string,billEvery?:int,holder?:string,institution?:string,inBudget?:bool,liabilityBillType?:string} $row */
    private function account(int $userId, array $row, string $now): int
    {
        $this->currencies->assert($row['currency']);
        $inBudget = array_key_exists('inBudget', $row)
            ? (!empty($row['inBudget']) ? 1 : 0)
            : ($row['side'] === 'asset' && $row['type'] !== 'savings' ? 1 : 0);
        $this->pdo->prepare(
            'INSERT INTO accounts (
                user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
                due_day, credit_limit_minor, autopay_choice, bank_autopay, bill_recurrence, bill_every,
                archived_at, gas_favorite, shopping_favorite, asset_favorite, in_budget, holder,
                institution, liability_bill_type, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            !empty($row['bankAutopay']) ? 1 : 0,
            $row['billRecurrence'] ?? 'monthly',
            $row['billEvery'] ?? 1,
            !empty($row['archived']) ? $now : null,
            !empty($row['gasFavorite']) ? 1 : 0,
            !empty($row['shoppingFavorite']) ? 1 : 0,
            !empty($row['assetFavorite']) ? 1 : 0,
            $inBudget,
            $row['holder'] ?? null,
            $row['institution'] ?? null,
            $row['liabilityBillType'] ?? 'debt',
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{name:string,type:string,amount:int,recurrence:string,anchor:string,next:string,status:string,otherLabel?:string,autopay?:bool,currency?:string,paidOn?:string,every?:int,sourceAccountId?:int} $row */
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
                recurrence, every_count, anchor_date, next_due_date, last_paid_on, autopay, status, created_at, updated_at,
                source_account_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['name'],
            $row['type'],
            $row['otherLabel'] ?? null,
            $accountId,
            $bucketId,
            $row['amount'],
            $currency,
            $row['recurrence'],
            $row['every'] ?? 1,
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
             ) VALUES (?, ?, ?, ?, ?, 1, 64900, 64000, 550, ?, ?)'
        )->execute([$userId, 'Pickup', 'truck', 60, 120, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();

        $extra = $this->pdo->prepare(
            'INSERT INTO vehicles (
                user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred,
                current_odometer_miles, origin_odometer_miles, fuel_price_minor, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, 400, ?, ?)'
        );
        foreach ([
            ['Side-by-side', 'utv', 80, 160, 2400],
            ['Quad', 'atv', 120, 250, 1800],
            ['Golf cart', 'golf cart', 150, 300, 420],
            ['Dirt bike', 'dirt bike', 350, 700, 860],
        ] as [$name, $kind, $min, $max, $miles]) {
            $extra->execute([$userId, $name, $kind, $min, $max, $miles, $miles, $now, $now]);
        }
        $this->pdo->prepare(
            'INSERT INTO vehicles (
                user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred,
                current_odometer_miles, origin_odometer_miles, fuel_price_minor, archived_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, NULL, ?, ?, ?)'
        )->execute([$userId, 'Old truck', 'truck', 100, 180, 84200, 84200, $now, $now, $now]);

        return $id;
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

    private function payroll(int $cycleId, string $payday, int $amount, bool $posted = false): void
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_entries (pay_cycle_id, payday, amount_minor, posted) VALUES (?, ?, ?, ?)'
        )->execute([$cycleId, $payday, $amount, $posted ? 1 : 0]);
    }

}
