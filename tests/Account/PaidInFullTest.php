<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Database\Database;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaidInFullTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        DemoSession::start($this->pdo);
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        new BillService($this->pdo, $catalog, $actor);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testPaidInFullCreatesNoLiabilityBill(): void
    {
        $card = $this->accounts->create([
            'name' => 'Paid card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 86000,
            'minimumDueMinor' => 3500,
            'dueDay' => 15,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
        ]);
        self::assertNull($this->sourceBill((int) $card['id']));

        $fuel = $this->accounts->create([
            'name' => 'Paid fuel',
            'type' => 'fuel',
            'currency' => 'USD',
            'balanceMinor' => 6400,
            'minimumDueMinor' => 2500,
            'dueDay' => 20,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
        ]);
        self::assertNull($this->sourceBill((int) $fuel['id']));
    }

    public function testAZeroMinimumCreatesNoBillAndThreeHundredThirteenDoes(): void
    {
        $blank = $this->accounts->create([
            'name' => 'Delta style',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4200,
            'minimumDueMinor' => 0,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
            'bankAutopay' => true,
        ]);
        self::assertNull($this->sourceBill((int) $blank['id']));

        $loan = $this->accounts->create([
            'name' => 'Student loan',
            'type' => 'loan',
            'currency' => 'USD',
            'balanceMinor' => 90000,
            'minimumDueMinor' => 0,
            'dueDay' => 1,
            'autopayChoice' => 'minimum',
            'bankAutopay' => true,
        ]);
        self::assertNull($this->sourceBill((int) $loan['id']));

        $card = $this->accounts->create([
            'name' => 'Minimum card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4200,
            'minimumDueMinor' => 313,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
            'bankAutopay' => true,
        ]);
        $bill = $this->sourceBill((int) $card['id']);
        self::assertIsArray($bill);
        self::assertSame('debt', $bill['type']);
        self::assertSame(313, (int) $bill['amount_minor']);
        self::assertSame('active', $bill['status']);
    }

    /** @return ?array<string, mixed> */
    private function sourceBill(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM bills WHERE source_account_id = ? AND status != 'hidden'");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
