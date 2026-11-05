<?php

declare(strict_types=1);

namespace App\Tests\Posting;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Category\CategoryService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Posting\PostingService;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use App\Trip\Drive;
use App\Trip\TripService;
use App\Vehicle\VehicleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostingRulesTest extends TestCase
{
    private PDO $pdo;

    private PostingService $postings;

    private AccountService $accounts;

    private string $path;

    private int $accountId;

    private int $categoryId;

    private int $fuelId;

    private int $vehicleId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $catalog = new CurrencyCatalog();
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
        $this->postings = new PostingService($this->pdo, $actor, $catalog);
        $account = $this->accounts->create([
            'name' => 'Checking 1',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 10000,
        ]);
        $this->accountId = (int) $account['id'];
        $this->categoryId = (int) $categories->create(['name' => 'Needs 1', 'group' => 'needs'])['id'];
        $categories->list(false);
        $fuel = $this->pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND role = 'fuel'");
        $fuel->execute([$_SESSION['user_id']]);
        $this->fuelId = (int) $fuel->fetchColumn();
        $this->vehicleId = (int) (new VehicleService($this->pdo, $actor))->create([
            'name' => 'Vehicle 1',
            'kind' => 'car',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'starred' => true,
        ])['id'];
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testMoneyOutLowersTheBalanceAndMoneyInRaisesIt(): void
    {
        $out = $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 1500,
            'direction' => 'out',
            'postedOn' => '2026-11-02',
            'payee' => 'Payee 1',
        ]);
        self::assertSame(8500, $out['balanceMinor']);
        $in = $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 500,
            'direction' => 'in',
            'postedOn' => '2026-11-03',
        ]);
        self::assertSame(9000, $in['balanceMinor']);
        self::assertSame(9000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testATypedBalanceIsNotReplayedOntoOlderPostings(): void
    {
        $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 1000,
            'direction' => 'out',
            'postedOn' => '2026-11-02',
        ]);
        $typed = $this->accounts->update($this->accountId, [
            'name' => 'Checking 1',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 4000,
        ]);
        self::assertSame(4000, $typed['balanceMinor']);
        self::assertCount(1, $this->postings->list());
    }

    public function testDeletingAPostingReturnsTheBalance(): void
    {
        $posting = $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 1000,
            'direction' => 'out',
            'postedOn' => '2026-11-02',
        ]);
        $this->postings->delete((int) $posting['id']);
        self::assertSame(10000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    public function testAChargeOnACardRaisesTheAmountOwed(): void
    {
        $card = $this->accounts->create([
            'name' => 'Card 1',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'creditLimitMinor' => 5000,
        ]);
        $posting = $this->postings->create([
            'accountId' => $card['id'],
            'categoryId' => $this->categoryId,
            'amountMinor' => 4500,
            'direction' => 'out',
            'postedOn' => '2026-11-03',
        ]);
        self::assertSame(5500, $posting['balanceMinor']);
    }

    public function testFuelReadingsAreOptionalAndAPlainCategoryRefusesThem(): void
    {
        $plain = $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->fuelId,
            'amountMinor' => 1000,
            'direction' => 'out',
            'postedOn' => '2026-10-01',
            'odometerMiles' => 10000,
            'pricePerGallonMinor' => 400,
            'vehicleId' => $this->vehicleId,
        ]);
        self::assertSame(10000, $plain['odometerMiles']);
        try {
            $this->postings->create([
                'accountId' => $this->accountId,
                'categoryId' => $this->fuelId,
                'amountMinor' => 1000,
                'direction' => 'out',
                'postedOn' => '2026-10-02',
                'odometerMiles' => 9999,
                'pricePerGallonMinor' => 400,
                'vehicleId' => $this->vehicleId,
            ]);
            self::fail('A lower odometer was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Enter an odometer at or above 10000.', $e->fields['odometerMiles']);
        }
        self::assertNull($plain['stretch']['milesPerGallon']);
        try {
            $this->postings->create([
                'accountId' => $this->accountId,
                'categoryId' => $this->categoryId,
                'amountMinor' => 100,
                'direction' => 'out',
                'postedOn' => '2026-10-02',
                'odometerMiles' => 10,
            ]);
            self::fail('A fuel reading was stored on a plain category.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testAQuoteShowsTheNextBalanceAndDoesNotWriteIt(): void
    {
        $quote = $this->postings->quote([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 1500,
            'direction' => 'out',
            'postedOn' => '2026-11-02',
        ]);
        self::assertSame(10000, $quote['oldBalanceMinor']);
        self::assertSame(8500, $quote['newBalanceMinor']);
        self::assertNull($quote['refused']);
        self::assertSame(10000, $this->accounts->get($this->accountId)['balanceMinor']);
        self::assertSame([], $this->postings->list());
    }

    public function testOneStayOnTheDateIsAttached(): void
    {
        $actor = new Actor($this->pdo);
        $trips = new TripService($this->pdo, $actor, new CurrencyCatalog(), $this->postings, new Drive(false));
        $trip = $trips->create([
            'placeName' => 'Stay 1',
            'arriveOn' => '2026-11-01',
            'departOn' => '2026-11-05',
            'latitude' => '1',
            'longitude' => '2',
            'totalMinor' => 0,
            'amountDueMinor' => 0,
            'today' => '2026-11-03',
        ]);
        $posting = $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 200,
            'direction' => 'out',
            'postedOn' => '2026-11-03',
        ]);
        self::assertSame($trip['id'], $posting['tripId']);
        $cleared = $this->postings->update((int) $posting['id'], [
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 200,
            'direction' => 'out',
            'postedOn' => '2026-11-03',
            'tripId' => null,
        ]);
        self::assertNull($cleared['tripId']);
    }

    public function testASplitMovesTheBalanceOnceAndEachLineKeepsItsOwnRemaining(): void
    {
        $wants = $this->category('Wants 1', 'wants');
        $input = [
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 1500,
            'direction' => 'out',
            'postedOn' => '2026-11-02',
            'lines' => [
                ['categoryId' => $this->categoryId, 'amountMinor' => 1000],
                ['categoryId' => $wants, 'amountMinor' => 500],
            ],
        ];
        $quote = $this->postings->quote($input);
        self::assertSame(8500, $quote['newBalanceMinor']);
        self::assertSame([], $this->postings->list());
        $posting = $this->postings->create($input);
        self::assertSame(8500, $posting['balanceMinor']);
        self::assertSame(8500, $this->accounts->get($this->accountId)['balanceMinor']);
        self::assertSame('Needs 1', $posting['lines'][0]['categoryName']);
        self::assertSame(-1000, $posting['lines'][0]['remainingMinor']);
        self::assertSame('Wants 1', $posting['lines'][1]['categoryName']);
        self::assertSame(-500, $posting['lines'][1]['remainingMinor']);
        self::assertCount(1, $this->postings->list());
    }

    public function testLinesThatDoNotSumOrRepeatACategoryAreRefused(): void
    {
        $wants = $this->category('Wants 1', 'wants');
        try {
            $this->postings->create([
                'accountId' => $this->accountId,
                'categoryId' => $this->categoryId,
                'amountMinor' => 1500,
                'direction' => 'out',
                'postedOn' => '2026-11-02',
                'lines' => [
                    ['categoryId' => $this->categoryId, 'amountMinor' => 1000],
                    ['categoryId' => $wants, 'amountMinor' => 400],
                ],
            ]);
            self::fail('Lines that miss the amount were stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('The lines have to sum to the amount.', $e->fields['lines']);
        }
        try {
            $this->postings->create([
                'accountId' => $this->accountId,
                'categoryId' => $this->categoryId,
                'amountMinor' => 1500,
                'direction' => 'out',
                'postedOn' => '2026-11-02',
                'lines' => [
                    ['categoryId' => $this->categoryId, 'amountMinor' => 1000],
                    ['categoryId' => $this->categoryId, 'amountMinor' => 500],
                ],
            ]);
            self::fail('One category was stored on two lines.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('A bucket is on one line.', $e->fields['lines']);
        }
        self::assertSame(10000, $this->accounts->get($this->accountId)['balanceMinor']);
        self::assertSame([], $this->postings->list());
    }

    public function testAFuelLineUsesItsOwnAmountForGallons(): void
    {
        $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->fuelId,
            'amountMinor' => 1000,
            'direction' => 'out',
            'postedOn' => '2026-10-01',
            'odometerMiles' => 10000,
            'pricePerGallonMinor' => 400,
            'vehicleId' => $this->vehicleId,
        ]);
        $split = $this->postings->create([
            'accountId' => $this->accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => 1500,
            'direction' => 'out',
            'postedOn' => '2026-10-15',
            'odometerMiles' => 10100,
            'pricePerGallonMinor' => 400,
            'vehicleId' => $this->vehicleId,
            'lines' => [
                ['categoryId' => $this->categoryId, 'amountMinor' => 1000],
                ['categoryId' => $this->fuelId, 'amountMinor' => 500],
            ],
        ]);
        self::assertSame('1.250', $split['stretch']['gallons']);
        self::assertSame(100, $split['stretch']['miles']);
        self::assertSame('80.0', $split['stretch']['milesPerGallon']);
    }

    public function testAFutureDateIsRefused(): void
    {
        try {
            $this->postings->create([
                'accountId' => $this->accountId,
                'categoryId' => $this->categoryId,
                'amountMinor' => 100,
                'direction' => 'out',
                'postedOn' => '2099-01-01',
                'today' => '2026-11-04',
            ]);
            self::fail('A future date was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('postedOn', $e->fields);
        }
        self::assertSame(10000, $this->accounts->get($this->accountId)['balanceMinor']);
    }

    private function category(string $name, string $group): int
    {
        $categories = new CategoryService($this->pdo, new Actor($this->pdo), new TargetService($this->pdo, new Actor($this->pdo)));

        return (int) $categories->create(['name' => $name, 'group' => $group])['id'];
    }
}
