<?php

declare(strict_types=1);

namespace App\Tests\AcctPeriod;

use App\AcctPeriod\AcctPeriod;
use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class AcctPeriodReachTest extends TestCase
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

    public function testElevenPeriodsStayAndTheTwelfthIsRefused(): void
    {
        $today = gmdate('Y-m-d');
        $month = AcctPeriod::defaultStart($today);
        self::assertLessThanOrEqual($today, $month);
        self::assertGreaterThanOrEqual($today, AcctPeriod::end($month, '1 month'));
        $this->assertWindow($month, '1 month', $today);
        $this->assertWindow($today, '1 week', $today);

        self::assertSame(1, AcctPeriod::stepsFromToday('2026-01-31', '1 month', '2026-03-30'));
        self::assertSame(2, AcctPeriod::stepsFromToday('2026-01-31', '1 month', '2026-03-31'));
        self::assertSame(-1, AcctPeriod::stepsFromToday('2026-03-31', '1 month', '2026-03-30'));
        self::assertSame(11, AcctPeriod::stepsFromToday('2026-08-17', '1 week', '2026-11-02'));
        self::assertSame(-11, AcctPeriod::stepsFromToday('2027-01-18', '1 week', '2026-11-02'));
    }

    private function assertWindow(string $anchor, string $length, string $today): void
    {
        self::assertSame(0, AcctPeriod::stepsFromToday($anchor, $length, $today));
        foreach ([-11, 11] as $steps) {
            $start = AcctPeriod::shift($anchor, $length, $steps);
            self::assertSame(-$steps, AcctPeriod::stepsFromToday($start, $length, $today));
            $saved = $this->auth->updateSettings([
                'acctPeriodStart' => $start,
                'acctPeriodLength' => $length,
            ]);
            self::assertSame($start, $saved['acctPeriodStart']);
            self::assertSame($length, $saved['acctPeriodLength']);
        }

        $kept = AcctPeriod::shift($anchor, $length, 11);
        foreach ([-12, 12] as $steps) {
            $start = AcctPeriod::shift($anchor, $length, $steps);
            self::assertSame(-$steps, AcctPeriod::stepsFromToday($start, $length, $today));
            self::assertFalse(AcctPeriod::withinReach($start, $length, $today));
            try {
                $this->auth->updateSettings([
                    'acctPeriodStart' => $start,
                    'acctPeriodLength' => $length,
                ]);
                self::fail('A period 12 steps from today was saved.');
            } catch (ApiException $e) {
                self::assertSame(409, $e->status);
                self::assertSame('That period is more than 11 from today.', $e->getMessage());
            }
            $current = $this->auth->current();
            self::assertNotNull($current);
            self::assertSame($kept, $current['acctPeriodStart']);
            self::assertSame($length, $current['acctPeriodLength']);
        }
    }
}
