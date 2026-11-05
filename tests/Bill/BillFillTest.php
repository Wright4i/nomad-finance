<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Bill\BillTypes;
use App\Category\CategoryService;
use App\Category\SystemBuckets;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use App\Vehicle\VehicleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillFillTest extends TestCase
{
    private PDO $pdo;

    private BillService $bills;

    private CategoryService $categories;

    private PayCycleService $cycles;

    private int $accountId;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $catalog = new CurrencyCatalog();
        $this->bills = new BillService($this->pdo, $catalog, $actor);
        $this->categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
        $this->cycles = new PayCycleService($this->pdo, $actor);
        $this->accountId = (int) (new AccountService($this->pdo, $catalog, $actor))->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100000,
        ])['id'];
        $this->cycles->create([
            'payor' => 'Monthly Payor',
            'payday' => '2026-11-01',
            'length' => 'monthly',
            'payKind' => 'fixed',
            'rateMinor' => 10000,
        ], '2026-11-05');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testEachTypeBelongsToOneGroup(): void
    {
        self::assertSame('needs', BillTypes::group('mortgage'));
        self::assertSame('needs', BillTypes::group('storage'));
        self::assertSame('needs', BillTypes::group('fuel'));
        self::assertSame('wants', BillTypes::group('misc'));
        self::assertSame('savings', BillTypes::group('liquidities'));
    }

    public function testTwoBillsSumOnTheDefaultAndAWeeklySpanCountsEachDueDate(): void
    {
        $first = $this->bills->create($this->bill('Rent A', 'rent', 1000, '2026-11-03'));
        $second = $this->bills->create($this->bill('Rent B', 'rent', 2500, '2026-11-04'));
        $weekly = $this->bills->create($this->bill('Power', 'rent', 100, '2026-11-08', 'weekly'));
        $needs = SystemBuckets::idForType($this->pdo, $this->userId(), 'rent');
        self::assertSame($needs, $first['categoryId']);
        self::assertSame($needs, $second['categoryId']);
        $open = $this->assignment($needs, $this->homeId());
        self::assertSame(1000 + 2500 + (100 * 4), $open['filled_minor']);
        self::assertSame($open['filled_minor'], $open['amount_minor']);
        self::assertSame($needs, $weekly['categoryId']);
    }

    public function testATypedExtraSurvivesTheNextBillSaveAndMarkPaidLeavesIt(): void
    {
        $bill = $this->bills->create($this->bill('Rent', 'rent', 1000, '2026-11-10'));
        $needs = (int) $bill['categoryId'];
        $this->pdo->prepare(
            'UPDATE assignments SET amount_minor = filled_minor + 400 WHERE category_id = ?'
        )->execute([$needs]);
        $updated = $this->bills->update($bill['id'], $this->bill('Rent', 'rent', 2000, '2026-11-10'));
        $open = $this->assignment($needs, $this->homeId());
        self::assertSame(2000, $open['filled_minor']);
        self::assertSame(2400, $open['amount_minor']);
        $this->bills->markPaid($updated['id'], ['paidOn' => '2026-11-10']);
        self::assertSame($open, $this->assignment($needs, $this->homeId()));
        self::assertSame(100000, $this->balance());
    }

    public function testAMissingRateIsLeftOutAndASecondPayorIsNotFilled(): void
    {
        $later = $this->cycles->create([
            'payor' => 'Weekly Payor',
            'payday' => '2026-11-01',
            'length' => 'weekly',
            'payKind' => 'fixed',
            'rateMinor' => 10000,
        ], '2026-11-05');
        $bill = $this->bills->create($this->bill('Abroad', 'rent', 2000, '2026-11-10', 'once', 'EUR'));
        $needs = (int) $bill['categoryId'];
        $home = $this->assignment($needs, $this->homeId());
        self::assertSame(2000, $home['filled_minor']);
        $other = $this->pdo->prepare(
            'SELECT COUNT(*) FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.pay_cycle_id = ? AND a.filled_minor != 0'
        );
        $other->execute([(int) $later['id']]);
        self::assertSame(0, (int) $other->fetchColumn());
    }

    public function testFuelStaysOnFuelBucketAndACrossGroupGoalSnapsHome(): void
    {
        $goal = $this->categories->create(['name' => 'Needs 1', 'group' => 'needs']);
        $wants = $this->categories->create(['name' => 'Wants 1', 'group' => 'wants']);
        $fuel = $this->bills->create($this->bill('Fuel', 'fuel', 500, '2026-11-04') + [
            'categoryId' => $goal['id'],
        ]);
        self::assertSame('Fuel', $fuel['categoryName']);
        $kept = $this->bills->create($this->bill('Rent', 'rent', 500, '2026-11-04') + [
            'categoryId' => $goal['id'],
        ]);
        self::assertSame('Needs 1', $kept['categoryName']);
        $snapped = $this->bills->create($this->bill('Rent 2', 'rent', 500, '2026-11-04') + [
            'categoryId' => $wants['id'],
        ]);
        self::assertSame('Rent', $snapped['categoryName']);
        $vehicle = (new VehicleService($this->pdo, new Actor($this->pdo)))->create([
            'name' => 'Vehicle 1',
            'kind' => 'car',
            'mpgMinTenths' => 60,
            'mpgMaxTenths' => 120,
            'currentOdometerMiles' => 0,
        ]);
        $before = $this->balance();
        $logged = $this->bills->create($this->bill('Fuel 1', 'fuel', 100, '2026-11-04') + [
            'vehicleId' => $vehicle['id'],
            'odometerMiles' => 100,
            'pricePerGallonMinor' => 400,
        ]);
        self::assertSame(100, $logged['odometerMiles']);
        self::assertSame('paid', $this->bills->markPaid($logged['id'], ['paidOn' => '2026-11-04'])['status']);
        self::assertSame($before, $this->balance());
        $rentReading = $this->bills->create($this->bill('Rent 3', 'rent', 100, '2026-11-04') + [
            'odometerMiles' => 10,
            'pricePerGallonMinor' => 400,
            'vehicleId' => $vehicle['id'],
        ]);
        self::assertNull($rentReading['odometerMiles']);
        self::assertSame($before, $this->balance());
    }

    public function testADefaultBucketOnlyTakesATargetAmount(): void
    {
        $needs = $this->categories->list(false, '2026-11-05');
        $default = null;
        foreach ($needs as $category) {
            if ($category['name'] === 'Rent' && ($category['locked'] ?? false) === true) {
                $default = $category;
            }
        }
        self::assertIsArray($default);
        try {
            $this->categories->update($default['id'], ['name' => 'Renamed', 'group' => 'needs', 'overspend' => 'cover']);
            self::fail('A default bucket was renamed.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $this->categories->archive($default['id']);
            self::fail('A default bucket was archived.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $this->categories->update($default['id'], [
                'target' => ['kind' => 'balance', 'amountMinor' => 5000],
            ], '2026-11-05');
            self::fail('A locked bucket took a target.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $this->categories->create(['name' => 'Fuel', 'group' => 'needs']);
            self::fail('A reserved name was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function bill(
        string $name,
        string $type,
        int $amount,
        string $due,
        string $recurrence = 'once',
        string $currency = 'USD',
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'accountId' => $this->accountId,
            'amountMinor' => $amount,
            'currency' => $currency,
            'recurrence' => $recurrence,
            'nextDueDate' => $due,
            'today' => '2026-11-05',
        ];
    }

    private function userId(): int
    {
        return (int) $_SESSION['user_id'];
    }

    private function homeId(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pay_cycles WHERE user_id = ? AND archived_at IS NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute([$this->userId()]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{amount_minor:int,filled_minor:int} */
    private function assignment(int $categoryId, int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.amount_minor), 0) AS amount_minor, COALESCE(SUM(a.filled_minor), 0) AS filled_minor
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.pay_cycle_id = ? AND a.category_id = ? AND p.period_start = \'2026-11-01\''
        );
        $stmt->execute([$cycleId, $categoryId]);
        $row = $stmt->fetch();

        return [
            'amount_minor' => (int) ($row['amount_minor'] ?? 0),
            'filled_minor' => (int) ($row['filled_minor'] ?? 0),
        ];
    }

    private function balance(): int
    {
        $stmt = $this->pdo->prepare('SELECT balance_minor FROM accounts WHERE id = ?');
        $stmt->execute([$this->accountId]);

        return (int) $stmt->fetchColumn();
    }
}
