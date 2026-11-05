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
        ]));

        self::assertSame('Ada', $user['name']);
        self::assertSame(399, $user['fuelPriceMinor']);
        $vehicle = $this->ownerVehicle();
        self::assertSame('Rig 1', $vehicle['name']);
        self::assertSame('truck', $vehicle['kind']);
        self::assertSame(1, (int) $vehicle['starred']);
        self::assertSame(80, (int) $vehicle['mpg_min_tenths']);
        self::assertSame(160, (int) $vehicle['mpg_max_tenths']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'owner'")->fetchColumn());
        self::assertSame('Vehicle 1', $this->pdo->query("SELECT v.name FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'demo'")->fetchColumn());
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

        self::assertNull($user['fuelPriceMinor']);
        self::assertNull($this->pdo->query("SELECT fuel_price_minor FROM user WHERE role = 'owner'")->fetchColumn());
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
        self::assertSame(399, $user['fuelPriceMinor']);
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

    /** @param array<string, mixed> $vehicle
     *  @return array<string, mixed>
     */
    private function books(string $name, array $vehicle): array
    {
        return [
            'name' => $name,
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ] + $vehicle;
    }

    /** @return array<string, mixed> */
    private function ownerVehicle(): array
    {
        $row = $this->pdo->query(
            "SELECT v.name, v.kind, v.starred, v.mpg_min_tenths, v.mpg_max_tenths
             FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'owner'"
        )->fetch();
        self::assertIsArray($row);

        return $row;
    }
}
