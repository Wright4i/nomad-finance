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
        // Solo full-timer in a Class C. Three paychecks are live. The other pay lengths stay archived so Show archived still has one of each.
        $checking = $this->account($userId, [
            'name' => 'Everyday checking', 'type' => 'checking', 'side' => 'asset', 'currency' => 'USD', 'balance' => 318000, 'holder' => 'Demo',
        ], $now);
        $this->account($userId, [
            'name' => 'Closed checking', 'type' => 'checking', 'side' => 'asset', 'currency' => 'USD', 'balance' => 1840, 'archived' => true,
        ], $now);
        $savings = $this->account($userId, [
            'name' => 'Emergency fund', 'type' => 'savings', 'side' => 'asset', 'currency' => 'USD', 'balance' => 860000, 'interest' => 450, 'assetFavorite' => true,
        ], $now);
        $this->account($userId, [
            'name' => 'Rig fund', 'type' => 'savings', 'side' => 'asset', 'currency' => 'USD', 'balance' => 145000,
        ], $now);
        $cash = $this->account($userId, [
            'name' => 'Wallet', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 8600,
        ], $now);
        $this->account($userId, [
            'name' => 'Camp jar', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 14000,
        ], $now);
        $this->account($userId, [
            'name' => 'Glovebox', 'type' => 'cash', 'side' => 'asset', 'currency' => 'USD', 'balance' => 4500,
        ], $now);
        $card = $this->account($userId, [
            'name' => 'Travel card', 'type' => 'credit', 'side' => 'liability', 'currency' => 'USD', 'balance' => 86000, 'shoppingFavorite' => true,
            'interest' => 1999, 'minimum' => 3500, 'dueDay' => 15, 'limit' => 600000, 'autopay' => 'minimum', 'bankAutopay' => true,
        ], $now);
        $loan = $this->account($userId, [
            'name' => 'Solar loan', 'type' => 'loan', 'side' => 'liability', 'currency' => 'USD', 'balance' => 245000,
            'interest' => 699, 'minimum' => 8500, 'dueDay' => 1, 'autopay' => 'balance', 'bankAutopay' => true,
        ], $now);
        $fuelCard = $this->account($userId, [
            'name' => 'Fuel card', 'type' => 'fuel', 'side' => 'liability', 'currency' => 'USD', 'balance' => 6400, 'gasFavorite' => true,
            'interest' => 0, 'minimum' => 2500, 'dueDay' => 20, 'limit' => 50000,
            'autopay' => 'minimum', 'bankAutopay' => true, 'billRecurrence' => 'daily', 'billEvery' => 2,
        ], $now);
        $otherAsset = $this->account($userId, [
            'name' => 'Solar kit', 'type' => 'other', 'side' => 'asset', 'currency' => 'USD', 'balance' => 185000,
        ], $now);
        $this->account($userId, [
            'name' => 'Camp tab', 'type' => 'other', 'side' => 'liability', 'currency' => 'USD', 'balance' => 8500,
        ], $now);

        $this->bill($checking, [
            'name' => 'Site week', 'type' => 'rent', 'amount' => 38500,
            'recurrence' => 'once', 'anchor' => '2026-11-10', 'next' => '2026-11-10', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Shore power', 'type' => 'electricity', 'amount' => 1800,
            'recurrence' => 'weekly', 'anchor' => '2026-11-01', 'next' => '2026-11-08', 'status' => 'active',
        ], $now);
        $this->bill($card, [
            'name' => 'Streaming', 'type' => 'subscriptions', 'amount' => 1599,
            'recurrence' => 'monthly', 'anchor' => '2026-11-15', 'next' => '2026-11-15', 'status' => 'active', 'autopay' => true,
        ], $now);
        $this->bill($checking, [
            'name' => 'Health share', 'type' => 'healthcare', 'amount' => 216000,
            'recurrence' => 'yearly', 'anchor' => '2026-11-20', 'next' => '2026-11-20', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Registration', 'type' => 'vehicle', 'amount' => 12000,
            'recurrence' => 'yearly', 'every' => 2, 'anchor' => '2027-06-15', 'next' => '2027-06-15', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Estimated tax', 'type' => 'debt', 'amount' => 22000,
            'recurrence' => 'monthly', 'anchor' => '2026-12-01', 'next' => '2026-12-01', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Phone', 'type' => 'cellular', 'amount' => 5500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-12', 'next' => '2026-11-12', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Clinic visit', 'type' => 'healthcare', 'amount' => 14000,
            'recurrence' => 'once', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'DEF fluid', 'type' => 'vehicle', 'amount' => 2800,
            'recurrence' => 'monthly', 'anchor' => '2026-11-08', 'next' => '2026-11-08', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Park pass', 'type' => 'entertainment', 'amount' => 8000,
            'recurrence' => 'yearly', 'anchor' => '2026-12-05', 'next' => '2026-12-05', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Camp store', 'type' => 'shopping', 'amount' => 2200,
            'recurrence' => 'weekly', 'anchor' => '2026-11-06', 'next' => '2026-11-06', 'status' => 'active',
        ], $now);
        $this->bill($cash, [
            'name' => 'Shower tokens', 'type' => 'other', 'otherLabel' => 'Shower tokens', 'amount' => 800,
            'recurrence' => 'once', 'anchor' => '2026-11-18', 'next' => '2026-11-18', 'status' => 'active',
        ], $now);
        $this->bill($otherAsset, [
            'name' => 'October site', 'type' => 'rent', 'amount' => 42000,
            'recurrence' => 'monthly', 'anchor' => '2026-10-15', 'next' => '2026-10-15', 'status' => 'active',
        ], $now);
        $this->bill($savings, [
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
        $this->bill($savings, [
            'name' => 'Travel card', 'type' => 'debt', 'amount' => 3500,
            'recurrence' => 'monthly', 'anchor' => '2026-11-15', 'next' => '2026-11-15', 'status' => 'active',
            'sourceAccountId' => $card,
        ], $now);
        $this->bill($savings, [
            'name' => 'Solar loan', 'type' => 'debt', 'amount' => 245000,
            'recurrence' => 'monthly', 'anchor' => '2026-12-01', 'next' => '2026-12-01', 'status' => 'active',
            'sourceAccountId' => $loan,
        ], $now);
        $this->bill($savings, [
            'name' => 'Fuel card', 'type' => 'fuel', 'amount' => 2500,
            'recurrence' => 'daily', 'every' => 2, 'anchor' => '2027-06-15', 'next' => '2027-06-15', 'status' => 'active', 'autopay' => true,
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
            'name' => 'Fresh water', 'type' => 'water', 'amount' => 1200,
            'recurrence' => 'monthly', 'anchor' => '2026-11-09', 'next' => '2026-11-09', 'status' => 'active',
        ], $now);
        $this->bill($checking, [
            'name' => 'Starlink', 'type' => 'internet', 'amount' => 12000,
            'recurrence' => 'monthly', 'anchor' => '2026-11-11', 'next' => '2026-11-11', 'status' => 'active',
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
            'name' => 'America the Beautiful', 'type' => 'travel', 'amount' => 8000,
            'recurrence' => 'yearly', 'anchor' => '2026-11-19', 'next' => '2026-11-19', 'status' => 'active',
        ], $now);
        foreach ([
            'propane' => ['Propane', 3500],
            'maintenance' => ['Rig service', 4500],
            'insurance' => ['RV insurance', 14500],
            'roadside' => ['Roadside', 900],
            'mailbox' => ['Mailbox', 1500],
            'laundry' => ['Laundry', 1600],
            'mortgage' => ['Family lot', 17500],
            'storage' => ['Storage unit', 8900],
            'memberships' => ['Camp club', 600],
            'software' => ['Map app', 500],
            'pets' => ['Dog food', 4800],
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
            'name' => 'Camp soap', 'type' => 'misc', 'otherLabel' => 'Camp soap', 'amount' => 800,
            'recurrence' => 'monthly', 'anchor' => '2026-11-21', 'next' => '2026-11-21', 'status' => 'active',
        ], $now);

        $this->category($userId, 'Road cushion', 'needs', $now);
        $this->category($userId, 'Fun money', 'wants', $now);
        $this->category($userId, 'Next rig', 'savings', $now);
        $this->category($userId, 'Tires', 'savings', $now);
        $this->category($userId, 'Repair reserve', 'savings', $now);
        $this->category($userId, 'Old gym', 'needs', $now, true);
        $this->payCycle($userId, 'Camp host', '2026-11-01', 'weekly', $now, null, null, false, 'hourly', 1600, 1200);
        $this->payCycle($userId, 'Market booth', '2026-11-01', 'biweekly', $now, null, null, true, 'hourly', 1500, 800);
        $this->payCycle($userId, 'Design retainer', '2026-11-01', 'semimonthly', $now, 15, null, true, 'salary', 960000);
        $this->payCycle($userId, 'Editing days', '2026-11-01', 'semimonthly', $now, 15, null, true, 'hourly', 1200, null, 'weekdays');
        $averaged = $this->payCycle($userId, 'Client average', '2026-11-01', 'semimonthly', $now, 15, null, true, 'fixed', 10000, null, 'typed', true);
        $this->payroll($averaged, '2026-10-01', 80000);
        $this->payroll($averaged, '2026-10-15', 110000);
        $this->payroll($averaged, '2026-11-01', 95000);
        $this->payCycle($userId, 'Remote salary', '2026-11-01', 'monthly', $now, null, null, false, 'salary', 6720000);
        $this->payCycle($userId, 'Contract days', '2026-11-01', 'monthly', $now, null, null, true, 'hourly', 2000, 300, 'weekdays');
        $this->payCycle($userId, 'Photo gigs', '2026-11-01', 'irregular', $now, null, '2026-12-15', false, 'fixed', 45000);
        $this->payCycle($userId, 'Warehouse job', '2026-11-01', 'monthly', $now, null, null, true, 'fixed', 40000);
        $this->seedEnvelopes($userId, $now);
        $this->seedTargets($userId, $now);
        $this->seedLife($userId, $now);
        $calendar = $this->pdo->prepare(
            'INSERT INTO calendar_entries (user_id, text, start_on, end_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ([
            ['Grocery run in Moab', '2026-09-10', '2026-09-10'],
            ['Mail pickup in Springdale', '2026-10-08', '2026-10-09'],
            ['Dump and fill', '2026-11-05', '2026-11-06'],
            ['Thanksgiving potluck', '2026-11-26', '2026-11-29'],
            ['Solar day', '2026-12-18', '2026-12-19'],
            ['Quartzsite market', '2027-01-03', '2027-01-03'],
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
        $cycle->execute([$userId, 'Remote salary']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'needed', 120000, '2027-02-01', (int) $cycle->fetchColumn(), $now, $now]);
        $category->execute([$userId, 'Repair reserve']);
        $insert->execute([$userId, (int) $category->fetchColumn(), 'balance', 50000, null, null, $now, $now]);
    }

    private function seedLife(int $userId, string $now): void
    {
        $account = $this->pdo->prepare('SELECT id FROM accounts WHERE user_id = ? AND name = ?');
        $account->execute([$userId, 'Everyday checking']);
        $checking = (int) $account->fetchColumn();
        $account->execute([$userId, 'Wallet']);
        $cash = (int) $account->fetchColumn();
        $fuel = SystemBuckets::idForType($this->pdo, $userId, 'fuel');

        // Each stay leaves the morning the next one arrives, so every day from the first arrival through the last departure is on a site.
        $this->stay($userId, $now, 'Devils Garden', 'Site 12', '2026-09-06', '2026-09-18', '38.7834', '-109.5947', 'Moab', 'UT', 'Devils Garden Campground, Moab, UT 84532', $checking, 24000, 0, null, 9, 'Dark sky, quiet loops.', 'fly');
        $this->stay($userId, $now, 'Watchman', 'Site 7', '2026-09-18', '2026-10-02', '37.2002', '-112.9865', 'Springdale', 'UT', 'Watchman Campground, Springdale, UT 84767', $checking, 42000, 0, null, 8, 'Sites fill at dawn.');
        $this->stay($userId, $now, 'Wahweap', 'Site 22', '2026-10-02', '2026-10-16', '36.9964', '-111.4984', 'Page', 'AZ', 'Wahweap Campground, Page, AZ 86040', $checking, 36400, 0, null, 8, 'Lake wind and a dump station.');
        $this->stay($userId, $now, 'Catalina', 'Site 42', '2026-10-16', '2026-10-30', '32.4260', '-110.9080', 'Tucson', 'AZ', '11570 N Oracle Rd, Tucson, AZ 85737', $checking, 35000, 0, null, 8, 'Saguaros and a rinse station.');
        $this->stay($userId, $now, 'Usery Mountain', null, '2026-10-30', '2026-11-13', '33.4805', '-111.6135', 'Mesa', 'AZ', '3939 N Usery Pass Rd, Mesa, AZ 85207', $checking, 44800, 0, null, null, null);
        $this->stay($userId, $now, 'McDowell Mountain', 'Site 11', '2026-11-13', '2026-11-20', '33.6890', '-111.7070', 'Fountain Hills', 'AZ', '16300 N McDowell Mountain Rd, Fountain Hills, AZ 85268', $checking, 24500, 24500, '2026-11-13', null, null);
        $this->stay($userId, $now, 'Lost Dutchman', 'Site 18', '2026-11-20', '2026-11-24', '33.4812', '-111.4778', 'Apache Junction', 'AZ', '6109 N Apache Trail, Apache Junction, AZ 85119', $checking, 14000, 14000, '2026-11-22', null, null);
        $this->stay($userId, $now, 'Cattail Cove', 'Site 9', '2026-11-24', '2026-12-01', '34.3610', '-114.1630', 'Lake Havasu City', 'AZ', '1950 Cattail Cove Rd, Lake Havasu City, AZ 86406', $checking, 17500, 17500, '2026-11-24', null, null);
        $this->stay($userId, $now, 'Cottonwood', 'Site 4', '2026-12-01', '2026-12-05', '33.7410', '-115.8100', 'Joshua Tree', 'CA', 'Cottonwood Campground, Joshua Tree, CA 92277', $cash, 10000, 7500, '2026-12-01', 7, 'Warm days, clear nights.');
        $this->stay($userId, $now, 'Midland', 'South edge', '2026-12-05', '2026-12-12', '33.7480', '-114.8030', 'Blythe', 'CA', 'Midland LTVA, Blythe, CA 92225', $checking, 4000, 4000, '2026-12-05', null, null);
        $this->stay($userId, $now, 'La Posa South', 'South row', '2026-12-12', '2027-01-08', '33.6300', '-114.2150', 'Quartzsite', 'AZ', 'La Posa South LTVA, Quartzsite, AZ 85346', $checking, 18000, 18000, '2026-12-20', null, null);
        $vehicle = $this->vehicle($userId, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-09-15', 1000, 9980, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-10-01', 600, 10000, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-10-01', 400, 10000, 400, $now);
        $this->fuelBill($cash, $fuel, null, '2026-10-15', 500, null, null, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-01', 2000, 10100, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-08', 1000, 10300, 400, $now);
        $this->fuelBill($cash, $fuel, $vehicle, '2026-11-18', 1000, 10500, 400, $now);
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
        $cycle->execute([$userId, 'Remote salary']);
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

    /** @param array{name:string,type:string,side:string,currency:string,balance:int,interest?:int,minimum?:int,dueDay?:int,limit?:int,archived?:bool,autopay?:string,gasFavorite?:bool,shoppingFavorite?:bool,assetFavorite?:bool,bankAutopay?:bool,billRecurrence?:string,billEvery?:int,holder?:string} $row */
    private function account(int $userId, array $row, string $now): int
    {
        $this->currencies->assert($row['currency']);
        $this->pdo->prepare(
            'INSERT INTO accounts (
                user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
                due_day, credit_limit_minor, autopay_choice, bank_autopay, bill_recurrence, bill_every,
                archived_at, gas_favorite, shopping_favorite, asset_favorite, in_budget, holder, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $row['side'] === 'asset' && $row['type'] !== 'savings' ? 1 : 0,
            $row['holder'] ?? null,
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
             ) VALUES (?, ?, ?, ?, ?, 1, 10500, 9800, 400, ?, ?)'
        )->execute([$userId, 'Bunkhouse', 'rv', 60, 120, $now, $now]);
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

    private function payroll(int $cycleId, string $payday, int $amount): void
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_entries (pay_cycle_id, payday, amount_minor) VALUES (?, ?, ?)'
        )->execute([$cycleId, $payday, $amount]);
    }

}
