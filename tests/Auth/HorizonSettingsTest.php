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

final class HorizonSettingsTest extends TestCase
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
        $this->auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testTheHorizonAndFallbackPriceSaveWithoutChangingThePassword(): void
    {
        $opened = $this->auth->current();
        self::assertNotNull($opened);
        self::assertSame(60, $opened['horizonDays']);
        self::assertNull($opened['fuelPriceMinor']);
        $hash = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();

        $saved = $this->auth->updateSettings([
            'horizonDays' => 30,
            'fuelPriceMinor' => 399,
        ]);
        self::assertSame('Ada', $saved['name']);
        self::assertSame('USD', $saved['baseCurrency']);
        self::assertSame(30, $saved['horizonDays']);
        self::assertSame(399, $saved['fuelPriceMinor']);
        self::assertSame($hash, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());

        $cleared = $this->auth->updateSettings(['fuelPriceMinor' => null, 'horizonDays' => '60']);
        self::assertSame(60, $cleared['horizonDays']);
        self::assertNull($cleared['fuelPriceMinor']);

        try {
            $this->auth->updateSettings(['horizonDays' => 45]);
            self::fail('A horizon other than 30 or 60 was saved.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('horizonDays', $e->fields);
        }
        try {
            $this->auth->updateSettings(['fuelPriceMinor' => 0]);
            self::fail('A zero fuel price was saved.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('fuelPriceMinor', $e->fields);
        }
        self::assertSame(60, $this->auth->current()['horizonDays']);
    }

    public function testDemoCanChangeTheHorizonAndKeepsItsName(): void
    {
        $demo = $this->auth->enterDemo();
        self::assertSame(60, $demo['horizonDays']);
        self::assertSame(400, $demo['fuelPriceMinor']);

        $saved = $this->auth->updateSettings(['horizonDays' => 30]);
        self::assertSame('Demo', $saved['name']);
        self::assertSame('USD', $saved['baseCurrency']);
        self::assertSame(30, $saved['horizonDays']);
        self::assertSame(400, $saved['fuelPriceMinor']);

        try {
            $this->auth->updateSettings(['name' => 'Someone else', 'horizonDays' => 60]);
            self::fail('Demo renamed itself.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame('Demo', $this->auth->current()['name']);
        self::assertSame(30, $this->auth->current()['horizonDays']);
    }
}
