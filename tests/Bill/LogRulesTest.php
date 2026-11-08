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

    public function testALogOnALiabilityAddsTheAmountOwed(): void
    {
        $card = $this->accounts->create([
            'name' => 'Camp card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 86000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $fuel = $this->accounts->create([
            'name' => 'Fuel card',
            'type' => 'fuel',
            'currency' => 'USD',
            'balanceMinor' => 6400,
        ]);
        $vehicle = $this->vehicles->create([
            'name' => 'Vehicle 1',
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'currentOdometerMiles' => 1000,
        ]);

        $this->bills->log([
            'kind' => 'want',
            'type' => 'restaurants',
            'name' => 'Dinner 1',
            'accountId' => $card['id'],
            'amountMinor' => 2500,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        $this->bills->log([
            'kind' => 'fuel',
            'accountId' => $fuel['id'],
            'amountMinor' => 4000,
            'vehicleId' => $vehicle['id'],
            'odometerMiles' => 1100,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);

        self::assertSame(88500, $this->accounts->get($card['id'])['balanceMinor']);
        self::assertSame(10400, $this->accounts->get($fuel['id'])['balanceMinor']);
        self::assertSame(100000, $this->balance());
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

    public function testAOneOffArchivesWithoutPausingAndDeleteRemovesIt(): void
    {
        $once = $this->bills->create([
            'name' => 'One dinner',
            'type' => 'restaurants',
            'accountId' => $this->checkingId,
            'amountMinor' => 1500,
            'currency' => 'USD',
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-12',
            'today' => '2026-11-05',
        ]);
        try {
            $this->bills->pause($once['id'], ['today' => '2026-11-05']);
            self::fail('A one-off was paused.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        $archived = $this->bills->archiveBill($once['id'], ['today' => '2026-11-05']);
        self::assertSame('archived', $archived['status']);
        self::assertSame('active', $this->bills->unarchiveBill($archived['id'], ['today' => '2026-11-05'])['status']);
        try {
            $this->bills->deleteBill($once['id'], true, ['today' => '2026-11-05']);
            self::fail('An active bill was deleted.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        $this->bills->archiveBill($once['id'], ['today' => '2026-11-05']);
        try {
            $this->bills->deleteBill($once['id'], false, ['today' => '2026-11-05']);
            self::fail('Delete ran without a confirmation.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        $this->bills->deleteBill($once['id'], true, ['today' => '2026-11-05']);
        try {
            $this->bills->get($once['id']);
            self::fail('The archived bill was still stored.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testANeedLogOmitsFuelAndASavingLogFilesOnItsBucket(): void
    {
        $before = $this->balance();
        try {
            $this->bills->log([
                'kind' => 'need',
                'type' => 'fuel',
                'name' => 'Fill 1',
                'accountId' => $this->checkingId,
                'amountMinor' => 1000,
                'date' => '2026-11-05',
                'today' => '2026-11-05',
            ]);
            self::fail('A need log accepted Fuel.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Choose a needs type.', $e->fields['type']);
        }
        try {
            $this->bills->log([
                'kind' => 'saving',
                'type' => 'restaurants',
                'name' => 'Dinner 2',
                'accountId' => $this->checkingId,
                'amountMinor' => 1000,
                'date' => '2026-11-05',
                'today' => '2026-11-05',
            ]);
            self::fail('A saving log accepted a wants type.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Choose a savings type.', $e->fields['type']);
        }

        $groceries = $this->bills->log([
            'kind' => 'need',
            'type' => 'groceries',
            'name' => 'Market 1',
            'accountId' => $this->checkingId,
            'amountMinor' => 1800,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        self::assertSame('active', $groceries['status']);
        self::assertNull($groceries['lastPaidOn']);
        self::assertSame('groceries', $groceries['type']);
        self::assertSame('Groceries', $groceries['categoryName']);
        self::assertSame($before, $this->balance());

        $debt = $this->bills->log([
            'kind' => 'saving',
            'type' => 'debt',
            'name' => 'Payment 1',
            'accountId' => $this->checkingId,
            'amountMinor' => 900,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        self::assertSame('active', $debt['status']);
        self::assertNull($debt['lastPaidOn']);
        self::assertSame('debt', $debt['type']);
        self::assertSame('Debt', $debt['categoryName']);
        self::assertSame($before, $this->balance());
    }

    public function testALockedBillDrawsFromTheStarredAccount(): void
    {
        $checking = $this->accounts->create([
            'name' => 'Everyday',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'starred' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Card star',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 50000,
            'minimumDueMinor' => 2500,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
        ]);
        $bill = $this->named('Card star');
        self::assertTrue($bill['locked']);
        self::assertSame($checking['id'], $bill['accountId']);
        self::assertSame($card['id'], $bill['sourceAccountId']);

        $wallet = $this->accounts->create([
            'name' => 'Wallet star',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'starred' => true,
        ]);
        self::assertSame($wallet['id'], $this->named('Card star')['accountId']);

        $this->accounts->update($wallet['id'], [
            'name' => 'Wallet star',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'starred' => false,
        ]);
        self::assertSame($card['id'], $this->named('Card star')['accountId']);
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

    public function testEditingAFuelEntryUpdatesTheReadingAndDeletingItRemovesTheBill(): void
    {
        $vehicle = $this->vehicles->create([
            'name' => 'Vehicle 1',
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'currentOdometerMiles' => 1000,
        ]);
        $before = $this->balance();
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
        $id = (int) $logged['id'];
        try {
            $this->bills->updateFuel($id, [
                'odometerMiles' => -1,
                'amountMinor' => 0,
                'pricePerGallonMinor' => 0,
                'today' => '2026-11-05',
            ]);
            self::fail('An invalid fuel edit was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('odometerMiles', $e->fields);
            self::assertArrayHasKey('amountMinor', $e->fields);
            self::assertArrayHasKey('pricePerGallonMinor', $e->fields);
        }
        self::assertSame(4000, $this->bills->get($id)['amountMinor']);
        self::assertSame(1100, $this->bills->get($id)['odometerMiles']);
        self::assertSame(400, $this->bills->get($id)['pricePerGallonMinor']);
        self::assertSame($before, $this->balance());

        $this->bills->markPaid($id, ['paidOn' => '2026-11-05']);
        $lowered = $this->bills->updateFuel($id, [
            'odometerMiles' => 1050,
            'amountMinor' => 2000,
            'pricePerGallonMinor' => 500,
            'today' => '2026-11-05',
        ]);
        self::assertSame(2000, $lowered['amountMinor']);
        self::assertSame(1050, $lowered['odometerMiles']);
        self::assertSame(500, $lowered['pricePerGallonMinor']);
        self::assertSame('paid', $lowered['status']);
        self::assertSame($before, $this->balance());
        $past = $this->vehicles->get($vehicle['id']);
        self::assertSame(1100, $past['currentOdometerMiles']);
        self::assertSame(1050, $past['readings'][0]['odometerMiles']);
        self::assertSame(2000, $past['readings'][0]['amountMinor']);
        self::assertSame(500, $past['readings'][0]['pricePerGallonMinor']);
        self::assertSame(50, $past['readings'][0]['miles']);
        self::assertSame('12.5', $past['readings'][0]['milesPerGallon']);

        $raised = $this->bills->updateFuel($id, [
            'odometerMiles' => 1200,
            'amountMinor' => 2000,
            'pricePerGallonMinor' => 500,
            'today' => '2026-11-05',
        ]);
        self::assertSame(1200, $raised['odometerMiles']);
        $advanced = $this->vehicles->get($vehicle['id']);
        self::assertSame(1200, $advanced['currentOdometerMiles']);
        self::assertSame(200, $advanced['readings'][0]['miles']);
        self::assertSame('50.0', $advanced['readings'][0]['milesPerGallon']);
        self::assertSame($before, $this->balance());

        $cleared = $this->bills->updateFuel($id, [
            'odometerMiles' => 1200,
            'amountMinor' => 2000,
            'pricePerGallonMinor' => null,
            'today' => '2026-11-05',
        ]);
        self::assertNull($cleared['pricePerGallonMinor']);
        self::assertNull($this->vehicles->get($vehicle['id'])['readings'][0]['milesPerGallon']);

        try {
            $this->bills->deleteFuel($id, false, ['today' => '2026-11-05']);
            self::fail('A fuel entry was deleted without confirmation.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(2000, $this->bills->get($id)['amountMinor']);

        $rent = $this->bills->create([
            'name' => 'Rent 1',
            'type' => 'rent',
            'accountId' => $this->checkingId,
            'amountMinor' => 1000,
            'currency' => 'USD',
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-11-20',
            'today' => '2026-11-05',
        ]);
        try {
            $this->bills->deleteFuel((int) $rent['id'], true, ['today' => '2026-11-05']);
            self::fail('A rent bill was deleted with the fuel entry.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame('Rent 1', $this->bills->get((int) $rent['id'])['name']);

        $this->bills->deleteFuel($id, true, ['today' => '2026-11-05']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM bills WHERE id = ?');
        $count->execute([$id]);
        self::assertSame(0, (int) $count->fetchColumn());
        try {
            $this->bills->get($id);
            self::fail('The deleted fuel bill was still on the books.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        $gone = $this->vehicles->get($vehicle['id']);
        self::assertSame([], $gone['readings']);
        self::assertSame(1200, $gone['currentOdometerMiles']);
        self::assertSame($before, $this->balance());
        self::assertSame('Rent 1', $this->bills->get((int) $rent['id'])['name']);
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
