<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class MarkPaidTest extends TestCase
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

    public function testMarkPaidRollsTheDateAndLeavesTheBalance(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 25000,
        ]);
        $bill = $this->bills->create([
            'name' => 'Rent',
            'type' => 'rent',
            'accountId' => $account['id'],
            'amountMinor' => 8000,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-01-31',
        ]);

        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-01-31']);
        $again = $this->accounts->get($account['id']);

        self::assertSame('2026-02-28', $paid['nextDueDate']);
        self::assertSame('2026-01-31', $paid['lastPaidOn']);
        self::assertSame('active', $paid['status']);
        self::assertSame(8000, $this->bills->paidMinor());
        self::assertSame(25000, $again['balanceMinor']);

        $undone = $this->bills->unpay($bill['id']);
        self::assertSame('2026-01-31', $undone['nextDueDate']);
        self::assertNull($undone['lastPaidOn']);
        self::assertSame('active', $undone['status']);
        self::assertSame(0, $this->bills->paidMinor());
        self::assertSame('Rent', $this->bills->get($bill['id'])['name']);
        self::assertSame(25000, $this->accounts->get($account['id'])['balanceMinor']);

        try {
            $this->bills->unpay($bill['id']);
            self::fail('Only the latest paid date can be cleared.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testARepeatingPaymentStaysOnTheDueDateItHad(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 25000,
        ]);
        $bill = $this->bills->create([
            'name' => 'Rent',
            'type' => 'rent',
            'accountId' => $account['id'],
            'amountMinor' => 8000,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-01-31',
        ]);

        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-02-02']);

        self::assertSame('2026-01-31', $paid['lastPaidOn']);
        self::assertSame('2026-02-28', $paid['nextDueDate']);
        self::assertSame('active', $paid['status']);
        self::assertSame(8000, $this->bills->paidMinor());

        $undone = $this->bills->unpay($bill['id']);
        self::assertSame('2026-01-31', $undone['nextDueDate']);
        self::assertNull($undone['lastPaidOn']);
        self::assertSame(0, $this->bills->paidMinor());
        self::assertSame($bill['id'], $this->bills->get($bill['id'])['id']);
    }

    public function testUnpayPutsAOneOffBackOnTheDueList(): void
    {
        $account = $this->accounts->create([
            'name' => 'Cash',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 100,
        ]);
        $bill = $this->bills->create([
            'name' => 'Visa fee',
            'type' => 'other',
            'otherLabel' => 'Visa',
            'accountId' => $account['id'],
            'amountMinor' => 8000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-04-01',
        ]);
        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-04-01']);
        self::assertSame('paid', $paid['status']);
        self::assertSame('2026-04-01', $paid['nextDueDate']);
        self::assertSame(8000, $this->bills->paidMinor());

        $undone = $this->bills->unpay($bill['id']);
        self::assertSame('active', $undone['status']);
        self::assertSame('2026-04-01', $undone['nextDueDate']);
        self::assertNull($undone['lastPaidOn']);
        self::assertSame(0, $this->bills->paidMinor());
        self::assertSame(100, $this->accounts->get($account['id'])['balanceMinor']);
    }
}
