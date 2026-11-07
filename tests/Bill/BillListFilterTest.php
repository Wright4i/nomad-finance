<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillListFilter;
use App\Bill\BillService;
use App\Database\Database;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillListFilterTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private BillService $bills;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        DemoSession::start($this->pdo);
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->bills = new BillService($this->pdo, $catalog, $actor);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAmountMatchesFromTheLeftAndABlankFilterKeepsTheBill(): void
    {
        $bill = [
            'name' => 'Registration',
            'accountId' => 4,
            'type' => 'vehicle',
            'amountMinor' => 12000,
        ];

        self::assertTrue(BillListFilter::matches($bill, []));
        self::assertTrue(BillListFilter::amountMatches(12000, '120'));
        self::assertTrue(BillListFilter::amountMatches(12000, '$120.00'));
        self::assertTrue(BillListFilter::amountMatches(1250, '12.5'));
        self::assertFalse(BillListFilter::amountMatches(1800, '8'));
        self::assertFalse(BillListFilter::amountMatches(12000, 'rent'));
        self::assertTrue(BillListFilter::matches($bill, ['name' => 'reg']));
        self::assertFalse(BillListFilter::matches($bill, ['name' => 'rent']));
        self::assertFalse(BillListFilter::matches($bill, ['accountId' => 9]));
        self::assertFalse(BillListFilter::matches($bill, ['type' => 'rent']));
    }

    public function testAFilterFindsABillOutsideTheFirstDays(): void
    {
        $checking = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 25000,
        ]);
        $wallet = $this->accounts->create([
            'name' => 'Wallet',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 1000,
        ]);
        $this->bills->create([
            'name' => 'Rent',
            'type' => 'rent',
            'accountId' => $checking['id'],
            'amountMinor' => 8000,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-01-01',
        ]);
        $this->bills->create([
            'name' => 'Groceries',
            'type' => 'groceries',
            'accountId' => $checking['id'],
            'amountMinor' => 4500,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-01-03',
        ]);
        foreach (['Water', 'Power', 'Phone', 'Trash', 'Gas', 'Tow', 'Park', 'Soap'] as $index => $name) {
            $this->bills->create([
                'name' => $name,
                'type' => 'other',
                'otherLabel' => $name,
                'accountId' => $checking['id'],
                'amountMinor' => 1000 + $index,
                'recurrence' => 'monthly',
                'nextDueDate' => '2026-01-0' . (string) (($index % 6) + 1),
            ]);
        }
        $this->bills->create([
            'name' => 'Registration',
            'type' => 'vehicle',
            'accountId' => $checking['id'],
            'amountMinor' => 12000,
            'recurrence' => 'yearly',
            'every' => 2,
            'nextDueDate' => '2027-06-15',
        ]);

        $open = array_column($this->bills->section('active', 1)['bills'], 'name');
        self::assertContains('Rent', $open);
        self::assertNotContains('Registration', $open);

        self::assertSame(['Registration'], array_column($this->bills->section('active', 1, ['name' => 'reg'])['bills'], 'name'));
        self::assertSame(['Registration'], array_column($this->bills->section('active', 1, ['type' => 'vehicle'])['bills'], 'name'));
        self::assertSame(['Registration'], array_column($this->bills->section('active', 1, ['amount' => '120'])['bills'], 'name'));
        self::assertSame(
            ['Groceries'],
            array_column($this->bills->section('active', 1, ['accountId' => $checking['id'], 'name' => 'Gro'])['bills'], 'name'),
        );
        self::assertSame([], $this->bills->section('active', 1, ['accountId' => $wallet['id']])['bills']);
    }
}
