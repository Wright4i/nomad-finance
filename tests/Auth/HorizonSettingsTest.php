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

    public function testTheSpanSavesWithoutChangingThePassword(): void
    {
        $opened = $this->auth->current();
        self::assertNotNull($opened);
        self::assertSame('1 month', $opened['spanLength']);
        self::assertSame(gmdate('Y-m-01'), $opened['spanStart']);
        self::assertArrayNotHasKey('horizonDays', $opened);
        self::assertArrayNotHasKey('fuelPriceMinor', $opened);
        $hash = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();

        $saved = $this->auth->updateSettings([
            'spanStart' => '2026-11-01',
            'spanLength' => '2 weeks',
            'theme' => 'light',
        ]);
        self::assertSame('Ada', $saved['name']);
        self::assertSame('USD', $saved['baseCurrency']);
        self::assertSame('2026-11-01', $saved['spanStart']);
        self::assertSame('2026-11-14', $saved['spanEnd']);
        self::assertSame('2 weeks', $saved['spanLength']);
        self::assertSame('light', $saved['theme']);
        self::assertSame($hash, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());

        try {
            $this->auth->updateSettings(['spanLength' => '5 weeks']);
            self::fail('A span length outside the six stops was saved.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('spanLength', $e->fields);
        }
        self::assertSame('2 weeks', $this->auth->current()['spanLength']);
    }

    public function testDemoCanChangeTheSpanAndKeepsItsName(): void
    {
        $demo = $this->auth->enterDemo();
        self::assertSame('1 month', $demo['spanLength']);
        self::assertSame(400, (int) $this->pdo->query(
            "SELECT v.fuel_price_minor FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'demo'"
        )->fetchColumn());

        $saved = $this->auth->updateSettings(['spanLength' => '1 week', 'spanStart' => '2026-11-01']);
        self::assertSame('Demo', $saved['name']);
        self::assertSame('2026-11-07', $saved['spanEnd']);

        try {
            $this->auth->updateSettings(['name' => 'Someone else', 'spanLength' => '1 month']);
            self::fail('Demo renamed itself.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame('Demo', $this->auth->current()['name']);
        self::assertSame('1 week', $this->auth->current()['spanLength']);
    }
}
