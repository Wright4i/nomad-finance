<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Money\CurrencyCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class MotionSettingsTest extends TestCase
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

    public function testMotionColumnsAreGoneAndOtherSettingsStay(): void
    {
        $opened = $this->auth->current();
        self::assertNotNull($opened);
        foreach ([
            'motionAssigned',
            'motionNote',
            'motionLevel',
            'motionScrim',
            'motionDialog',
            'motionTicker',
            'motionStagger',
        ] as $key) {
            self::assertArrayNotHasKey($key, $opened);
        }

        $names = array_column($this->pdo->query('PRAGMA table_info(user)')->fetchAll(), 'name');
        foreach ([
            'motion_assigned',
            'motion_note',
            'motion_level',
            'motion_scrim',
            'motion_dialog',
            'motion_ticker',
            'motion_stagger',
        ] as $column) {
            self::assertNotContains($column, $names);
        }

        $hash = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();
        $saved = $this->auth->updateSettings(['theme' => 'light', 'motionTicker' => false]);
        self::assertSame('Ada', $saved['name']);
        self::assertSame('light', $saved['theme']);
        self::assertArrayNotHasKey('fuelPriceMinor', $saved);
        self::assertSame('1 month', $saved['acctPeriodLength']);
        self::assertSame(gmdate('Y-m-01'), $saved['acctPeriodStart']);
        self::assertArrayNotHasKey('motionTicker', $saved);
        self::assertSame($hash, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());
    }

    public function testDemoResetLeavesTheOwnerTheme(): void
    {
        $ownerId = $_SESSION['user_id'];
        $this->auth->updateSettings(['theme' => 'light']);
        $hash = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();
        $this->auth->enterDemo();

        (new DemoLibrary($this->pdo, new CurrencyCatalog()))->resetDemo();

        $_SESSION['user_id'] = $ownerId;
        $owner = $this->auth->current();
        self::assertNotNull($owner);
        self::assertSame('Ada', $owner['name']);
        self::assertSame('light', $owner['theme']);
        self::assertArrayNotHasKey('fuelPriceMinor', $owner);
        self::assertSame($hash, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());

        $demo = $this->pdo->query("SELECT acct_period_length FROM user WHERE role = 'demo'")->fetch();
        self::assertIsArray($demo);
        self::assertSame('1 month', $demo['acct_period_length']);
        self::assertSame(400, (int) $this->pdo->query(
            "SELECT v.fuel_price_minor FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'demo'"
        )->fetchColumn());
    }
}
