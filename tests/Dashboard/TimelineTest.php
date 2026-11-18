<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Auth\Actor;
use App\Calendar\CalendarEntryService;
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
            new EnvelopeService($this->pdo, $actor, $catalog, new TargetService($this->pdo, $actor), new PayCycleService($this->pdo, $actor), new Drive(false)),
            new PayCycleService($this->pdo, $actor),
            new TripService($this->pdo, $actor, $catalog, new Drive(false)),
            new CalendarEntryService($this->pdo, $actor),
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

        self::assertArrayNotHasKey('horizonDays', $sheet);
        self::assertSame('2026-11-01', $sheet['windowStart']);
        self::assertSame('2026-11-30', $sheet['windowEnd']);
        self::assertSame(877333, $sheet['incomeMinor']);
        self::assertArrayNotHasKey('billsDue', $sheet);

        $income = [];
        foreach ($sheet['income'] as $row) {
            $income[$row['payor']] = $row;
        }
        self::assertCount(2, $income);
        self::assertSame(833333, $income['Day job']['paycheckMinor']);
        self::assertSame(44000, $income['Side job']['paycheckMinor']);
        self::assertNull($income['Side job']['average']);
        self::assertTrue($income['Side job']['averageOn']);
        self::assertFalse($income['Day job']['averageOn']);

        $paydays = $this->rows($sheet['timeline'], 'payday', 'Side job');
        $byDay = [];
        foreach ($paydays as $row) {
            $byDay[$row['on']] = $row;
            self::assertNotSame('average', $row['kind']);
        }
        self::assertSame(44000, $byDay['2026-11-16']['paycheckMinor']);
        self::assertSame(44000, $byDay['2026-11-01']['paycheckMinor']);
        $salary = $this->rows($sheet['timeline'], 'payday', 'Day job');
        self::assertSame('2026-11-01', $salary[0]['on']);
        self::assertSame(833333, $salary[0]['paycheckMinor']);

        self::assertNotContains('2026-10-15', $this->dates($sheet['timeline'], 'bill'));
        self::assertContains('2026-11-10', $this->dates($sheet['timeline'], 'bill', 'Site week'));
        self::assertNotContains('2026-12-05', $this->dates($sheet['timeline'], 'bill', 'Park pass'));
        self::assertGreaterThan(1, count($this->dates($sheet['timeline'], 'bill', 'Camp store')));
        self::assertSame([], $this->dates($sheet['timeline'], 'stay'));
        self::assertSame([], $this->dates($sheet['timeline'], 'balanceDue'));

        $trips = new TripService($this->pdo, new Actor($this->pdo), new CurrencyCatalog(), new Drive(false));
        $places = [];
        foreach ($trips->list('2026-10-06') as $trip) {
            $places[$trip['placeName']] = $trip;
        }
        self::assertNull($places['Home base']['fuel']['miles']);
        self::assertNull($places['Home base']['fuel']['costMinor']);
        self::assertNull($places['OCEAN User Group']['fuel']['miles']);
        self::assertNotNull($places['Oak Creek RV Park']['fuel']['miles']);
        self::assertNotNull($places['Oak Creek RV Park']['fuel']['costMinor']);

        $this->auth->updateSettings(['acctPeriodStart' => '2026-10-01']);
        $october = $this->dashboard->show('2026-10-07');
        $stays = [];
        foreach ($october['timeline'] as $row) {
            if ($row['kind'] === 'stay') {
                $stays[$row['placeName']] = $row;
            }
        }
        self::assertNotNull($stays['Oasis Las Vegas RV Resort']['fuel']['miles']);
        self::assertArrayNotHasKey('Home base', $stays);

        $this->auth->updateSettings(['acctPeriodStart' => '2026-11-01', 'acctPeriodLength' => '2 weeks']);
        $shorter = $this->dashboard->show('2026-11-03');
        self::assertSame('2026-11-14', $shorter['windowEnd']);
        self::assertNotContains('2026-12-05', $this->dates($shorter['timeline'], 'bill', 'Park pass'));
        self::assertNotContains('2026-11-16', $this->dates($shorter['timeline'], 'payday', 'Side job'));
        self::assertNotContains('2026-12-01', $this->dates($shorter['timeline'], 'payday', 'Day job'));
        self::assertSame(877333, $shorter['incomeMinor']);
        $sources = 0;
        foreach ($shorter['envelopes']['sources']['income'] as $row) {
            $sources += (int) $row['amountMinor'];
        }
        foreach ($shorter['envelopes']['sources']['accounts'] as $row) {
            $sources += (int) $row['amountMinor'];
        }
        foreach ($shorter['envelopes']['sources']['floats'] ?? [] as $row) {
            $sources -= (int) $row['uncoveredMinor'];
        }
        self::assertSame($sources, $shorter['envelopes']['poolMinor']);
        self::assertSame($shorter['envelopes']['poolMinor'] - $shorter['envelopes']['assignedMinor'], $shorter['envelopes']['availableMinor']);
        self::assertLessThan($shorter['envelopes']['poolMinor'], $shorter['envelopes']['availableMinor']);
        self::assertNotSame($shorter['envelopes']['assignedMinor'], $shorter['envelopes']['availableMinor']);
        self::assertGreaterThan(50000, $shorter['envelopes']['assignedMinor']);
    }

    public function testDuePiesSumActiveBillsInTheMonth(): void
    {
        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-11-01', '1 month', $_SESSION['user_id']]);
        $sheet = $this->dashboard->show('2026-11-04');
        $november = $this->slices($sheet['duePies']);
        self::assertSame(['USD'], array_keys($november));
        self::assertSame('rent', array_key_first($november['USD']));
        self::assertSame(80500, $november['USD']['rent']);
        self::assertGreaterThan(4968, $november['USD']['electricity']);
        self::assertSame(2900, $november['USD']['healthcare']);
        self::assertSame(800, $november['USD']['other']);
        self::assertSame(14500, $november['USD']['debt']);
        self::assertArrayNotHasKey('entertainment', $november['USD']);

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-12-01', $_SESSION['user_id']]);
        $later = $this->dashboard->show('2026-11-04');
        $december = $this->slices($later['duePies']);
        self::assertSame(8000, $december['USD']['entertainment']);
        self::assertSame(14500, $december['USD']['debt']);
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
