<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Category\CategoryService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use App\Trip\Drive;
use App\Trip\Fuel;
use App\Trip\TripService;
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
        $this->pdo->prepare('UPDATE user SET fuel_price_minor = 400 WHERE id = ?')->execute([$_SESSION['user_id']]);

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
        $expected = Fuel::estimate($legs[0]['miles'], null, 400);
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

        $this->pdo->prepare('UPDATE user SET fuel_price_minor = NULL WHERE id = ?')->execute([$_SESSION['user_id']]);
        $priced = $this->trips->get((int) $second['id'], '2026-11-03');
        self::assertSame($expected['miles'], $priced['fuel']['miles']);
        self::assertNull($priced['fuel']['costMinor']);
    }

    public function testAHandoffDayIsAllowedAndASharedNightIsNamed(): void
    {
        $first = $this->stay(0, 0, '2026-11-01', '2026-11-05', 'Stay 1');
        $second = $this->stay(0, 0, '2026-11-05', '2026-11-08', 'Stay 2');
        self::assertSame('2026-11-05', $second['arriveOn']);
        try {
            $this->stay(0, 0, '2026-11-04', '2026-11-06', 'Stay 3');
            self::fail('An overnight overlap was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertStringContainsString('Stay 1', $e->fields['arriveOn']);
        }
        try {
            $this->trips->update((int) $first['id'], [
                'placeName' => 'Stay 1',
                'arriveOn' => '2026-11-06',
                'departOn' => '2026-11-09',
                'latitude' => '36.17',
                'longitude' => '-115.14',
                'totalMinor' => 0,
                'amountDueMinor' => 0,
                'today' => '2026-11-03',
            ]);
            self::fail('An updated stay was allowed to share a night.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertStringContainsString('Stay 2', $e->fields['arriveOn']);
        }
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

    public function testLodgingDueDoesNotBlockArchiveOrChangeTheBalance(): void
    {
        $this->stay(8000, 8000, '2026-12-01', '2026-12-05');
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
        self::assertNotNull($this->accounts->archive($this->accountId)['archivedAt']);
        self::assertSame(20000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    /** @param array<string, mixed> $extra
     *  @return array<string, mixed>
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
