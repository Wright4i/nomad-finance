<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Auth\Actor;
use App\Calendar\CalendarEntryService;
use App\Auth\AuthService;
use App\Dashboard\DashboardService;
use App\Dashboard\PlannerHeading;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Trip\Drive;
use App\Trip\TripService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlannerHeadingTest extends TestCase
{
    public function testUpcomingAndDoneStaysLeaveTheWordPlanner(): void
    {
        self::assertSame(
            ['text' => 'Planner', 'italic' => false],
            PlannerHeading::label([
                ['id' => 1, 'arriveOn' => '2026-10-01', 'departOn' => '2026-10-04', 'city' => 'Bend'],
                ['id' => 2, 'arriveOn' => '2026-11-20', 'departOn' => '2026-11-24', 'city' => 'Las Vegas'],
            ], '2026-11-05'),
        );
        self::assertSame(
            ['text' => 'Planner', 'italic' => false],
            PlannerHeading::label([], '2026-11-05'),
        );
    }

    public function testAnUnderwayStayWithNoCityLeavesTheWordPlanner(): void
    {
        self::assertSame(
            ['text' => 'Planner', 'italic' => false],
            PlannerHeading::label([
                ['id' => 3, 'arriveOn' => '2026-11-04', 'departOn' => '2026-11-08', 'city' => null],
            ], '2026-11-05'),
        );
        self::assertSame(
            ['text' => 'Planner', 'italic' => false],
            PlannerHeading::label([
                ['id' => 3, 'arriveOn' => '2026-11-04', 'departOn' => '2026-11-08', 'city' => '   '],
            ], '2026-11-05'),
        );
    }

    public function testThePoolHasFiftyPhrasesAndEachNamesThePlace(): void
    {
        self::assertCount(50, PlannerHeading::PHRASES);
        self::assertCount(50, array_unique(PlannerHeading::PHRASES));

        foreach (PlannerHeading::PHRASES as $index => $template) {
            self::assertSame(1, substr_count($template, '%s'));
            self::assertStringNotContainsString('Las Vegas', $template);
            self::assertSame(sprintf($template, 'Sitka'), PlannerHeading::phrase('Sitka', $index));
            self::assertStringContainsString('Sitka', PlannerHeading::phrase('Sitka', $index));
        }
    }

    public function testAGivenIndexReturnsAStablePhrase(): void
    {
        self::assertSame('Welcome to Bend', PlannerHeading::phrase('Bend', 0));
        self::assertSame('Viva Reno', PlannerHeading::phrase('Reno', 4));
        self::assertSame(PlannerHeading::phrase('Bend', 7), PlannerHeading::phrase('Bend', 7));
        self::assertSame(PlannerHeading::phrase('Bend', 7), PlannerHeading::phrase('Bend', 57));
        self::assertSame(PlannerHeading::phrase('Bend', 49), PlannerHeading::phrase('Bend', -1));

        $label = PlannerHeading::label([
            ['id' => 1, 'arriveOn' => '2026-10-01', 'departOn' => '2026-10-04', 'city' => 'Bend'],
            ['id' => 2, 'arriveOn' => '2026-11-04', 'departOn' => '2026-11-08', 'city' => 'Seattle'],
            ['id' => 3, 'arriveOn' => '2026-12-01', 'departOn' => '2026-12-05', 'city' => 'Los Angeles'],
        ], '2026-11-05', 7);

        self::assertSame(PlannerHeading::phrase('Seattle', 7), $label['text']);
        self::assertStringContainsString('Seattle', $label['text']);
        self::assertStringNotContainsString('Bend', $label['text']);
        self::assertStringNotContainsString('Los Angeles', $label['text']);
        self::assertTrue($label['italic']);
    }

    public function testAnEmptyPlaceDoesNotInventACity(): void
    {
        self::assertSame('Planner', PlannerHeading::phrase('', 0));
        self::assertSame('Planner', PlannerHeading::phrase('   ', 17));
        self::assertSame(PlannerHeading::label([], '2026-11-05')['text'], PlannerHeading::phrase('', 3));
        self::assertStringNotContainsString('Las Vegas', PlannerHeading::phrase('', 3));
        self::assertStringNotContainsString('Seattle', PlannerHeading::phrase('   ', 3));
    }

    public function testALoadPicksOnePhraseFromThePool(): void
    {
        $trips = [
            ['id' => 2, 'arriveOn' => '2026-11-04', 'departOn' => '2026-11-08', 'city' => 'Seattle'],
        ];
        $seen = [];
        for ($n = 0; $n < 40; $n++) {
            $label = PlannerHeading::label($trips, '2026-11-05');
            self::assertTrue($label['italic']);
            self::assertContains($label['text'], $this->phrases('Seattle'));
            $seen[$label['text']] = true;
        }
        self::assertGreaterThan(1, count($seen));
    }

    public function testAHandoffUsesTheEarlierStay(): void
    {
        $trips = [
            ['id' => 4, 'arriveOn' => '2026-11-01', 'departOn' => '2026-11-05', 'city' => 'Seattle'],
            ['id' => 5, 'arriveOn' => '2026-11-05', 'departOn' => '2026-11-08', 'city' => 'Portland'],
        ];
        $label = PlannerHeading::label($trips, '2026-11-05', 0);

        self::assertSame(PlannerHeading::phrase('Seattle', 0), $label['text']);
        self::assertTrue($label['italic']);
        self::assertStringNotContainsString('Portland', $label['text']);

        $picked = PlannerHeading::label($trips, '2026-11-05');
        self::assertContains($picked['text'], $this->phrases('Seattle'));
        self::assertStringNotContainsString('Portland', $picked['text']);

        $blank = PlannerHeading::label([
            ['id' => 4, 'arriveOn' => '2026-11-01', 'departOn' => '2026-11-05', 'city' => null],
            ['id' => 5, 'arriveOn' => '2026-11-05', 'departOn' => '2026-11-08', 'city' => 'Portland'],
        ], '2026-11-05');

        self::assertSame(['text' => 'Planner', 'italic' => false], $blank);
    }

    public function testTheDashboardNamesTheOpenStayAndLeavesTheBooks(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = Database::connect($path, dirname(__DIR__, 2) . '/db/migrations');
        try {
            $catalog = new CurrencyCatalog();
            $actor = new Actor($pdo);
            $auth = new AuthService($pdo, $catalog, new DemoLibrary($pdo, $catalog));
            $auth->setup([
                'name' => 'Ada',
                'password' => 'correct horse battery',
                'baseCurrency' => 'USD',
            ]);
            $auth->enterDemo();
            $trips = new TripService($pdo, $actor, $catalog, new Drive(false));
            $dashboard = new DashboardService(
                $pdo,
                $catalog,
                $actor,
                new EnvelopeService($pdo, $actor, $catalog, new TargetService($pdo, $actor), new PayCycleService($pdo, $actor)),
                new PayCycleService($pdo, $actor),
                $trips,
                new CalendarEntryService($pdo, $actor),
            );

            $plain = $dashboard->show('2026-11-05');
            self::assertContains($plain['heading'], $this->phrases('Mesa'));
            self::assertTrue($plain['headingItalic']);

            $books = $this->books($pdo);
            $current = null;
            foreach ($trips->list('2026-11-05', false) as $trip) {
                if ($trip['arriveOn'] <= '2026-11-05' && '2026-11-05' <= $trip['departOn']) {
                    $current = $trip;
                    break;
                }
            }
            self::assertIsArray($current);
            self::assertSame('Usery Mountain', $current['placeName']);
            $trips->update((int) $current['id'], [
                'placeName' => 'Usery Mountain',
                'arriveOn' => '2026-10-30',
                'departOn' => '2026-11-13',
                'latitude' => '33.4805',
                'longitude' => '-111.6135',
                'city' => null,
                'region' => 'AZ',
                'accountId' => $current['accountId'],
                'totalMinor' => 44800,
                'amountDueMinor' => 0,
                'today' => '2026-11-05',
            ]);
            $cleared = $dashboard->show('2026-11-05');
            self::assertSame('Planner', $cleared['heading']);
            self::assertFalse($cleared['headingItalic']);
            self::assertSame($books, $this->books($pdo));
        } finally {
            unset($pdo);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return list<string> */
    private function phrases(string $place): array
    {
        $phrases = [];
        foreach (PlannerHeading::PHRASES as $index => $template) {
            $phrases[] = PlannerHeading::phrase($place, $index);
        }

        return $phrases;
    }

    /** @return array{accounts:list<array<string, mixed>>} */
    private function books(PDO $pdo): array
    {
        $accounts = $pdo->query('SELECT id, balance_minor FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        return [
            'accounts' => is_array($accounts) ? $accounts : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $trips
     * @return list<array{id:int,placeName:string,city:mixed,arriveOn:mixed,departOn:mixed}>
     */
    private function stayRows(array $trips): array
    {
        $rows = [];
        foreach ($trips as $trip) {
            $rows[] = [
                'id' => (int) $trip['id'],
                'placeName' => (string) $trip['placeName'],
                'city' => $trip['city'],
                'arriveOn' => $trip['arriveOn'],
                'departOn' => $trip['departOn'],
            ];
        }

        return $rows;
    }
}
