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

final class BillTypeTest extends TestCase
{
    private PDO $pdo;

    private BillService $bills;

    private int $accountId;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        DemoSession::start($this->pdo);
        $accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->bills = new BillService($this->pdo, $catalog, $actor);
        $account = $accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
        ]);
        $this->accountId = $account['id'];
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testOtherStoresATypedNameAndAPresetClearsIt(): void
    {
        try {
            $this->bills->create($this->bill(['type' => 'other', 'otherLabel' => '  ']));
            self::fail('Other needs a name.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('otherLabel', $e->fields);
        }

        $bill = $this->bills->create($this->bill([
            'type' => 'other',
            'otherLabel' => 'Parking',
        ]));
        self::assertSame('other', $bill['type']);
        self::assertSame('Parking', $bill['otherLabel']);

        $rent = $this->bills->update($bill['id'], $this->bill([
            'type' => 'rent',
            'otherLabel' => 'Parking',
        ]));
        self::assertSame('rent', $rent['type']);
        self::assertNull($rent['otherLabel']);

        try {
            $this->bills->create($this->bill(['type' => 'housing']));
            self::fail('The type has to be one of the preset kinds.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('type', $e->fields);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function bill(array $overrides): array
    {
        return array_merge([
            'name' => 'Scratch Bill',
            'type' => 'rent',
            'accountId' => $this->accountId,
            'amountMinor' => 800,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-11-15',
        ], $overrides);
    }
}
