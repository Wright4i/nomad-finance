<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Auth\Actor;
use App\Auth\AuthService;
use App\Database\Database;
use App\Dashboard\DashboardService;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Trip\Drive;
use App\Trip\TripService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TimelineTest extends TestCase
{
    private PDO $pdo;

    private DashboardService $dashboard;

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
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        $this->auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $this->auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $this->auth->enterDemo();
        $this->dashboard = new DashboardService(
            $this->pdo,
            $catalog,
            $actor,
            new EnvelopeService($this->pdo, $actor, $catalog, new TargetService($this->pdo, $actor)),
            new PayCycleService($this->pdo, $actor),
            new TripService($this->pdo, $actor, $catalog, new Drive(false)),
        );
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testTheTimelineFollowsTheSavedWindow(): void
    {
        $sheet = $this->dashboard->show('2026-11-03');

        self::assertSame(60, $sheet['horizonDays']);
        self::assertSame('2026-11-03', $sheet['windowStart']);
        self::assertSame('2027-01-01', $sheet['windowEnd']);
        self::assertSame(3996000, $sheet['incomeMinor']);
        self::assertArrayNotHasKey('billsDue', $sheet);

        $income = [];
        foreach ($sheet['income'] as $row) {
            $income[$row['payor']] = $row;
        }
        self::assertSame(220000, $income['Weekday Payor']['weekdayPay']['earlierMinor']);
        self::assertSame(220000, $income['Weekday Payor']['weekdayPay']['laterMinor']);
        self::assertNull($income['Weekday Payor']['average']);
        self::assertFalse($income['Weekday Payor']['averageOn']);
        self::assertNull($income['Weekly Payor']['average']);

        $paydays = $this->rows($sheet['timeline'], 'payday', 'Weekday Payor');
        $byDay = [];
        foreach ($paydays as $row) {
            $byDay[$row['on']] = $row;
            self::assertNotSame('average', $row['kind']);
        }
        self::assertSame(220000, $byDay['2026-11-15']['paycheckMinor']);
        self::assertSame(210000, $byDay['2026-12-15']['paycheckMinor']);
        self::assertSame('weekdays', $byDay['2026-12-01']['hoursMode']);
        self::assertSame(21, $byDay['2026-12-01']['weekdayPay']['weekdays']);
        self::assertArrayNotHasKey('2026-11-01', $byDay);

        self::assertNotContains('2026-10-15', $this->dates($sheet['timeline'], 'bill'));
        self::assertContains('2026-11-10', $this->dates($sheet['timeline'], 'bill', 'One-Time Bill'));
        self::assertContains('2026-12-05', $this->dates($sheet['timeline'], 'bill', 'Education Bill'));
        self::assertGreaterThan(1, count($this->dates($sheet['timeline'], 'bill', 'Childcare Bill')));

        $stays = [];
        foreach ($sheet['timeline'] as $row) {
            self::assertNotSame('average', $row['kind']);
            if ($row['kind'] === 'stay') {
                $stays[$row['placeName']] = $row;
            }
        }
        self::assertNull($stays['Stay 1']['fuel']['miles']);
        self::assertNull($stays['Stay 1']['fuel']['costMinor']);
        self::assertNotNull($stays['Stay 2']['fuel']['miles']);
        self::assertNotNull($stays['Stay 2']['fuel']['costMinor']);
        self::assertContains('2026-11-22', $this->dates($sheet['timeline'], 'balanceDue', 'Stay 1'));

        $this->auth->updateSettings(['horizonDays' => 30]);
        $shorter = $this->dashboard->show('2026-11-03');
        self::assertSame(30, $shorter['horizonDays']);
        self::assertSame('2026-12-02', $shorter['windowEnd']);
        self::assertNotContains('2026-12-05', $this->dates($shorter['timeline'], 'bill', 'Education Bill'));
        self::assertNotContains('2026-12-15', $this->dates($shorter['timeline'], 'payday', 'Weekday Payor'));
        self::assertContains('2026-12-01', $this->dates($shorter['timeline'], 'payday', 'Weekday Payor'));
        self::assertSame(3996000, $shorter['incomeMinor']);
        self::assertSame(1329500, $shorter['envelopes']['availableMinor']);
    }

    public function testDuePiesSumActiveBillsInTheMonth(): void
    {
        $sheet = $this->dashboard->show('2026-11-04');
        $november = $this->slices($sheet['duePies']['2026-11']);
        self::assertSame(['USD'], array_keys($november));
        self::assertSame('lodging', array_key_first($november['USD']));
        self::assertSame(38000, $november['USD']['rent']);
        self::assertGreaterThan(4968, $november['USD']['electricity']);
        self::assertSame(127500, $november['USD']['healthcare']);
        self::assertSame(2000, $november['USD']['other']);
        self::assertSame(2500, $november['USD']['debt']);
        self::assertArrayNotHasKey('entertainment', $november['USD']);

        $december = $this->slices($sheet['duePies']['2026-12']);
        self::assertSame(50000, $december['USD']['entertainment']);
        self::assertGreaterThanOrEqual(545000, $december['USD']['debt']);
    }

    /**
     * @param list<array{currency:string,slices:list<array{type:string,amountMinor:int}>}> $pies
     * @return array<string, array<string, int>>
     */
    private function slices(array $pies): array
    {
        $byCurrency = [];
        foreach ($pies as $pie) {
            $amounts = [];
            foreach ($pie['slices'] as $slice) {
                $amounts[$slice['type']] = $slice['amountMinor'];
            }
            $byCurrency[$pie['currency']] = $amounts;
        }

        return $byCurrency;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, string $kind, ?string $name = null): array
    {
        $found = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind) {
                continue;
            }
            if ($name !== null && ($row['name'] ?? $row['placeName'] ?? $row['payor'] ?? null) !== $name) {
                continue;
            }
            $found[] = $row;
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function dates(array $rows, string $kind, ?string $name = null): array
    {
        return array_column($this->rows($rows, $kind, $name), 'on');
    }
}
