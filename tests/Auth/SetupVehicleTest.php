<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class SetupVehicleTest extends TestCase
{
    private PDO $pdo;

    private AuthService $auth;

    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $this->auth = new AuthService($this->pdo, new CurrencyCatalog(), new DemoLibrary($this->pdo, new CurrencyCatalog()));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testSetupStoresTheStarredVehicleAndTheFuelPrice(): void
    {
        $user = $this->auth->setup($this->books('Ada', [
            'fuelPriceMinor' => 399,
            'vehicleName' => 'Rig 1',
            'kind' => 'truck',
            'mpgMinTenths' => 80,
            'mpgMaxTenths' => 160,
            'currentOdometerMiles' => 12000,
        ]));

        self::assertSame('Ada', $user['name']);
        self::assertArrayNotHasKey('fuelPriceMinor', $user);
        $vehicle = $this->ownerVehicle();
        self::assertSame(399, (int) $vehicle['fuel_price_minor']);
        self::assertSame('Rig 1', $vehicle['name']);
        self::assertSame('truck', $vehicle['kind']);
        self::assertSame(1, (int) $vehicle['starred']);
        self::assertSame(80, (int) $vehicle['mpg_min_tenths']);
        self::assertSame(160, (int) $vehicle['mpg_max_tenths']);
        self::assertSame(12000, (int) $vehicle['current_odometer_miles']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'owner'")->fetchColumn());
        self::assertSame('Bunkhouse', $this->pdo->query("SELECT v.name FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'demo' AND v.starred = 1")->fetchColumn());
    }

    public function testAnEmptyFuelPriceStoresMilesOnly(): void
    {
        $user = $this->auth->setup($this->books('Ada', [
            'fuelPriceMinor' => null,
            'vehicleName' => 'Rig 1',
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
        ]));

        self::assertArrayNotHasKey('fuelPriceMinor', $user);
        self::assertNull($this->ownerVehicle()['fuel_price_minor']);
        self::assertSame('Rig 1', $this->ownerVehicle()['name']);
    }

    public function testABadMpgRangeLeavesSetupUnfinished(): void
    {
        try {
            $this->auth->setup($this->books('Ada', [
                'fuelPriceMinor' => 399,
                'vehicleName' => 'Rig 1',
                'kind' => 'rv',
                'mpgMinTenths' => 120,
                'mpgMaxTenths' => 60,
            ]));
            self::fail('A backwards mpg range was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('The maximum is above the minimum.', $e->fields['mpgMaxTenths'] ?? null);
        }

        self::assertTrue($this->auth->needsSetup());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn());
        try {
            $this->auth->login(['name' => 'Ada', 'password' => 'correct horse battery']);
            self::fail('A half-created login was usable.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }

        $user = $this->auth->setup($this->books('Ada', [
            'fuelPriceMinor' => 399,
            'vehicleName' => 'Rig 1',
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
        ]));
        self::assertSame('Ada', $user['name']);
        self::assertSame(399, (int) $this->ownerVehicle()['fuel_price_minor']);
        self::assertSame(1, (int) $this->ownerVehicle()['starred']);
    }

    public function testABadFuelPriceLeavesSetupUnfinished(): void
    {
        try {
            $this->auth->setup($this->books('Ada', [
                'fuelPriceMinor' => 0,
                'vehicleName' => 'Rig 1',
                'kind' => 'car',
                'mpgMinTenths' => 60,
                'mpgMaxTenths' => 120,
            ]));
            self::fail('A zero fuel price was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('fuelPriceMinor', $e->fields);
        }

        self::assertTrue($this->auth->needsSetup());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user')->fetchColumn());
    }

    public function testAnEmptyVehicleNameLeavesSetupUnfinished(): void
    {
        try {
            $this->auth->setup($this->books('Ada', [
                'vehicleName' => '   ',
                'kind' => 'rv',
                'mpgMinTenths' => 60,
                'mpgMaxTenths' => 120,
            ]));
            self::fail('A blank vehicle name was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('vehicleName', $e->fields);
        }

        self::assertTrue($this->auth->needsSetup());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user')->fetchColumn());
    }

    public function testSetupAcceptsTheCampgroundKinds(): void
    {
        foreach (['utv', 'atv', 'golf cart', 'dirt bike'] as $kind) {
            $_SESSION = [];
            $path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
            $pdo = Database::connect($path, dirname(__DIR__, 2) . '/db/migrations');
            $auth = new AuthService($pdo, new CurrencyCatalog(), new DemoLibrary($pdo, new CurrencyCatalog()));
            $auth->setup($this->books('Ada', [
                'vehicleName' => 'Rig 1',
                'kind' => $kind,
                'mpgMinTenths' => 80,
                'mpgMaxTenths' => 160,
            ]));
            self::assertSame($kind, $pdo->query(
                "SELECT v.kind FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'owner'"
            )->fetchColumn());
            unset($pdo);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testSetupRefusesAnUnknownKind(): void
    {
        try {
            $this->auth->setup($this->books('Ada', [
                'vehicleName' => 'Rig 1',
                'kind' => 'boat',
                'mpgMinTenths' => 60,
                'mpgMaxTenths' => 120,
            ]));
            self::fail('An unknown kind was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Choose a kind.', $e->fields['kind']);
        }

        self::assertTrue($this->auth->needsSetup());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user')->fetchColumn());
    }

    /** @param array<string, mixed> $vehicle
     *  @return array<string, mixed>
     */
    private function books(string $name, array $vehicle): array
    {
        $base = [
            'name' => $name,
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ] + $vehicle;
        if (!array_key_exists('currentOdometerMiles', $base)) {
            $base['currentOdometerMiles'] = 0;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function ownerVehicle(): array
    {
        $row = $this->pdo->query(
            "SELECT v.name, v.kind, v.starred, v.mpg_min_tenths, v.mpg_max_tenths, v.current_odometer_miles, v.fuel_price_minor
             FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'owner'"
        )->fetch();
        self::assertIsArray($row);

        return $row;
    }
}
