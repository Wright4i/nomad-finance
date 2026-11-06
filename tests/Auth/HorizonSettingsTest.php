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

    public function testTheHorizonSavesWithoutChangingThePassword(): void
    {
        $opened = $this->auth->current();
        self::assertNotNull($opened);
        self::assertSame(60, $opened['horizonDays']);
        self::assertArrayNotHasKey('fuelPriceMinor', $opened);
        $hash = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();

        $saved = $this->auth->updateSettings([
            'horizonDays' => 30,
            'theme' => 'light',
        ]);
        self::assertSame('Ada', $saved['name']);
        self::assertSame('USD', $saved['baseCurrency']);
        self::assertSame(30, $saved['horizonDays']);
        self::assertSame('light', $saved['theme']);
        self::assertArrayNotHasKey('fuelPriceMinor', $saved);
        self::assertSame($hash, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());

        $cleared = $this->auth->updateSettings(['horizonDays' => '60']);
        self::assertSame(60, $cleared['horizonDays']);

        try {
            $this->auth->updateSettings(['horizonDays' => 45]);
            self::fail('A horizon other than 30 or 60 was saved.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('horizonDays', $e->fields);
        }
        self::assertSame(60, $this->auth->current()['horizonDays']);
    }

    public function testDemoCanChangeTheHorizonAndKeepsItsName(): void
    {
        $demo = $this->auth->enterDemo();
        self::assertSame(60, $demo['horizonDays']);
        self::assertSame(400, (int) $this->pdo->query(
            "SELECT v.fuel_price_minor FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'demo'"
        )->fetchColumn());

        $saved = $this->auth->updateSettings(['horizonDays' => 30]);
        self::assertSame('Demo', $saved['name']);
        self::assertSame('USD', $saved['baseCurrency']);
        self::assertSame(30, $saved['horizonDays']);

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
