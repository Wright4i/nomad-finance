<?php

declare(strict_types=1);

namespace App\Tests\Vehicle;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Category\CategoryService;
use App\Database\Database;
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
        $this->pdo->prepare('UPDATE user SET fuel_price_minor = 400 WHERE id = ?')->execute([$_SESSION['user_id']]);
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
        $vehicle = $this->vehicles->create($this->vehicle('Vehicle 1', true));
        $this->stay('Stay 1', '36.1699', '-115.1398', '2026-11-01', '2026-11-02');
        $second = $this->stay('Stay 2', '34.0522', '-118.2437', '2026-11-03', '2026-11-04');
        self::assertSame('9.0', $second['fuel']['milesPerGallon']);
        self::assertSame(400, $second['fuel']['priceMinor']);
        self::assertNotNull($second['fuel']['costMinor']);

        $this->fill(10000, 1000, $vehicle['id'], '2026-10-01');
        $this->fill(10020, 1000, $vehicle['id'], '2026-10-02');
        $this->fill(10120, 1000, $vehicle['id'], '2026-10-03');
        $opened = $this->vehicles->get($vehicle['id']);
        self::assertSame('8.0', $opened['milesPerGallon']);
        $labeled = $this->trips->get((int) $second['id'], '2026-11-03');
        self::assertSame('8.0', $labeled['fuel']['milesPerGallon']);
        self::assertSame(400, $labeled['fuel']['priceMinor']);
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

    private function fill(int $odometer, int $amount, int $vehicleId, string $day): void
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, last_paid_on, autopay, status,
                created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor
             ) VALUES (\'Fuel 1\', \'fuel\', ?, ?, ?, \'USD\', \'once\', ?, ?, ?, 0, \'paid\', ?, ?, ?, ?, 400)'
        )->execute([
            $this->accountId, $this->fuelId, $amount, $day, $day, $day, $now, $now, $vehicleId, $odometer,
        ]);
    }
}
