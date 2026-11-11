<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Database\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class FuelHistoryMigrationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testFuelPostingsBecomePaidBillsAndBalancesStayTyped(): void
    {
        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $dir = dirname(__DIR__, 2) . '/db/migrations';
        $files = glob($dir . '/*.sql');
        self::assertIsArray($files);
        sort($files);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($files as $file) {
            $base = basename($file);
            if ($base === '024_dollars_drop_postings.sql' || $base === '025_locked_buckets_fuel_payroll.sql' || $base === '032_vehicle_kinds.sql' || $base === '033_stay_bill.sql' || $base === '034_account_star.sql' || $base === '035_bill_every.sql' || $base === '036_liability_schedule.sql' || $base === '039_account_in_budget.sql' || $base === '040_account_holder.sql' || $base === '041_payroll_posted.sql' || $base === '043_account_favorites.sql' || $base === '044_drop_account_star.sql' || $base === '047_vehicle_archive.sql' || $base === '048_account_liability_bill_type.sql' || $base === '049_bills_trip_fuel.sql' || $base === '050_bills_lodging_part.sql' || $base === '051_bill_paid_on_due.sql') {
                continue;
            }
            $sql = file_get_contents($file);
            self::assertIsString($sql);
            $parts = preg_split('/;\s*(?:\R|$)/', trim($sql));
            self::assertIsArray($parts);
            foreach ($parts as $part) {
                $statement = trim($part);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        }
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'USD\', \'dark\', \'owner\', ?)'
        )->execute(['Ada', password_hash('secret', PASSWORD_DEFAULT), $now]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO accounts (user_id, name, type, side, currency, balance_minor, created_at, updated_at)
             VALUES (?, \'Cash 2\', \'cash\', \'asset\', \'JPY\', 100000, ?, ?)'
        )->execute([$userId, $now, $now]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
             VALUES (?, \'Fuel Bucket\', \'needs\', 0, \'cover\', \'fuel\', ?, ?)'
        )->execute([$userId, $now, $now]);
        $fuelId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
             VALUES (?, \'Needs 1\', \'needs\', 0, \'cover\', \'goal\', ?, ?)'
        )->execute([$userId, $now, $now]);
        $goalId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO postings (
                user_id, account_id, category_id, amount_minor, direction, payee, posted_on,
                odometer_miles, price_per_gallon_minor, created_at, updated_at
             ) VALUES (?, ?, ?, 4000, \'out\', \'Fuel 1\', \'2026-10-01\', 10000, 400, ?, ?)'
        )->execute([$userId, $accountId, $fuelId, $now, $now]);
        $pdo->prepare(
            'INSERT INTO postings (
                user_id, account_id, category_id, amount_minor, direction, payee, posted_on, created_at, updated_at
             ) VALUES (?, ?, ?, 1500, \'out\', \'Payee 1\', \'2026-10-02\', ?, ?)'
        )->execute([$userId, $accountId, $goalId, $now, $now]);
        $pdo->prepare(
            'INSERT INTO exchange_rates (user_id, base_currency, currency, rate, created_at, updated_at)
             VALUES (?, \'USD\', \'JPY\', \'0.0067\', ?, ?)'
        )->execute([$userId, $now, $now]);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (id TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );
        $record = $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (?, ?)');
        foreach ($files as $file) {
            $id = basename($file);
            if ($id === '024_dollars_drop_postings.sql' || $id === '025_locked_buckets_fuel_payroll.sql' || $id === '032_vehicle_kinds.sql' || $id === '033_stay_bill.sql' || $id === '034_account_star.sql' || $id === '035_bill_every.sql' || $id === '036_liability_schedule.sql' || $id === '039_account_in_budget.sql' || $id === '040_account_holder.sql' || $id === '041_payroll_posted.sql' || $id === '043_account_favorites.sql' || $id === '044_drop_account_star.sql' || $id === '047_vehicle_archive.sql' || $id === '048_account_liability_bill_type.sql' || $id === '049_bills_trip_fuel.sql' || $id === '050_bills_lodging_part.sql' || $id === '051_bill_paid_on_due.sql') {
                continue;
            }
            $record->execute([$id, $now]);
        }
        unset($pdo);

        $pdo = Database::connect($this->path, $dir);
        $account = $pdo->prepare('SELECT balance_minor, currency FROM accounts WHERE user_id = ? AND name = \'Cash 2\'');
        $account->execute([$userId]);
        $stored = $account->fetch();
        self::assertIsArray($stored);
        self::assertSame(100000, (int) $stored['balance_minor']);
        self::assertSame('USD', $stored['currency']);
        $bills = $pdo->prepare(
            'SELECT b.name, b.type, b.status, b.amount_minor, b.currency, b.odometer_miles, b.price_per_gallon_minor
             FROM bills b JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? ORDER BY b.name'
        );
        $bills->execute([$userId]);
        $rows = $bills->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('Fuel 1', $rows[0]['name']);
        self::assertSame('fuel', $rows[0]['type']);
        self::assertSame('paid', $rows[0]['status']);
        self::assertSame(4000, (int) $rows[0]['amount_minor']);
        self::assertSame('USD', $rows[0]['currency']);
        self::assertSame(10000, (int) $rows[0]['odometer_miles']);
        self::assertSame(400, (int) $rows[0]['price_per_gallon_minor']);
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('postings', 'posting_lines', 'exchange_rates')"
        )->fetchColumn());
    }
}
