<?php

declare(strict_types=1);

namespace App\Tests\Vehicle;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Category\CategoryService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use App\Trip\Drive;
use App\Trip\TripService;
use App\Vehicle\VehicleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class VehicleRulesTest extends TestCase
{
    private PDO $pdo;

    private string $path;

    private VehicleService $vehicles;

    private TripService $trips;

    private int $accountId;

    private int $fuelId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $catalog = new CurrencyCatalog();
        $accounts = new AccountService($this->pdo, $catalog, $actor);
        $categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
        $this->trips = new TripService($this->pdo, $actor, $catalog, new Drive(false));
        $this->vehicles = new VehicleService($this->pdo, $actor);
        $this->accountId = (int) $accounts->create([
            'name' => 'Cash 1',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 50000,
        ])['id'];
        $categories->list(false);
        $fuel = $this->pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND role = 'locked' AND bill_type = 'fuel'");
        $fuel->execute([$_SESSION['user_id']]);
        $this->fuelId = (int) $fuel->fetchColumn();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAVehicleStoresNoColor(): void
    {
        $created = $this->vehicles->create($this->vehicle('Vehicle 1', false));
        self::assertArrayNotHasKey('color', $created);
        $columns = $this->pdo->query('PRAGMA table_info(vehicles)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertIsArray($columns);
        $names = array_column($columns, 'name');
        self::assertNotContains('color', $names);
        $updated = $this->vehicles->update($created['id'], $this->vehicle('Vehicle 1', true) + ['color' => '#ff00aa']);
        self::assertArrayNotHasKey('color', $updated);
        self::assertTrue($updated['starred']);
        self::assertSame('rv', $updated['kind']);
    }

    public function testASecondStarClearsTheFirst(): void
    {
        $first = $this->vehicles->create($this->vehicle('Vehicle 1', true));
        $second = $this->vehicles->create($this->vehicle('Vehicle 2', true));
        self::assertFalse($this->vehicles->get($first['id'])['starred']);
        self::assertTrue($second['starred']);
        $again = $this->vehicles->update($first['id'], $this->vehicle('Vehicle 1', true));
        self::assertTrue($again['starred']);
        self::assertFalse($this->vehicles->get($second['id'])['starred']);
    }

    public function testTheLabelUsesTheMidpointUntilAnInRangeFillUpExists(): void
    {
        $vehicle = $this->vehicles->create(array_merge($this->vehicle('Vehicle 1', true), ['fuelPriceMinor' => 400]));
        $this->stay('Stay 1', '36.1699', '-115.1398', '2026-11-01', '2026-11-02');
        $second = $this->stay('Stay 2', '34.0522', '-118.2437', '2026-11-03', '2026-11-04');
        self::assertSame('9.0', $second['fuel']['milesPerGallon']);
        self::assertSame(400, $second['fuel']['priceMinor']);
        self::assertNotNull($second['fuel']['costMinor']);
        self::assertNull($this->listed('Vehicle 1')['milesPerGallon']);

        $this->fill(10000, 1000, $vehicle['id'], '2026-10-01');
        $this->fill(10020, 1000, $vehicle['id'], '2026-10-02');
        $this->fill(10120, 1000, $vehicle['id'], '2026-10-03');
        $opened = $this->vehicles->get($vehicle['id']);
        self::assertSame('8.0', $opened['milesPerGallon']);
        self::assertSame('8.0', $this->listed('Vehicle 1')['milesPerGallon']);
        self::assertSame([], $this->listed('Vehicle 1')['readings']);
        $labeled = $this->trips->get((int) $second['id'], '2026-11-03');
        self::assertSame('8.0', $labeled['fuel']['milesPerGallon']);
        self::assertSame(400, $labeled['fuel']['priceMinor']);
    }

    public function testAnUnpaidFillUpFeedsTheFuelLabel(): void
    {
        $vehicle = $this->vehicles->create($this->vehicle('Vehicle 1', true));
        $this->stay('Stay 1', '36.1699', '-115.1398', '2026-11-01', '2026-11-02');
        $second = $this->stay('Stay 2', '34.0522', '-118.2437', '2026-11-03', '2026-11-04');
        self::assertSame('9.0', $second['fuel']['milesPerGallon']);
        $this->fill(10000, 1000, $vehicle['id'], '2026-10-01', 'Fuel 1', 'active');
        $this->fill(10020, 1000, $vehicle['id'], '2026-10-02', 'Fuel 1', 'active');
        $this->fill(10120, 1000, $vehicle['id'], '2026-10-03', 'Fuel 1', 'active');
        $labeled = $this->trips->get((int) $second['id'], '2026-11-03');
        self::assertSame('8.0', $labeled['fuel']['milesPerGallon']);
        self::assertCount(3, $this->vehicles->get($vehicle['id'])['readings']);
    }

    public function testTheFuelLogListsTheNewestFillUpFirst(): void
    {
        $vehicle = $this->vehicles->create($this->vehicle('Vehicle 1', true));
        $newest = $this->fill(10500, 1000, $vehicle['id'], '2026-11-18', 'Alpha');
        $earlierSameDay = $this->fill(10400, 1000, $vehicle['id'], '2026-11-08', 'Middle');
        $laterSameDay = $this->fill(10300, 1000, $vehicle['id'], '2026-11-08', 'Zebra');
        $readings = $this->vehicles->get($vehicle['id'])['readings'];
        self::assertSame(
            [
                ['postedOn' => '2026-11-18', 'id' => $newest, 'odometerMiles' => 10500],
                ['postedOn' => '2026-11-08', 'id' => $laterSameDay, 'odometerMiles' => 10300],
                ['postedOn' => '2026-11-08', 'id' => $earlierSameDay, 'odometerMiles' => 10400],
            ],
            array_map(
                static fn (array $reading): array => [
                    'postedOn' => $reading['postedOn'],
                    'id' => $reading['id'],
                    'odometerMiles' => $reading['odometerMiles'],
                ],
                $readings,
            ),
        );
        $byOdometer = [];
        foreach ($readings as $reading) {
            $byOdometer[$reading['odometerMiles']] = $reading;
        }
        self::assertSame(100, $byOdometer[10400]['miles']);
        self::assertSame('40.0', $byOdometer[10400]['milesPerGallon']);
        self::assertSame(100, $byOdometer[10500]['miles']);
        self::assertSame('40.0', $byOdometer[10500]['milesPerGallon']);
    }

    public function testTwoChargesOnOneDateAreSeparateRowsAndTheLaterChargeHasNoStretch(): void
    {
        $vehicle = $this->vehicles->create($this->vehicle('Vehicle 1', true));
        $this->fill(10000, 1000, $vehicle['id'], '2026-10-01');
        $earlier = $this->fill(10100, 1000, $vehicle['id'], '2026-11-01', 'Fuel 1');
        $later = $this->fill(10100, 2000, $vehicle['id'], '2026-11-01', 'Fuel 2');
        $rows = array_values(array_filter(
            $this->vehicles->get($vehicle['id'])['readings'],
            static fn (array $reading): bool => $reading['postedOn'] === '2026-11-01',
        ));
        self::assertCount(2, $rows);
        self::assertSame($later, $rows[0]['id']);
        self::assertSame($earlier, $rows[1]['id']);
        self::assertSame(2000, $rows[0]['amountMinor']);
        self::assertSame(1000, $rows[1]['amountMinor']);
        self::assertNull($rows[0]['miles']);
        self::assertNull($rows[0]['milesPerGallon']);
        self::assertSame(100, $rows[1]['miles']);
        self::assertSame('13.3', $rows[1]['milesPerGallon']);
    }

    public function testAMissingStarLeavesTheFuelCostEmpty(): void
    {
        $this->vehicles->create($this->vehicle('Vehicle 1', false));
        $this->stay('Stay 1', '36.1699', '-115.1398', '2026-11-01', '2026-11-02');
        $second = $this->stay('Stay 2', '34.0522', '-118.2437', '2026-11-03', '2026-11-04');
        self::assertNotNull($second['fuel']['miles']);
        self::assertNull($second['fuel']['milesPerGallon']);
        self::assertNull($second['fuel']['costMinor']);
        self::assertNull($second['fuel']['priceMinor']);
    }

    public function testThePriceUsesThatVehiclesRecentLogThenItsFallback(): void
    {
        $starred = $this->vehicles->create(array_merge($this->vehicle('Vehicle 1', true), [
            'currentOdometerMiles' => 10500,
            'fuelPriceMinor' => 399,
        ]));
        $other = $this->vehicles->create(array_merge($this->vehicle('Vehicle 2', false), [
            'currentOdometerMiles' => 200,
            'fuelPriceMinor' => 250,
        ]));
        self::assertSame(399, $starred['fuelPriceMinor']);
        self::assertSame(250, $other['fuelPriceMinor']);

        $this->priced((int) $starred['id'], '2026-11-01', 450);
        $this->priced((int) $starred['id'], '2026-11-01', 475);
        $this->priced((int) $other['id'], '2026-12-01', 510);
        $this->priced(null, '2026-12-02', 999);

        self::assertSame(475, $this->listed('Vehicle 1')['suggestedPricePerGallonMinor']);
        self::assertSame(510, $this->listed('Vehicle 2')['suggestedPricePerGallonMinor']);

        $this->stay('Stay 1', '36.1699', '-115.1398', '2026-11-01', '2026-11-02');
        $second = $this->stay('Stay 2', '34.0522', '-118.2437', '2026-11-03', '2026-11-04');
        $labeled = $this->trips->get((int) $second['id'], '2026-11-03');
        self::assertSame(475, $labeled['fuel']['priceMinor']);

        $this->pdo->prepare('DELETE FROM bills WHERE vehicle_id = ?')->execute([$starred['id']]);
        self::assertSame(399, $this->listed('Vehicle 1')['suggestedPricePerGallonMinor']);
        self::assertSame(510, $this->listed('Vehicle 2')['suggestedPricePerGallonMinor']);
        self::assertSame(399, $this->trips->get((int) $second['id'], '2026-11-03')['fuel']['priceMinor']);

        $cleared = $this->vehicles->update((int) $starred['id'], array_merge($this->vehicle('Vehicle 1', true), [
            'currentOdometerMiles' => 10500,
            'fuelPriceMinor' => null,
        ]));
        self::assertNull($cleared['fuelPriceMinor']);
        self::assertNull($cleared['suggestedPricePerGallonMinor']);
        self::assertSame(510, $this->listed('Vehicle 2')['suggestedPricePerGallonMinor']);
        self::assertNull($this->trips->get((int) $second['id'], '2026-11-03')['fuel']['priceMinor']);

        try {
            $this->vehicles->update((int) $starred['id'], array_merge($this->vehicle('Vehicle 1', true), [
                'currentOdometerMiles' => 10500,
                'fuelPriceMinor' => 0,
            ]));
            self::fail('A zero fallback was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('fuelPriceMinor', $e->fields);
        }
    }

    public function testAVehicleAcceptsEachKind(): void
    {
        foreach (['car', 'truck', 'motorcycle', 'rv', 'semi', 'utv', 'atv', 'golf cart', 'dirt bike'] as $kind) {
            $created = $this->vehicles->create(array_merge($this->vehicle('Kind ' . $kind, false), ['kind' => $kind]));
            self::assertSame($kind, $created['kind']);
        }

        $updated = $this->vehicles->update(
            (int) $this->listed('Kind rv')['id'],
            array_merge($this->vehicle('Kind rv', false), ['kind' => 'dirt bike']),
        );
        self::assertSame('dirt bike', $updated['kind']);

        try {
            $this->vehicles->create(array_merge($this->vehicle('Boat', false), ['kind' => 'boat']));
            self::fail('An unknown kind was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Choose a kind.', $e->fields['kind']);
        }
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function vehicle(string $name, bool $starred): array
    {
        return [
            'name' => $name,
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'starred' => $starred,
            'currentOdometerMiles' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function stay(string $name, string $latitude, string $longitude, string $arrive, string $depart): array
    {
        return $this->trips->create([
            'placeName' => $name,
            'arriveOn' => $arrive,
            'departOn' => $depart,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'today' => '2026-11-04',
        ]);
    }

    private function fill(int $odometer, int $amount, int $vehicleId, string $day, string $name = 'Fuel 1', string $status = 'paid'): int
    {
        $now = gmdate('c');
        $paidOn = $status === 'paid' ? $day : null;
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, last_paid_on, autopay, status,
                created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor
             ) VALUES (?, \'fuel\', ?, ?, ?, \'USD\', \'once\', ?, ?, ?, 0, ?, ?, ?, ?, ?, 400)'
        )->execute([
            $name, $this->accountId, $this->fuelId, $amount, $day, $day, $paidOn, $status, $now, $now, $vehicleId, $odometer,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function priced(?int $vehicleId, string $day, ?int $price, string $status = 'paid'): void
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, last_paid_on, autopay, status,
                created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor
             ) VALUES (\'Fuel 1\', \'fuel\', ?, ?, 1000, \'USD\', \'once\', ?, ?, ?, 0, ?, ?, ?, ?, 1000, ?)'
        )->execute([
            $this->accountId, $this->fuelId, $day, $day, $day, $status, $now, $now, $vehicleId, $price,
        ]);
    }

    /** @return array<string, mixed> */
    private function listed(string $name): array
    {
        foreach ($this->vehicles->list() as $vehicle) {
            if ($vehicle['name'] === $name) {
                return $vehicle;
            }
        }
        self::fail('Missing ' . $name);
    }
}
