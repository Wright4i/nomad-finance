<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Category\CategoryService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use App\Trip\Drive;
use App\Trip\Fuel;
use App\Trip\TripService;
use App\Vehicle\VehicleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TripRulesTest extends TestCase
{
    private PDO $pdo;

    private TripService $trips;

    private AccountService $accounts;

    private string $path;

    private int $accountId;

    private int $categoryId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $catalog = new CurrencyCatalog();
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
        $this->trips = new TripService($this->pdo, $actor, $catalog, new Drive(false));
        $account = $this->accounts->create([
            'name' => 'Checking 1',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 20000,
        ]);
        $this->accountId = (int) $account['id'];
        $category = $categories->create(['name' => 'Needs 1', 'group' => 'needs']);
        $this->categoryId = (int) $category['id'];
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testABalanceDueDateDoesNotPost(): void
    {
        $trip = $this->trips->create([
            'placeName' => 'Stay 1',
            'note' => 'Site 34',
            'arriveOn' => '2026-11-20',
            'departOn' => '2026-11-24',
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'accountId' => $this->accountId,
            'totalMinor' => 12000,
            'amountDueMinor' => 12000,
            'balanceDueOn' => '2026-11-22',
            'today' => '2026-11-03',
        ]);
        self::assertSame('2026-11-22', $trip['balanceDueOn']);
        self::assertSame(12000, $trip['amountDueMinor']);
        self::assertSame(12000, $trip['totalMinor']);
        self::assertSame(0, $trip['depositMinor']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);

        $updated = $this->trips->update((int) $trip['id'], [
            'placeName' => 'Stay 1',
            'note' => 'Site 34',
            'arriveOn' => '2026-11-20',
            'departOn' => '2026-11-24',
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'accountId' => $this->accountId,
            'totalMinor' => 12000,
            'amountDueMinor' => 12000,
            'balanceDueOn' => '2026-11-25',
            'today' => '2026-11-03',
        ]);
        self::assertSame('2026-11-25', $updated['balanceDueOn']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);

        $prefilled = $this->stay(8000, 5000, '2026-12-01', '2026-12-05', 'Stay 3');
        self::assertSame('2026-12-01', $prefilled['balanceDueOn']);
        self::assertSame(8000, $prefilled['totalMinor']);
        self::assertSame(5000, $prefilled['amountDueMinor']);
        self::assertSame(3000, $prefilled['depositMinor']);
        $free = $this->stay(0, 0, '2027-01-02', '2027-01-04', 'Stay 4');
        self::assertSame('2027-01-02', $free['balanceDueOn']);
        self::assertSame(0, $free['amountDueMinor']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testSavingATripDoesNotMoveTheBalance(): void
    {
        $trip = $this->stay(12000, 12000, '2026-11-20', '2026-11-24');
        self::assertSame('upcoming', $trip['status']);
        self::assertSame(0, $trip['depositMinor']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testAmountDueCannotExceedTheTotal(): void
    {
        try {
            $this->stay(1000, 2000, '2026-11-20', '2026-11-21');
            self::fail('An amount due above the total was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testSavingLodgingDoesNotChangeTheTypedBalance(): void
    {
        $trip = $this->stay(8000, 5000, '2026-12-01', '2026-12-05');
        self::assertSame(3000, $trip['depositMinor']);
        self::assertFalse($trip['depositCharged']);
        self::assertFalse($trip['dueCharged']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testArrivalDoesNotChargeTheAmountDue(): void
    {
        $trip = $this->stay(8000, 8000, '2026-11-03', '2026-11-06');
        self::assertSame('underway', $trip['status']);
        self::assertFalse($trip['dueCharged']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testDeleteWithoutConfirmationLeavesTheStayAndTheBalance(): void
    {
        $trip = $this->stay(8000, 5000, '2026-12-01', '2026-12-05');
        try {
            $this->trips->delete((int) $trip['id'], false);
            self::fail('The stay was deleted without confirmation.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
        $this->trips->delete((int) $trip['id'], true);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testRouteOrderFollowsArrival(): void
    {
        $later = $this->stay(0, 0, '2026-12-01', '2026-12-02', 'Stay 2');
        $sooner = $this->stay(0, 0, '2026-11-01', '2026-11-02', 'Stay 1');
        $names = array_column($this->trips->list('2026-11-03'), 'placeName');
        self::assertSame([(string) $sooner['placeName'], (string) $later['placeName']], $names);
        $colors = array_column($this->trips->list('2026-11-03'), 'color');
        self::assertSame(['poppy', 'lagoon'], $colors);
    }

    public function testALaterStayCarriesAFuelLabelAndTheFirstDoesNot(): void
    {
        $first = $this->trips->create([
            'placeName' => 'Stay 1',
            'arriveOn' => '2026-11-20',
            'departOn' => '2026-11-24',
            'latitude' => '36.1699',
            'longitude' => '-115.1398',
            'accountId' => $this->accountId,
            'totalMinor' => 1000,
            'amountDueMinor' => 1000,
            'today' => '2026-11-03',
        ]);
        $second = $this->trips->create([
            'placeName' => 'Stay 2',
            'arriveOn' => '2026-12-01',
            'departOn' => '2026-12-05',
            'latitude' => '34.0522',
            'longitude' => '-118.2437',
            'accountId' => $this->accountId,
            'totalMinor' => 1000,
            'amountDueMinor' => 1000,
            'today' => '2026-11-03',
        ]);
        $legs = (new Drive(false))->legs([
            ['id' => (int) $first['id'], 'latitude' => '36.1699', 'longitude' => '-115.1398'],
            ['id' => (int) $second['id'], 'latitude' => '34.0522', 'longitude' => '-118.2437'],
        ]);
        $expected = Fuel::estimate($legs[0]['miles'], null, null);
        $again = $this->trips->get((int) $second['id'], '2026-11-03');
        $opened = $this->trips->get((int) $first['id'], '2026-11-03');

        self::assertNull($opened['fuel']['miles']);
        self::assertNull($opened['fuel']['milesPerGallon']);
        self::assertNull($opened['fuel']['costMinor']);
        self::assertNull($opened['fuel']['priceMinor']);
        self::assertSame($expected, $again['fuel']);
        self::assertArrayHasKey('milesPerGallon', $again['fuel']);
        self::assertArrayHasKey('priceMinor', $again['fuel']);
        self::assertSame($legs[0]['miles'], $again['fuel']['miles']);
        self::assertGreaterThan(0, $again['fuel']['miles']);
        self::assertNull($again['fuel']['costMinor']);
        self::assertNull($again['fuel']['priceMinor']);
    }

    public function testARoadThatDidNotAnswerIsNotAskedAgainRightAway(): void
    {
        $from = ['id' => 1, 'latitude' => '36.1699', 'longitude' => '-115.1398'];
        $to = ['id' => 2, 'latitude' => '34.0522', 'longitude' => '-118.2437'];
        $url = 'https://router.project-osrm.org/route/v1/driving/'
            . rawurlencode($from['longitude']) . ',' . rawurlencode($from['latitude']) . ';'
            . rawurlencode($to['longitude']) . ',' . rawurlencode($to['latitude'])
            . '?overview=simplified&geometries=geojson';
        $path = sys_get_temp_dir() . '/nomad-road-' . hash('sha256', $url) . '.json.miss';
        file_put_contents($path, '1');
        try {
            $started = hrtime(true);
            $legs = (new Drive(true))->legs([$from, $to]);
            $elapsed = (hrtime(true) - $started) / 1e6;
        } finally {
            @unlink($path);
        }

        self::assertLessThan(200, $elapsed);
        self::assertFalse($legs[0]['driving']);
        self::assertSame(0, $legs[0]['miles']);
    }

    public function testAHandoffDayIsAllowedAndASharedNightIsKept(): void
    {
        $first = $this->stay(0, 0, '2026-11-01', '2026-11-05', 'Stay 1');
        $second = $this->stay(0, 0, '2026-11-05', '2026-11-08', 'Stay 2');
        $first = $this->trips->get((int) $first['id'], '2026-11-03');
        $second = $this->trips->get((int) $second['id'], '2026-11-03');
        self::assertSame('2026-11-05', $second['arriveOn']);
        self::assertFalse($first['moveLater']);
        self::assertFalse($second['moveEarlier']);
        $nested = $this->stay(0, 0, '2026-11-04', '2026-11-05', 'Stay 3');
        self::assertSame('Stay 3', $nested['placeName']);
        self::assertTrue($nested['moveEarlier']);
        $updated = $this->trips->update((int) $first['id'], [
            'placeName' => 'Stay 1',
            'arriveOn' => '2026-11-06',
            'departOn' => '2026-11-09',
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'today' => '2026-11-03',
        ]);
        self::assertSame('2026-11-09', $updated['departOn']);
    }

    public function testChangingDatesReseatsTheRouteForOverlappingStays(): void
    {
        $this->stay(0, 0, '2026-07-21', '2026-07-26', 'River Park');
        $this->stay(0, 0, '2026-07-26', '2026-07-28', 'Valley KOA');
        $this->stay(0, 0, '2026-10-23', '2026-10-25', 'Desert Palms');
        $fly = $this->trips->create($this->fields([
            'placeName' => 'Conference',
            'arriveOn' => '2026-11-08',
            'departOn' => '2026-11-10',
            'travel' => 'fly',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $fly = $this->trips->update((int) $fly['id'], $this->fields([
            'placeName' => 'Conference',
            'arriveOn' => '2026-07-23',
            'departOn' => '2026-07-25',
            'travel' => 'fly',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        self::assertSame(1, $fly['routeOrder']);
        self::assertTrue($fly['moveEarlier']);
        self::assertFalse($fly['moveLater']);
        $legs = $this->trips->route('2026-07-24');
        $ids = array_map(static fn (array $leg): int => $leg['toId'], $legs);
        self::assertContains((int) $fly['id'], $ids);
        $flyLeg = null;
        foreach ($legs as $leg) {
            if ($leg['toId'] === (int) $fly['id']) {
                $flyLeg = $leg;
                break;
            }
        }
        self::assertIsArray($flyLeg);
        self::assertSame('fly', $flyLeg['travel']);
    }

    public function testANestedStayTradesPlacesAndFuelFollowsTheRoute(): void
    {
        $resort = $this->trips->create($this->fields([
            'placeName' => 'Desert Palms RV Resort',
            'arriveOn' => '2026-10-23',
            'departOn' => '2026-11-23',
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $guest = $this->trips->create($this->fields([
            'placeName' => 'Guest house',
            'arriveOn' => '2026-11-22',
            'departOn' => '2026-11-23',
            'latitude' => '36.27',
            'longitude' => '-115.24',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $resort = $this->trips->get((int) $resort['id'], '2026-11-06');
        $guest = $this->trips->get((int) $guest['id'], '2026-11-06');
        self::assertTrue($resort['moveLater']);
        self::assertFalse($resort['moveEarlier']);
        self::assertTrue($guest['moveEarlier']);
        self::assertFalse($guest['moveLater']);
        $before = $this->trips->route('2026-11-06');
        self::assertSame([(int) $resort['id'], (int) $guest['id']], [$before[0]['fromId'], $before[0]['toId']]);
        $guestMiles = $this->trips->get((int) $guest['id'], '2026-11-06')['fuel']['miles'];
        self::assertSame($before[0]['miles'], $guestMiles);
        self::assertGreaterThan(0, $guestMiles);

        $this->trips->move((int) $guest['id'], 'earlier');
        $after = $this->trips->route('2026-11-06');
        self::assertSame([(int) $guest['id'], (int) $resort['id']], [$after[0]['fromId'], $after[0]['toId']]);
        $resortAfter = $this->trips->get((int) $resort['id'], '2026-11-06');
        self::assertSame($after[0]['miles'], $resortAfter['fuel']['miles']);
        self::assertSame($guestMiles, $resortAfter['fuel']['miles']);
        self::assertNull($this->trips->get((int) $guest['id'], '2026-11-06')['fuel']['miles']);
        self::assertTrue($resortAfter['moveEarlier']);
        self::assertFalse($resortAfter['moveLater']);

        $this->trips->move((int) $guest['id'], 'earlier');
        $still = $this->trips->route('2026-11-06');
        self::assertSame((int) $guest['id'], $still[0]['fromId']);
        try {
            $this->trips->move((int) $guest['id'], 'up');
            self::fail('An unknown route move was accepted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testAStayThatEndsLaterStaysSecondAndADayBetweenBlocksTheJump(): void
    {
        $resort = $this->trips->create($this->fields([
            'placeName' => 'Desert Palms RV Resort',
            'arriveOn' => '2026-10-23',
            'departOn' => '2026-11-23',
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $later = $this->trips->create($this->fields([
            'placeName' => 'After resort',
            'arriveOn' => '2026-11-22',
            'departOn' => '2026-11-24',
            'latitude' => '36.30',
            'longitude' => '-115.30',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $resort = $this->trips->get((int) $resort['id'], '2026-11-06');
        $later = $this->trips->get((int) $later['id'], '2026-11-06');
        self::assertFalse($resort['moveLater']);
        self::assertFalse($later['moveEarlier']);
        $this->trips->move((int) $later['id'], 'earlier');
        $kept = $this->trips->route('2026-11-06');
        self::assertSame((int) $resort['id'], $kept[0]['fromId']);
        self::assertSame((int) $later['id'], $kept[0]['toId']);

        $this->trips->delete((int) $later['id'], true);
        $middle = $this->trips->create($this->fields([
            'placeName' => 'Middle camp',
            'arriveOn' => '2026-11-10',
            'departOn' => '2026-11-12',
            'latitude' => '36.20',
            'longitude' => '-115.20',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $guest = $this->trips->create($this->fields([
            'placeName' => 'Guest house',
            'arriveOn' => '2026-11-22',
            'departOn' => '2026-11-23',
            'latitude' => '36.27',
            'longitude' => '-115.24',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $fresh = [];
        foreach ($this->trips->list('2026-11-06') as $trip) {
            $fresh[$trip['placeName']] = $trip;
        }
        self::assertTrue($fresh['Desert Palms RV Resort']['moveLater']);
        self::assertTrue($fresh['Middle camp']['moveEarlier']);
        self::assertFalse($fresh['Middle camp']['moveLater']);
        self::assertFalse($fresh['Guest house']['moveEarlier']);
        $this->trips->move((int) $guest['id'], 'earlier');
        $legs = $this->trips->route('2026-11-06');
        self::assertSame((int) $resort['id'], $legs[0]['fromId']);
        self::assertSame((int) $middle['id'], $legs[0]['toId']);
        self::assertSame((int) $guest['id'], $legs[1]['toId']);
    }

    public function testARatingIsStoredAsHalfStarsAndDoesNotPost(): void
    {
        $balance = $this->accounts->get($this->accountId)['balanceMinor'];
        $trip = $this->trips->create($this->fields([
            'ratingHalfStars' => 7,
            'ratingComment' => '  Comment 1  ',
        ]));
        self::assertSame(7, $trip['ratingHalfStars']);
        self::assertSame('Comment 1', $trip['ratingComment']);
        $stored = $this->pdo->query(
            'SELECT rating_half_stars, typeof(rating_half_stars) AS kind, rating_comment FROM trips WHERE id = ' . (int) $trip['id']
        )->fetch();
        self::assertIsArray($stored);
        self::assertSame('integer', $stored['kind']);
        self::assertSame(7, (int) $stored['rating_half_stars']);
        self::assertSame('Comment 1', $stored['rating_comment']);

        $listed = $this->trips->list('2026-11-03');
        self::assertSame(7, $listed[0]['ratingHalfStars']);
        self::assertSame('Comment 1', $listed[0]['ratingComment']);
        $again = $this->trips->get((int) $trip['id'], '2026-11-03');
        self::assertSame(7, $again['ratingHalfStars']);
        self::assertSame('Comment 1', $again['ratingComment']);

        $kept = $this->trips->update((int) $trip['id'], $this->fields());
        self::assertSame(7, $kept['ratingHalfStars']);
        self::assertSame('Comment 1', $kept['ratingComment']);

        $half = $this->trips->update((int) $trip['id'], $this->fields([
            'ratingHalfStars' => 1,
            'ratingComment' => '   ',
        ]));
        self::assertSame(1, $half['ratingHalfStars']);
        self::assertNull($half['ratingComment']);

        $full = $this->trips->update((int) $trip['id'], $this->fields([
            'ratingHalfStars' => 10,
            'ratingComment' => 'Comment 2',
        ]));
        self::assertSame(10, $full['ratingHalfStars']);
        self::assertSame('Comment 2', $full['ratingComment']);

        try {
            $this->trips->update((int) $trip['id'], $this->fields([
                'ratingHalfStars' => 10,
                'ratingComment' => str_repeat('a', 2001),
            ]));
            self::fail('A comment longer than the note was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('ratingComment', $e->fields);
        }
        self::assertSame('Comment 2', $this->trips->get((int) $trip['id'], '2026-11-03')['ratingComment']);

        $zero = $this->trips->update((int) $trip['id'], $this->fields([
            'ratingHalfStars' => 0,
            'ratingComment' => '',
        ]));
        self::assertSame(0, $zero['ratingHalfStars']);
        self::assertNull($zero['ratingComment']);
        self::assertSame($balance, $this->accounts->get($this->accountId)['balanceMinor']);

        $plain = $this->stay(0, 0, '2026-12-01', '2026-12-02', 'Stay 2');
        self::assertNull($plain['ratingHalfStars']);
        self::assertNull($plain['ratingComment']);
    }

    public function testHalfStarsOutsideZeroThroughFiveAreRefused(): void
    {
        $trip = $this->trips->create($this->fields([
            'ratingHalfStars' => 4,
            'ratingComment' => 'Comment 1',
        ]));
        foreach ([11, -1, 3.5] as $bad) {
            try {
                $this->trips->update((int) $trip['id'], $this->fields([
                    'ratingHalfStars' => $bad,
                    'ratingComment' => 'Comment 1',
                ]));
                self::fail('A rating outside 0 to 5 stars was stored.');
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
                self::assertArrayHasKey('ratingHalfStars', $e->fields);
            }
        }
        $kept = $this->trips->get((int) $trip['id'], '2026-11-03');
        self::assertSame(4, $kept['ratingHalfStars']);
        self::assertSame('Comment 1', $kept['ratingComment']);
    }

    public function testACommentWithoutARatingIsRefused(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();
        try {
            $this->trips->create($this->fields(['ratingComment' => 'Comment 1']));
            self::fail('A comment was stored on an unrated stay.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('ratingComment', $e->fields);
        }
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn());

        $trip = $this->trips->create($this->fields());
        self::assertNull($trip['ratingHalfStars']);
        try {
            $this->trips->update((int) $trip['id'], $this->fields(['ratingComment' => 'Comment 1']));
            self::fail('A comment was stored on an unrated stay.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('ratingComment', $e->fields);
        }
        $kept = $this->trips->get((int) $trip['id'], '2026-11-03');
        self::assertNull($kept['ratingHalfStars']);
        self::assertNull($kept['ratingComment']);

        $this->trips->update((int) $trip['id'], $this->fields([
            'ratingHalfStars' => 2,
            'ratingComment' => 'Comment 1',
        ]));
        try {
            $this->trips->update((int) $trip['id'], $this->fields([
                'ratingHalfStars' => null,
                'ratingComment' => 'Comment 1',
            ]));
            self::fail('A comment was kept after the rating was cleared.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('ratingComment', $e->fields);
        }
        $still = $this->trips->get((int) $trip['id'], '2026-11-03');
        self::assertSame(2, $still['ratingHalfStars']);
        self::assertSame('Comment 1', $still['ratingComment']);
    }

    public function testMarkingADepositBillLowersTheDepositDue(): void
    {
        $trip = $this->stay(8000, 5000, '2026-12-01', '2026-12-05', 'Deposit camp');
        self::assertSame(3000, $trip['depositMinor']);
        $deposit = $this->lodgingBill((int) $trip['id'], 'deposit');
        self::assertSame(3000, (int) $deposit['amount_minor']);
        $bills = new BillService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo));
        $bills->markPaid((int) $deposit['id'], ['paidOn' => '2026-12-01']);
        $after = $this->trips->get((int) $trip['id'], '2026-11-03');
        self::assertSame(0, $after['depositMinor']);
        self::assertTrue($after['depositCharged']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testClearingAmountDueRemovesAnUnpaidDueBillButKeepsAPaidOne(): void
    {
        $trip = $this->trips->create($this->fields([
            'placeName' => 'Keep paid due',
            'arriveOn' => '2026-12-01',
            'departOn' => '2026-12-05',
            'totalMinor' => 10000,
            'amountDueMinor' => 4000,
            'balanceDueOn' => '2026-12-15',
        ]));
        $due = $this->lodgingBill((int) $trip['id'], 'due');
        $bills = new BillService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo));
        $bills->markPaid((int) $due['id'], ['paidOn' => '2026-12-15']);
        $this->trips->update((int) $trip['id'], $this->fields([
            'placeName' => 'Keep paid due',
            'arriveOn' => '2026-12-01',
            'departOn' => '2026-12-05',
            'totalMinor' => 10000,
            'amountDueMinor' => 0,
            'balanceDueOn' => '2026-12-15',
        ]));
        $kept = $this->lodgingBill((int) $trip['id'], 'due');
        self::assertSame('paid', $kept['status']);
        self::assertSame(4000, (int) $kept['amount_minor']);

        $open = $this->stay(9000, 3000, '2027-04-01', '2027-04-04', 'Drop unpaid due');
        $this->trips->update((int) $open['id'], $this->fields([
            'placeName' => 'Drop unpaid due',
            'arriveOn' => '2027-04-01',
            'departOn' => '2027-04-04',
            'totalMinor' => 9000,
            'amountDueMinor' => 0,
            'balanceDueOn' => '2027-04-02',
        ]));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bills WHERE trip_id = ? AND type = 'lodging' AND lodging_part = 'due'"
        );
        $stmt->execute([(int) $open['id']]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testMarkingTheDueBillClearsAmountDueOnTheStay(): void
    {
        $trip = $this->trips->create($this->fields([
            'placeName' => 'Due later',
            'arriveOn' => '2026-12-01',
            'departOn' => '2026-12-05',
            'totalMinor' => 10000,
            'amountDueMinor' => 4000,
            'balanceDueOn' => '2026-12-15',
        ]));
        self::assertSame(4000, $trip['amountDueMinor']);
        $due = $this->lodgingBill((int) $trip['id'], 'due');
        $bills = new BillService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo));
        $paid = $bills->markPaid((int) $due['id'], ['paidOn' => '2026-12-15']);
        self::assertSame('2026-12-15', $paid['lastPaidOn']);
        $after = $this->trips->get((int) $trip['id'], '2026-11-03');
        self::assertSame(0, $after['amountDueMinor']);
        self::assertTrue($after['dueCharged']);
    }

    public function testAStayDueBecomesOneLodgingBillAndEditsKeepThatBill(): void
    {
        $trip = $this->stay(12000, 4000, '2026-11-20', '2026-11-24', 'Mesa camp');
        $created = $this->lodgingBills();
        self::assertCount(1, $created);
        self::assertSame('Mesa camp', $created[0]['name']);
        self::assertSame('lodging', $created[0]['type']);
        self::assertSame('once', $created[0]['recurrence']);
        self::assertSame('active', $created[0]['status']);
        self::assertSame('USD', $created[0]['currency']);
        self::assertSame(4000, (int) $created[0]['amount_minor']);
        self::assertSame('2026-11-20', $created[0]['anchor_date']);
        self::assertSame('2026-11-20', $created[0]['next_due_date']);
        self::assertSame($this->accountId, (int) $created[0]['account_id']);
        self::assertSame((int) $trip['id'], (int) $created[0]['trip_id']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);

        $wallet = $this->accounts->create([
            'name' => 'Wallet 1',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 5000,
        ]);
        $updated = $this->trips->update((int) $trip['id'], $this->fields([
            'placeName' => 'Mesa camp',
            'arriveOn' => '2026-11-20',
            'departOn' => '2026-11-24',
            'totalMinor' => 12000,
            'amountDueMinor' => 2500,
            'balanceDueOn' => '2026-11-22',
            'accountId' => (int) $wallet['id'],
        ]));
        self::assertSame(9500, $this->trips->get((int) $trip['id'], '2026-11-03')['depositMinor']);
        $edited = $this->lodgingBills();
        self::assertCount(1, $edited);
        self::assertSame((int) $created[0]['id'], (int) $edited[0]['id']);
        self::assertSame('Mesa camp', $edited[0]['name']);
        self::assertSame(2500, (int) $edited[0]['amount_minor']);
        self::assertSame('2026-11-22', $edited[0]['anchor_date']);
        self::assertSame('2026-11-22', $edited[0]['next_due_date']);
        self::assertSame((int) $wallet['id'], (int) $edited[0]['account_id']);
        self::assertSame('USD', $edited[0]['currency']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
        self::assertSame(5000, $this->accounts->get((int) $wallet['id'])['balanceMinor']);

        $paid = (new BillService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo)))->markPaid(
            (int) $edited[0]['id'],
            ['paidOn' => '2026-11-22'],
        );
        self::assertSame('paid', $paid['status']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
        self::assertSame(5000, $this->accounts->get((int) $wallet['id'])['balanceMinor']);

        $this->trips->update((int) $updated['id'], $this->fields([
            'placeName' => 'Mesa camp',
            'arriveOn' => '2026-11-20',
            'departOn' => '2026-11-24',
            'totalMinor' => 12000,
            'amountDueMinor' => 0,
            'balanceDueOn' => '2026-11-22',
            'accountId' => (int) $wallet['id'],
        ]));
        $keptDue = $this->dueBillsFor((int) $trip['id']);
        self::assertCount(1, $keptDue);
        self::assertSame('paid', $keptDue[0]['status']);
        self::assertSame((int) $paid['id'], (int) $keptDue[0]['id']);

        $openCamp = $this->stay(0, 0, '2027-02-01', '2027-02-03', 'Open camp');
        self::assertSame([], $this->dueBillsFor((int) $openCamp['id']));

        $dated = $this->stay(5000, 5000, '2027-03-01', '2027-03-04', 'Due camp');
        self::assertCount(1, $this->dueBillsFor((int) $dated['id']));
        $this->trips->update((int) $dated['id'], $this->fields([
            'placeName' => 'Due camp',
            'arriveOn' => '2027-03-01',
            'departOn' => '2027-03-04',
            'totalMinor' => 5000,
            'amountDueMinor' => 5000,
            'balanceDueOn' => '',
            'accountId' => $this->accountId,
        ]));
        self::assertSame([], $this->dueBillsFor((int) $dated['id']));
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testLodgingDueDoesNotChangeTheBalanceAndTheBillBlocksArchive(): void
    {
        $trip = $this->stay(8000, 8000, '2026-12-01', '2026-12-05');
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
        try {
            $this->accounts->archive($this->accountId);
            self::fail('An active lodging bill allowed the account to be archived.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        $this->trips->update((int) $trip['id'], $this->fields([
            'placeName' => 'Stay 1',
            'arriveOn' => '2026-12-01',
            'departOn' => '2026-12-05',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'balanceDueOn' => '',
            'accountId' => null,
        ]));
        self::assertNotNull($this->accounts->archive($this->accountId)['archivedAt']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testAFlightSkipsTheRoadAndKeepsNoFuelMiles(): void
    {
        $this->stay(0, 0, '2026-11-01', '2026-11-02', 'Stay 1');
        $second = $this->trips->create($this->fields([
            'placeName' => 'Stay 2',
            'arriveOn' => '2026-11-02',
            'departOn' => '2026-11-04',
            'latitude' => '34.05',
            'longitude' => '-118.24',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
            'travel' => 'fly',
        ]));
        self::assertSame('fly', $second['travel']);
        $legs = $this->trips->route('2026-11-03');
        self::assertCount(1, $legs);
        self::assertSame('fly', $legs[0]['travel']);
        self::assertFalse($legs[0]['driving']);
        self::assertSame(0, $legs[0]['miles']);
        self::assertSame([], $legs[0]['coordinates']);
        self::assertNull($this->trips->get((int) $second['id'], '2026-11-03')['fuel']['miles']);
        self::assertSame([], $this->trips->fuelEstimates('2026-11-01', '2026-11-30'));

        $kept = $this->trips->update((int) $second['id'], $this->fields([
            'placeName' => 'Stay 2',
            'arriveOn' => '2026-11-02',
            'departOn' => '2026-11-04',
            'latitude' => '34.05',
            'longitude' => '-118.24',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        self::assertSame('fly', $kept['travel']);

        try {
            $this->trips->create($this->fields([
                'placeName' => 'Stay 3',
                'arriveOn' => '2026-12-01',
                'departOn' => '2026-12-02',
                'travel' => 'sail',
            ]));
            self::fail('A stay stored an unknown travel.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('travel', $e->fields);
        }
    }

    public function testAStaySyncsAnActiveFuelBillOnTheFuelBucket(): void
    {
        $this->accounts->create([
            'name' => 'Fuel 1',
            'type' => 'fuel',
            'currency' => 'USD',
            'balanceMinor' => 0,
            'gasFavorite' => true,
        ]);
        $vehicles = new VehicleService($this->pdo, new Actor($this->pdo));
        $vehicles->create([
            'name' => 'Coach',
            'kind' => 'rv',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'currentOdometerMiles' => 1000,
            'fuelPriceMinor' => 400,
            'starred' => true,
        ]);
        $this->trips->create($this->fields([
            'placeName' => 'First',
            'arriveOn' => '2026-11-10',
            'departOn' => '2026-11-12',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $second = $this->trips->create($this->fields([
            'placeName' => 'Second',
            'arriveOn' => '2026-11-14',
            'departOn' => '2026-11-16',
            'latitude' => '34.05',
            'longitude' => '-118.24',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'accountId' => null,
        ]));
        $stmt = $this->pdo->prepare("SELECT name, type, status, amount_minor FROM bills WHERE trip_id = ? AND type = 'fuel'");
        $stmt->execute([(int) $second['id']]);
        $fuel = $stmt->fetch();
        self::assertIsArray($fuel);
        self::assertSame('Second', $fuel['name']);
        self::assertSame('active', $fuel['status']);
        self::assertGreaterThan(0, (int) $fuel['amount_minor']);
    }

    /** @return list<array<string, mixed>> */
    private function lodgingBills(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM bills WHERE trip_id IS NOT NULL AND type = 'lodging' AND lodging_part = 'due' ORDER BY id");
        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    private function dueBillsFor(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bills WHERE trip_id = ? AND type = 'lodging' AND lodging_part = 'due' ORDER BY id"
        );
        $stmt->execute([$tripId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    private function lodgingBill(int $tripId, string $part): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bills WHERE trip_id = ? AND type = 'lodging' AND lodging_part = ?"
        );
        $stmt->execute([$tripId, $part]);
        $row = $stmt->fetch();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function fields(array $extra = []): array
    {
        return array_merge([
            'placeName' => 'Stay 1',
            'note' => 'Site 34',
            'arriveOn' => '2026-11-20',
            'departOn' => '2026-11-24',
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'accountId' => $this->accountId,
            'totalMinor' => 12000,
            'amountDueMinor' => 12000,
            'today' => '2026-11-03',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function stay(int $total, int $due, string $arrive, string $depart, string $name = 'Stay 1'): array
    {
        return $this->trips->create([
            'placeName' => $name,
            'note' => 'Site 34',
            'arriveOn' => $arrive,
            'departOn' => $depart,
            'latitude' => '36.17',
            'longitude' => '-115.14',
            'accountId' => $total > 0 ? $this->accountId : null,
            'totalMinor' => $total,
            'amountDueMinor' => $due,
            'today' => '2026-11-03',
        ]);
    }
}
