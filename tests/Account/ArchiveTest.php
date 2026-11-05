<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
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

    public function testAPausedBillStillBlocksArchive(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 5000,
        ]);
        $bill = $this->bills->create([
            'name' => 'Rent',
            'type' => 'rent',
            'accountId' => $account['id'],
            'amountMinor' => 120000,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-01-31',
        ]);

        try {
            $this->accounts->archive($account['id']);
            self::fail('Archive should be refused.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }

        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-01-31']);
        self::assertSame('2026-02-28', $paid['nextDueDate']);
        $paid = $this->bills->markPaid($paid['id'], ['paidOn' => '2026-02-28']);
        self::assertSame('2026-03-31', $paid['nextDueDate']);

        $this->bills->pause($bill['id']);
        try {
            $this->accounts->archive($account['id']);
            self::fail('A paused bill should still block archive.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testOneOffPaidBillDoesNotBlockArchive(): void
    {
        $account = $this->accounts->create([
            'name' => 'Cash',
            'type' => 'cash',
            'currency' => 'EUR',
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
        self::assertNotNull($this->accounts->archive($account['id'])['archivedAt']);
    }

    public function testUnarchivePutsTheAccountBackOnTheList(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 5000,
        ]);
        $this->accounts->archive($account['id']);
        self::assertNotContains('Checking', array_column($this->accounts->list(false), 'name'));

        $restored = $this->accounts->unarchive($account['id']);
        self::assertNull($restored['archivedAt']);
        self::assertSame(5000, $restored['balanceMinor']);
        self::assertContains('Checking', array_column($this->accounts->list(false), 'name'));
    }
}
