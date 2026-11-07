<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Account\LiabilityBill;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Database\Database;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class LiabilityScheduleTest extends TestCase
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

    public function testBankAutopayBuildsTheBillAndAZeroAmountLeavesTheCalendar(): void
    {
        self::assertSame('2026-11-09', LiabilityBill::nextDue('daily', 3, 1, '2026-11-06', '2026-11-06', null));
        self::assertSame('2027-11-15', LiabilityBill::nextDue('yearly', 1, 15, '2026-11-20', null, '2026-11-15'));
        self::assertSame('2027-01-15', LiabilityBill::upcoming(15, '2026-11-20', null, 2));

        $card = $this->accounts->create([
            'name' => 'Card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 0,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'billRecurrence' => 'daily',
            'billEvery' => 3,
        ]);
        self::assertNull($this->one('Card'));

        $this->accounts->update($card['id'], $this->body($card['id'], [
            'balanceMinor' => 4200,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'billRecurrence' => 'daily',
            'billEvery' => 3,
        ]));
        $bill = $this->one('Card');
        self::assertIsArray($bill);
        self::assertSame(4200, $bill['amountMinor']);
        self::assertSame('daily', $bill['recurrence']);
        self::assertSame(3, $bill['every']);
        self::assertTrue($bill['autopay']);
        self::assertSame('active', $bill['status']);
        $month = substr((string) $bill['nextDueDate'], 0, 7);
        self::assertContains('Card', array_column($this->bills->calendar($month), 'name'));

        $paid = $this->bills->markPaid((int) $bill['id'], ['paidOn' => $bill['nextDueDate']]);
        self::assertSame(
            (new \DateTimeImmutable((string) $bill['nextDueDate']))->modify('+3 days')->format('Y-m-d'),
            $paid['nextDueDate'],
        );
        $this->bills->unpay((int) $bill['id']);

        $this->accounts->update($card['id'], $this->body($card['id'], [
            'balanceMinor' => 0,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'billRecurrence' => 'daily',
            'billEvery' => 3,
        ]));
        self::assertNull($this->one('Card'));
        self::assertNotContains('Card', array_column($this->bills->calendar($month), 'name'));

        $this->accounts->update($card['id'], $this->body($card['id'], [
            'balanceMinor' => 0,
            'minimumDueMinor' => 1800,
            'dueDay' => 15,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'billRecurrence' => 'monthly',
            'billEvery' => 2,
        ]));
        $fromMinimum = $this->one('Card');
        self::assertIsArray($fromMinimum);
        self::assertSame(1800, $fromMinimum['amountMinor']);

        $this->accounts->update($card['id'], $this->body($card['id'], [
            'balanceMinor' => 0,
            'minimumDueMinor' => 1800,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
            'bankAutopay' => true,
            'billRecurrence' => 'monthly',
            'billEvery' => 2,
        ]));
        $minimum = $this->one('Card');
        self::assertIsArray($minimum);
        self::assertSame(1800, $minimum['amountMinor']);
        self::assertSame('monthly', $minimum['recurrence']);
        self::assertSame(2, $minimum['every']);
        self::assertSame('active', $minimum['status']);

        $this->accounts->update($card['id'], $this->body($card['id'], [
            'balanceMinor' => 4200,
            'minimumDueMinor' => 1800,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
            'bankAutopay' => false,
        ]));
        self::assertNull($this->one('Card'));
        $hidden = $this->pdo->query('SELECT status FROM bills WHERE source_account_id = ' . (int) $card['id'])->fetchColumn();
        self::assertSame('hidden', $hidden);
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function body(int $id, array $overrides): array
    {
        $account = $this->accounts->get($id);

        return array_merge([
            'name' => $account['name'],
            'type' => $account['type'],
            'currency' => 'USD',
            'balanceMinor' => $account['balanceMinor'],
            'minimumDueMinor' => $account['minimumDueMinor'],
            'dueDay' => $account['dueDay'],
            'autopayChoice' => $account['autopayChoice'],
            'bankAutopay' => $account['bankAutopay'],
            'billRecurrence' => $account['billRecurrence'],
            'billEvery' => $account['billEvery'],
        ], $overrides);
    }

    /** @return ?array<string, mixed> */
    private function one(string $name): ?array
    {
        foreach ($this->bills->list() as $bill) {
            if ($bill['name'] === $name) {
                return $bill;
            }
        }

        return null;
    }
}
