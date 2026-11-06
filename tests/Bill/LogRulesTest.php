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
use App\Vehicle\VehicleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class LogRulesTest extends TestCase
{
    private PDO $pdo;

    private BillService $bills;

    private AccountService $accounts;

    private VehicleService $vehicles;

    private int $checkingId;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $catalog = new CurrencyCatalog();
        $this->bills = new BillService($this->pdo, $catalog, $actor);
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->vehicles = new VehicleService($this->pdo, $actor);
        $this->checkingId = (int) $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100000,
        ])['id'];
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAFuelLogDoesNotMoveTheBalanceAndRefusesALowerReading(): void
    {
        $vehicle = $this->vehicles->create([
            'name' => 'Vehicle 1',
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'currentOdometerMiles' => 1000,
        ]);
        $before = $this->balance();
        try {
            $this->bills->log([
                'kind' => 'fuel',
                'accountId' => $this->checkingId,
                'amountMinor' => 400,
                'vehicleId' => $vehicle['id'],
                'odometerMiles' => 900,
                'date' => '2026-11-05',
                'today' => '2026-11-05',
            ]);
            self::fail('A lower reading was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
        $logged = $this->bills->log([
            'kind' => 'fuel',
            'accountId' => $this->checkingId,
            'amountMinor' => 4000,
            'vehicleId' => $vehicle['id'],
            'odometerMiles' => 1100,
            'pricePerGallonMinor' => 400,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        self::assertSame('active', $logged['status']);
        self::assertNull($logged['lastPaidOn']);
        self::assertSame('Fuel', $logged['name']);
        self::assertSame('fuel', $logged['type']);
        self::assertFalse($logged['locked']);
        self::assertSame($before, $this->balance());
        $fed = $this->vehicles->get($vehicle['id']);
        self::assertSame(1100, $fed['currentOdometerMiles']);
        self::assertSame(400, $fed['suggestedPricePerGallonMinor']);
        self::assertSame('10.0', $fed['milesPerGallon']);
        self::assertSame(1100, $fed['readings'][0]['odometerMiles']);
        self::assertSame(100, $fed['readings'][0]['miles']);
        self::assertSame('10.0', $fed['readings'][0]['milesPerGallon']);
        $paid = $this->bills->markPaid($logged['id'], ['paidOn' => '2026-11-05']);
        self::assertSame('paid', $paid['status']);
        self::assertSame('2026-11-05', $paid['lastPaidOn']);
        self::assertSame($before, $this->balance());
        self::assertSame(1100, $this->vehicles->get($vehicle['id'])['currentOdometerMiles']);
        try {
            $this->vehicles->update($vehicle['id'], [
                'name' => 'Vehicle 1',
                'kind' => 'rv',
                'mpgMinTenths' => 60,
                'mpgMaxTenths' => 120,
                'currentOdometerMiles' => 1000,
            ]);
            self::fail('Current odometer dropped below a fuel reading.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testAWantLogFilesOnTheLockedBucketAndCanBeArchivedFromPaused(): void
    {
        $before = $this->balance();
        $logged = $this->bills->log([
            'kind' => 'want',
            'type' => 'restaurants',
            'name' => 'Dinner 1',
            'accountId' => $this->checkingId,
            'amountMinor' => 2500,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        self::assertSame('active', $logged['status']);
        self::assertNull($logged['lastPaidOn']);
        self::assertSame('Restaurants', $logged['categoryName']);
        self::assertSame($before, $this->balance());
        $paid = $this->bills->markPaid($logged['id'], ['paidOn' => '2026-11-05']);
        self::assertSame('paid', $paid['status']);
        self::assertSame($before, $this->balance());
        $active = $this->bills->create([
            'name' => 'Phone 1',
            'type' => 'cellular',
            'accountId' => $this->checkingId,
            'amountMinor' => 1000,
            'currency' => 'USD',
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-11-20',
            'today' => '2026-11-05',
        ]);
        try {
            $this->bills->archiveBill($active['id'], ['today' => '2026-11-05']);
            self::fail('An active bill was archived.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        $paused = $this->bills->pause($active['id'], ['today' => '2026-11-05']);
        $archived = $this->bills->archiveBill($paused['id'], ['today' => '2026-11-05']);
        self::assertSame('archived', $archived['status']);
        self::assertSame('paused', $this->bills->unarchiveBill($archived['id'], ['today' => '2026-11-05'])['status']);
    }

    public function testALiabilityBillHidesWhenAutopayIsOffAndStaysLocked(): void
    {
        $card = $this->accounts->create([
            'name' => 'Card 1',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 50000,
            'minimumDueMinor' => 2500,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
        ]);
        $shown = $this->named('Card 1');
        self::assertSame('debt', $shown['type']);
        self::assertTrue($shown['locked']);
        self::assertSame(2500, $shown['amountMinor']);
        try {
            $this->bills->archiveBill($shown['id'], ['today' => '2026-11-05']);
            self::fail('A liability bill was archived.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        $this->accounts->update($card['id'], [
            'name' => 'Card 1',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 50000,
            'minimumDueMinor' => 2500,
            'dueDay' => 15,
            'autopayChoice' => 'none',
        ]);
        $names = array_map(static fn (array $bill): string => (string) $bill['name'], $this->bills->list());
        self::assertNotContains('Card 1', $names);
        $hidden = $this->pdo->query("SELECT status FROM bills WHERE source_account_id = {$card['id']}")->fetchColumn();
        self::assertSame('hidden', $hidden);
    }

    /** @return array<string, mixed> */
    private function named(string $name): array
    {
        foreach ($this->bills->list() as $bill) {
            if ($bill['name'] === $name) {
                return $bill;
            }
        }
        self::fail('Missing ' . $name);
    }

    private function balance(): int
    {
        $stmt = $this->pdo->prepare('SELECT balance_minor FROM accounts WHERE id = ?');
        $stmt->execute([$this->checkingId]);

        return (int) $stmt->fetchColumn();
    }
}
