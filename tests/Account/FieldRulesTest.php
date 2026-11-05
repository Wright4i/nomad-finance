<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class FieldRulesTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $this->accounts = new AccountService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCheckingDropsLiabilityFields(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'minimumDueMinor' => 50,
            'dueDay' => 3,
            'creditLimitMinor' => 500,
        ]);

        self::assertSame('asset', $account['side']);
        self::assertNull($account['minimumDueMinor']);
        self::assertNull($account['dueDay']);
        self::assertNull($account['creditLimitMinor']);
    }

    public function testCreditKeepsLiabilityFields(): void
    {
        $account = $this->accounts->create([
            'name' => 'Card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4000,
            'interestBps' => 1999,
            'minimumDueMinor' => 2500,
            'dueDay' => 15,
            'creditLimitMinor' => 200000,
        ]);

        self::assertSame('liability', $account['side']);
        self::assertSame(1999, $account['interestBps']);
        self::assertSame(2500, $account['minimumDueMinor']);
        self::assertSame(15, $account['dueDay']);
        self::assertSame(200000, $account['creditLimitMinor']);
    }

    public function testOtherRequiresASide(): void
    {
        try {
            $this->accounts->create([
                'name' => 'Brokerage',
                'type' => 'other',
                'currency' => 'USD',
                'balanceMinor' => 100,
            ]);
            self::fail('Other accounts need a side.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('side', $e->fields);
        }
    }
}
