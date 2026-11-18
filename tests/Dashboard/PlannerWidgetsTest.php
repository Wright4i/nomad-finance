<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Auth\Actor;
use App\Calendar\CalendarEntryService;
use App\Auth\AuthService;
use App\Bill\Occurrences;
use App\Database\Database;
use App\Dashboard\DashboardService;
use App\Dashboard\PlannerWidgets;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Trip\Drive;
use App\Trip\TripService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlannerWidgetsTest extends TestCase
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

    public function testAnArrivalTodayOrTomorrowLeadsAndALaterArrivalDoesNot(): void
    {
        $underway = $this->stay(1, 'Underway', 'Reno', 'NV', '2026-11-01', '2026-11-10');
        $tomorrow = $this->stay(2, 'Tomorrow', 'Bend', 'OR', '2026-11-06', '2026-11-08');
        $later = $this->stay(3, 'Later', 'Boise', 'ID', '2026-11-07', '2026-11-09');
        $today = $this->stay(4, 'Today', 'Tacoma', 'WA', '2026-11-05', '2026-11-07');

        $lead = PlannerWidgets::stay([$underway, $tomorrow, $later], '2026-11-05');
        self::assertNotNull($lead);
        self::assertSame('Tomorrow', $lead['placeName']);
        self::assertSame('Bend', $lead['city']);
        self::assertSame('OR', $lead['region']);

        $arrived = PlannerWidgets::stay([$underway, $today, $tomorrow], '2026-11-05');
        self::assertNotNull($arrived);
        self::assertSame('Today', $arrived['placeName']);

        $covered = PlannerWidgets::stay([$underway, $later], '2026-11-05');
        self::assertNotNull($covered);
        self::assertSame('Underway', $covered['placeName']);
        self::assertSame('Reno', $covered['city']);

        self::assertNull(PlannerWidgets::stay([$later], '2026-11-05'));
    }

    public function testTheSoonestPaydayAndAccountTotals(): void
    {
        $soonest = PlannerWidgets::payday([
            ['kind' => 'payday', 'on' => '2026-11-04', 'payor' => 'Earlier', 'paycheckMinor' => 100],
            ['kind' => 'bill', 'on' => '2026-11-05', 'name' => 'Rent'],
            ['kind' => 'payday', 'on' => '2026-11-05', 'payor' => 'Today', 'paycheckMinor' => 2500],
            ['kind' => 'payday', 'on' => '2026-11-08', 'payor' => 'Later', 'paycheckMinor' => 900],
        ], '2026-11-05');
        self::assertSame(['payor' => 'Today', 'on' => '2026-11-05', 'paycheckMinor' => 2500], $soonest);

        $totals = PlannerWidgets::accountTotals([
            ['type' => 'checking', 'side' => 'asset', 'balanceMinor' => 100],
            ['type' => 'checking', 'side' => 'asset', 'balanceMinor' => 50],
            ['type' => 'savings', 'side' => 'asset', 'balanceMinor' => 400],
            ['type' => 'credit', 'side' => 'liability', 'balanceMinor' => 40],
            ['type' => 'loan', 'side' => 'liability', 'balanceMinor' => 500],
            ['type' => 'other', 'side' => 'liability', 'balanceMinor' => 12],
            ['type' => 'other', 'side' => 'asset', 'balanceMinor' => 7],
            ['type' => 'fuel', 'side' => 'liability', 'balanceMinor' => 0],
        ]);
        self::assertSame(
            ['savings', 'checking', 'other', 'fuel', 'other', 'credit', 'loan'],
            array_column($totals, 'type'),
        );
        self::assertSame(
            ['asset', 'asset', 'asset', 'liability', 'liability', 'liability', 'liability'],
            array_column($totals, 'side'),
        );
        self::assertSame(400, $totals[0]['balanceMinor']);
        self::assertSame(150, $totals[1]['balanceMinor']);
        self::assertSame(7, $totals[2]['balanceMinor']);
        self::assertSame('liability', $totals[4]['side']);
        self::assertSame(12, $totals[4]['balanceMinor']);
        self::assertSame(0, $totals[3]['balanceMinor']);
        self::assertNotContains('cash', array_column($totals, 'type'));

        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-11-01', '1 month', $_SESSION['user_id']]);
        $sheet = $this->dashboard->show('2026-11-05');
        self::assertSame([
            'payor' => 'Side job',
            'on' => '2026-11-16',
            'paycheckMinor' => 44000,
        ], $sheet['payday']);
        self::assertSame(2, $sheet['payorCount']);
        self::assertNull($sheet['stay']);
        self::assertSame(PlannerWidgets::ORDER, $sheet['plannerOrder']);
        $byType = [];
        foreach ($sheet['accountTotals'] as $row) {
            $byType[$row['type'] . ':' . $row['side']] = $row;
        }
        self::assertSame(266000, $byType['checking:asset']['balanceMinor']);
        self::assertSame(5000000, $byType['savings:asset']['balanceMinor']);
        self::assertSame(11500, $byType['cash:asset']['balanceMinor']);
        self::assertSame(173300, $byType['credit:liability']['balanceMinor']);
        self::assertSame(570000, $byType['loan:liability']['balanceMinor']);
        self::assertSame(0, $byType['fuel:liability']['balanceMinor']);
        $other = array_values(array_filter(
            $sheet['accountTotals'],
            static fn (array $row): bool => $row['type'] === 'other',
        ));
        self::assertSame(
            [
                ['type' => 'other', 'balanceMinor' => 50000, 'side' => 'asset'],
                ['type' => 'other', 'balanceMinor' => 8500, 'side' => 'liability'],
            ],
            $other,
        );
        self::assertSame(
            ['savings', 'checking', 'other', 'cash', 'fuel', 'other', 'credit', 'loan'],
            array_column($sheet['accountTotals'], 'type'),
        );

        $received = ['on' => '2026-11-01', 'paycheckMinor' => 100];
        $today = ['on' => '2026-11-05', 'paycheckMinor' => 200];
        $later = ['on' => '2026-11-08', 'paycheckMinor' => 300];
        $nextMonth = ['on' => '2026-12-01', 'paycheckMinor' => 400];
        self::assertSame(500, PlannerWidgets::stillComing([$received, $today, $later, $nextMonth], '2026-11', '2026-11-05'));
        self::assertSame(400, PlannerWidgets::stillComing([$received, $today, $later, $nextMonth], '2026-12', '2026-11-05'));
        self::assertSame(500, PlannerWidgets::stillComingBetween([$received, $today, $later, $nextMonth], '2026-11-01', '2026-11-30', '2026-11-05'));
        self::assertSame(400, PlannerWidgets::stillComingBetween([$received, $today, $later, $nextMonth], '2026-12-01', '2026-12-31', '2026-11-05'));
        self::assertSame(44000, $sheet['incomeComing']);
    }

    public function testPayStayAndBillsFollowTheAppToday(): void
    {
        $today = $this->appToday();
        $clock = new \DateTimeImmutable($today);
        $yesterday = $clock->modify('-1 day')->format('Y-m-d');
        $tomorrow = $clock->modify('+1 day')->format('Y-m-d');
        $later = $clock->modify('+4 days')->format('Y-m-d');
        $month = $clock->format('Y-m');

        $coming = PlannerWidgets::stillComing([
            ['on' => $yesterday, 'paycheckMinor' => 100],
            ['on' => $today, 'paycheckMinor' => 200],
            ['on' => $tomorrow, 'paycheckMinor' => 300],
        ], $month, $today);
        $expected = 200 + (str_starts_with($tomorrow, $month) ? 300 : 0);
        self::assertSame($expected, $coming);
        if (str_starts_with($yesterday, $month)) {
            self::assertNotSame($expected + 100, $coming);
        }

        $payday = PlannerWidgets::payday([
            ['kind' => 'payday', 'on' => $yesterday, 'payor' => 'Earlier', 'paycheckMinor' => 100],
            ['kind' => 'payday', 'on' => $tomorrow, 'payor' => 'Next', 'paycheckMinor' => 50],
            ['kind' => 'payday', 'on' => $today, 'payor' => 'Now', 'paycheckMinor' => 75],
        ], $today);
        self::assertNotNull($payday);
        self::assertSame('Now', $payday['payor']);
        self::assertSame($today, $payday['on']);

        $underway = $this->stay(1, 'Underway', 'Reno', 'NV', $clock->modify('-3 days')->format('Y-m-d'), $clock->modify('+6 days')->format('Y-m-d'));
        $arrival = $this->stay(2, 'Arrival', 'Bend', 'OR', $tomorrow, $clock->modify('+6 days')->format('Y-m-d'));
        $lead = PlannerWidgets::stay([$underway, $arrival], $today);
        self::assertNotNull($lead);
        self::assertSame('Arrival', $lead['placeName']);
        $covered = PlannerWidgets::stay([$underway], $today);
        self::assertNotNull($covered);
        self::assertSame('Underway', $covered['placeName']);

        $sheet = $this->dashboard->show($today);
        if ($sheet['payday'] === null) {
            self::assertGreaterThan('2026-11-16', $today);
        } else {
            self::assertGreaterThanOrEqual($today, $sheet['payday']['on']);
        }
        if ($sheet['stay'] !== null) {
            $stay = $sheet['stay'];
            $leads = $stay['arriveOn'] === $today || $stay['arriveOn'] === $tomorrow;
            $covers = $stay['arriveOn'] <= $today && $today <= $stay['departOn'];
            self::assertTrue($leads || $covers);
        }

        $paidOn = $clock->modify('-2 days')->format('Y-m-d');
        $future = $this->occurrence('Later Bill', 'once', $later, $later, null);
        $settled = $this->occurrence('Paid Weekly Bill', 'weekly', $paidOn, $later, $paidOn);
        self::assertSame([[$later, false]], $this->dated(Occurrences::inMonth([$future], substr($later, 0, 7))));
        $paidDates = $this->dated(Occurrences::inMonth([$settled], substr($paidOn, 0, 7)));
        self::assertContains([$paidOn, true], $paidDates);
        foreach ($paidDates as $mark) {
            if ($mark[0] > $today) {
                self::assertFalse($mark[1]);
            }
        }
        if (substr($later, 0, 7) === substr($paidOn, 0, 7)) {
            self::assertContains([$later, false], $paidDates);
        }
    }

    public function testASavedOrderRoundTripsAndAPartialOrderFallsBack(): void
    {
        $custom = ['bills', 'net', 'income', 'calendar', 'trips', 'accounts', 'envelopes'];
        self::assertSame($custom, PlannerWidgets::order(PlannerWidgets::encode($custom)));
        foreach (['["bills"]', '["income","nope"]', '["income","income","calendar","trips","accounts","envelopes"]', '', null, ['trips']] as $stored) {
            $order = PlannerWidgets::order($stored);
            self::assertSame(PlannerWidgets::ORDER, $order);
            foreach (PlannerWidgets::ORDER as $widget) {
                self::assertContains($widget, $order);
            }
        }

        $owner = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();
        $demo = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'demo'")->fetchColumn();
        $saved = $this->auth->updateSettings(['plannerOrder' => $custom]);
        self::assertSame($custom, $saved['plannerOrder']);
        self::assertSame($custom, $this->auth->current()['plannerOrder']);
        self::assertSame(json_encode($custom), $this->pdo->query("SELECT planner_order FROM user WHERE role = 'demo'")->fetchColumn());
        self::assertSame($owner, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());
        self::assertSame($demo, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'demo'")->fetchColumn());

        try {
            $this->auth->updateSettings(['plannerOrder' => ['bills']]);
            self::fail('A partial order should be refused.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('plannerOrder', $e->fields);
        }
        self::assertSame($custom, $this->auth->current()['plannerOrder']);

        $this->pdo->prepare('UPDATE user SET planner_order = ? WHERE role = \'demo\'')->execute(['["income","nope"]']);
        self::assertSame(PlannerWidgets::ORDER, $this->auth->current()['plannerOrder']);
    }

    public function testAWidgetStepsOnePlaceAndTheEndsStayPut(): void
    {
        $order = PlannerWidgets::ORDER;
        $forward = PlannerWidgets::step($order, 'income', 1);
        self::assertSame(['net', 'calendar', 'income', 'trips', 'accounts', 'envelopes', 'bills'], $forward);
        self::assertSame($order, PlannerWidgets::step($forward, 'income', -1));
        self::assertSame(['income', 'net', 'calendar', 'trips', 'accounts', 'envelopes', 'bills'], PlannerWidgets::step($order, 'income', -1));
        self::assertSame($order, PlannerWidgets::step($order, 'net', -1));
        self::assertSame($order, PlannerWidgets::step($order, 'bills', 1));
        $later = PlannerWidgets::step($order, 'bills', -1);
        self::assertSame(['net', 'income', 'calendar', 'trips', 'accounts', 'bills', 'envelopes'], $later);
        self::assertSame($order, PlannerWidgets::step($order, 'income', 2));
        self::assertSame($order, PlannerWidgets::step($order, 'missing', 1));

        $saved = $this->auth->updateSettings(['plannerOrder' => $forward]);
        self::assertSame($forward, $saved['plannerOrder']);
        self::assertSame(json_encode($forward), $this->pdo->query("SELECT planner_order FROM user WHERE role = 'demo'")->fetchColumn());
    }

    public function testAWidgetSpanIsOneThroughSixAndRestores(): void
    {
        $defaults = [
            'net' => 3,
            'income' => 3,
            'calendar' => 2,
            'trips' => 2,
            'accounts' => 2,
            'envelopes' => 6,
            'bills' => 6,
        ];
        self::assertSame([1, 2, 3, 4, 5, 6], PlannerWidgets::SPAN_VALUES);
        self::assertSame($defaults, PlannerWidgets::SPANS);
        self::assertSame($defaults, PlannerWidgets::spans(null));
        self::assertSame($defaults, $this->dashboard->show('2026-11-05')['plannerSpans']);
        foreach ($defaults as $span) {
            self::assertContains($span, PlannerWidgets::SPAN_VALUES);
        }

        $custom = [
            'net' => 2,
            'income' => 6,
            'calendar' => 1,
            'trips' => 5,
            'accounts' => 4,
            'envelopes' => 2,
            'bills' => 3,
        ];
        self::assertSame($custom, PlannerWidgets::spans(PlannerWidgets::encodeSpans($custom)));
        foreach ([
            null,
            '',
            '{"income":5}',
            '{"income":2}',
            '{"income":"6","calendar":2,"trips":2,"accounts":2,"envelopes":6,"bills":6}',
            '[]',
            'nope',
            [2, 2, 2, 2, 2, 6],
        ] as $stored) {
            self::assertSame($defaults, PlannerWidgets::spans($stored));
        }

        $owner = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn();
        $demo = $this->pdo->query("SELECT password_hash FROM user WHERE role = 'demo'")->fetchColumn();
        $saved = $this->auth->updateSettings(['plannerSpans' => $custom]);
        self::assertSame($custom, $saved['plannerSpans']);
        self::assertSame($custom, $this->auth->current()['plannerSpans']);
        self::assertSame(json_encode($custom), $this->pdo->query("SELECT planner_spans FROM user WHERE role = 'demo'")->fetchColumn());
        self::assertSame(PlannerWidgets::ORDER, $saved['plannerOrder']);
        self::assertSame($owner, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'owner'")->fetchColumn());
        self::assertSame($demo, $this->pdo->query("SELECT password_hash FROM user WHERE role = 'demo'")->fetchColumn());

        $order = ['bills', 'net', 'income', 'calendar', 'trips', 'accounts', 'envelopes'];
        $kept = $this->auth->updateSettings(['plannerOrder' => $order]);
        self::assertSame($order, $kept['plannerOrder']);
        self::assertSame($custom, $kept['plannerSpans']);

        foreach ([
            ['income' => 4],
            ['net' => 3, 'income' => 0, 'calendar' => 2, 'trips' => 2, 'accounts' => 2, 'envelopes' => 6, 'bills' => 6],
            ['net' => 3, 'income' => 7, 'calendar' => 2, 'trips' => 2, 'accounts' => 2, 'envelopes' => 6, 'bills' => 6],
            ['net' => 3, 'income' => '6', 'calendar' => 2, 'trips' => 2, 'accounts' => 2, 'envelopes' => 6, 'bills' => 6],
            ['net' => 3, 'income' => 2, 'calendar' => 2, 'trips' => 2, 'accounts' => 2, 'envelopes' => 6, 'bills' => 6, 'extra' => 1],
            ['income' => 2, 'calendar' => 2, 'trips' => 2, 'accounts' => 2, 'envelopes' => 6, 'bills' => 6],
            [1, 2, 3, 4, 5, 6],
        ] as $bad) {
            try {
                $this->auth->updateSettings(['plannerSpans' => $bad]);
                self::fail('An invalid span should be refused.');
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
                self::assertArrayHasKey('plannerSpans', $e->fields);
            }
        }
        self::assertSame($custom, $this->auth->current()['plannerSpans']);

        $this->pdo->prepare('UPDATE user SET planner_spans = ? WHERE role = \'demo\'')->execute(['{"income":9}']);
        self::assertSame($defaults, $this->auth->current()['plannerSpans']);
        self::assertSame($order, $this->auth->current()['plannerOrder']);
    }

    private function appToday(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function occurrence(string $name, string $recurrence, string $anchor, string $next, ?string $paidOn): array
    {
        return [
            'id' => 7,
            'name' => $name,
            'type' => 'rent',
            'otherLabel' => null,
            'amountMinor' => 1000,
            'currency' => 'USD',
            'exponent' => 2,
            'recurrence' => $recurrence,
            'anchorDate' => $anchor,
            'nextDueDate' => $next,
            'lastPaidOn' => $paidOn,
            'status' => 'active',
        ];
    }

    /**
     * @param list<array{on:string,paid:bool}> $marks
     * @return list<array{0:string,1:bool}>
     */
    private function dated(array $marks): array
    {
        return array_map(static fn (array $mark): array => [$mark['on'], $mark['paid']], $marks);
    }

    /** @return array{id:int,placeName:string,city:string,region:string,arriveOn:string,departOn:string} */
    private function stay(int $id, string $place, string $city, string $region, string $arrive, string $depart): array
    {
        return [
            'id' => $id,
            'placeName' => $place,
            'city' => $city,
            'region' => $region,
            'arriveOn' => $arrive,
            'departOn' => $depart,
        ];
    }
}
