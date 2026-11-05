<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Auth\Actor;
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

    public function testAnUnderwayCityUsesAStablePhraseFromTheSet(): void
    {
        self::assertSame('Welcome to Seattle', PlannerHeading::phrase(4, 'Seattle'));
        self::assertSame('Hello from Seattle', PlannerHeading::phrase(1, 'Seattle'));
        self::assertSame('Settled in Seattle', PlannerHeading::phrase(2, 'Seattle'));
        self::assertSame('Good to be in Seattle', PlannerHeading::phrase(3, 'Seattle'));
        self::assertNotSame(PlannerHeading::phrase(1, 'Seattle'), PlannerHeading::phrase(2, 'Seattle'));

        $label = PlannerHeading::label([
            ['id' => 1, 'arriveOn' => '2026-10-01', 'departOn' => '2026-10-04', 'city' => 'Bend'],
            ['id' => 2, 'arriveOn' => '2026-11-04', 'departOn' => '2026-11-08', 'city' => 'Seattle'],
            ['id' => 3, 'arriveOn' => '2026-12-01', 'departOn' => '2026-12-05', 'city' => 'Los Angeles'],
        ], '2026-11-05');

        self::assertSame('Settled in Seattle', $label['text']);
        self::assertTrue($label['italic']);
    }

    public function testAHandoffUsesTheEarlierStay(): void
    {
        $label = PlannerHeading::label([
            ['id' => 4, 'arriveOn' => '2026-11-01', 'departOn' => '2026-11-05', 'city' => 'Seattle'],
            ['id' => 5, 'arriveOn' => '2026-11-05', 'departOn' => '2026-11-08', 'city' => 'Portland'],
        ], '2026-11-05');

        self::assertSame('Welcome to Seattle', $label['text']);
        self::assertTrue($label['italic']);

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
                new EnvelopeService($pdo, $actor, $catalog, new TargetService($pdo, $actor)),
                new PayCycleService($pdo, $actor),
                $trips,
            );

            $plain = $dashboard->show('2026-11-05');
            self::assertSame('Planner', $plain['heading']);
            self::assertFalse($plain['headingItalic']);

            $books = $this->books($pdo);
            $earlier = $trips->create([
                'placeName' => 'Stay 3',
                'arriveOn' => '2026-11-01',
                'departOn' => '2026-11-05',
                'latitude' => '47.6062',
                'longitude' => '-122.3321',
                'city' => 'Seattle',
                'region' => 'WA',
                'totalMinor' => 0,
                'amountDueMinor' => 0,
                'today' => '2026-11-05',
            ]);
            $later = $trips->create([
                'placeName' => 'Stay 4',
                'arriveOn' => '2026-11-05',
                'departOn' => '2026-11-08',
                'latitude' => '45.5152',
                'longitude' => '-122.6784',
                'city' => 'Portland',
                'region' => 'OR',
                'totalMinor' => 0,
                'amountDueMinor' => 0,
                'today' => '2026-11-05',
            ]);
            self::assertSame($books, $this->books($pdo));

            $before = $this->stayRows($trips->list('2026-11-05', false));
            $sheet = $dashboard->show('2026-11-05');
            self::assertSame(
                PlannerHeading::phrase((int) $earlier['id'], 'Seattle'),
                $sheet['heading'],
            );
            self::assertTrue($sheet['headingItalic']);
            self::assertStringNotContainsString('Portland', $sheet['heading']);
            self::assertSame($before, $this->stayRows($trips->list('2026-11-05', false)));
            self::assertSame($books, $this->books($pdo));

            $trips->update((int) $earlier['id'], [
                'placeName' => 'Stay 3',
                'arriveOn' => '2026-11-01',
                'departOn' => '2026-11-05',
                'latitude' => '47.6062',
                'longitude' => '-122.3321',
                'city' => null,
                'region' => 'WA',
                'totalMinor' => 0,
                'amountDueMinor' => 0,
                'today' => '2026-11-05',
            ]);
            $cleared = $dashboard->show('2026-11-05');
            self::assertSame('Planner', $cleared['heading']);
            self::assertFalse($cleared['headingItalic']);
            self::assertSame('Portland', $trips->get((int) $later['id'], '2026-11-05')['city']);
            self::assertSame($books, $this->books($pdo));
        } finally {
            unset($pdo);
            if (is_file($path)) {
                unlink($path);
            }
        }
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
