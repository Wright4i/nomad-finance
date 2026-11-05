<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Category\CategoryService;
use App\Target\TargetService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Money\RateBaseline;
use App\PayCycle\PayCycleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ResetTest extends TestCase
{
    private PDO $pdo;

    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testResetRestoresDemoAndLeavesTheOwner(): void
    {
        $catalog = new CurrencyCatalog();
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $ownerId = $_SESSION['user_id'];
        self::assertIsInt($ownerId);

        $actor = new Actor($this->pdo);
        $accounts = new AccountService($this->pdo, $catalog, $actor);
        $harbor = $accounts->create([
            'name' => 'Harbor',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 12345,
        ]);

        try {
            $actor->requireDemo();
            self::fail('The owner cannot reset demo books.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
        }

        $demo = $auth->enterDemo();
        self::assertSame('demo', $demo['role']);
        $seeded = $accounts->list(false);
        self::assertCount(10, $seeded);
        $checking = $this->named($seeded, 'Checking 1');
        self::assertSame('checking', $checking['type']);
        self::assertSame(249000, $checking['balanceMinor']);

        $accounts->create([
            'name' => 'Scratch',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 100,
        ]);
        self::assertCount(11, $accounts->list(false));

        try {
            $accounts->get($harbor['id']);
            self::fail('Demo cannot open the owner account.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }

        (new DemoLibrary($this->pdo, $catalog))->resetDemo();
        $restored = $accounts->list(false);
        self::assertCount(10, $restored);
        $names = array_column($restored, 'name');
        self::assertNotContains('Scratch', $names);
        self::assertContains('Checking 1', $names);
        self::assertNotContains('Harbor', $names);

        $_SESSION['user_id'] = $ownerId;
        $again = $accounts->get($harbor['id']);
        self::assertSame('Harbor', $again['name']);
        self::assertSame(12345, $again['balanceMinor']);
        self::assertCount(1, $accounts->list(false));
    }

    public function testSeedCoversEveryAccountAndBillVariant(): void
    {
        $catalog = new CurrencyCatalog();
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $auth->enterDemo();
        $demoId = $_SESSION['user_id'];
        self::assertIsInt($demoId);

        $types = $this->column(
            'SELECT DISTINCT type FROM accounts WHERE user_id = ? ORDER BY type',
            [$demoId],
        );
        self::assertSame(['cash', 'checking', 'credit', 'loan', 'other', 'savings'], $types);

        $otherSides = $this->column(
            "SELECT DISTINCT side FROM accounts WHERE user_id = ? AND type = 'other' ORDER BY side",
            [$demoId],
        );
        self::assertSame(['asset', 'liability'], $otherSides);

        $archived = $this->pdo->prepare('SELECT COUNT(*) FROM accounts WHERE user_id = ? AND archived_at IS NOT NULL');
        $archived->execute([$demoId]);
        self::assertSame(1, (int) $archived->fetchColumn());

        $card = $this->pdo->prepare(
            'SELECT interest_bps, minimum_due_minor, due_day, credit_limit_minor FROM accounts WHERE user_id = ? AND name = \'Card 1\''
        );
        $card->execute([$demoId]);
        self::assertSame(
            ['interest_bps' => 1999, 'minimum_due_minor' => 2500, 'due_day' => 15, 'credit_limit_minor' => 200000],
            array_map(intval(...), $card->fetch() ?: []),
        );

        $rates = $this->column(
            'SELECT rate FROM exchange_rates WHERE user_id = ? AND currency = \'JPY\'',
            [$demoId],
        );
        self::assertSame([RateBaseline::rates()['JPY']], $rates);
        $eurRates = $this->pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE user_id = ? AND currency = \'EUR\'');
        $eurRates->execute([$demoId]);
        self::assertSame(1, (int) $eurRates->fetchColumn());
        $rateCount = $this->pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE user_id = ?');
        $rateCount->execute([$demoId]);
        self::assertSame(count(RateBaseline::rates()), (int) $rateCount->fetchColumn());

        $billTypes = $this->column(
            'SELECT DISTINCT b.type FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.type',
            [$demoId],
        );
        self::assertSame([
            'cellular', 'debt', 'electricity', 'emergency', 'entertainment', 'fuel', 'groceries', 'healthcare',
            'insurance', 'internet', 'investments', 'laundry', 'liquidities', 'lodging', 'mailbox',
            'maintenance', 'memberships', 'misc', 'mortgage', 'other', 'pets', 'propane', 'rent', 'repairs',
            'restaurants', 'roadside', 'shopping', 'software', 'storage', 'subscriptions', 'travel',
            'vehicle', 'water',
        ], $billTypes);
        $otherName = $this->pdo->prepare(
            'SELECT b.other_label FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? AND b.name = \'Other Bill\''
        );
        $otherName->execute([$demoId]);
        self::assertSame('Custom 1', $otherName->fetchColumn());
        $recurrences = $this->column(
            'SELECT DISTINCT b.recurrence FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.recurrence',
            [$demoId],
        );
        self::assertSame(['monthly', 'once', 'weekly', 'yearly'], $recurrences);
        $statuses = $this->column(
            'SELECT DISTINCT b.status FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.status',
            [$demoId],
        );
        self::assertSame(['active', 'paid', 'paused'], $statuses);

        $names = $this->column(
            'SELECT b.name FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.name',
            [$demoId],
        );
        self::assertSame([
            'Childcare Bill',
            'Education Bill',
            'Emergency Bill',
            'Fuel Bill',
            'Groceries Bill',
            'Insurance Bill',
            'Internet Bill',
            'Investments Bill',
            'Laundry Bill',
            'Liquidities Bill',
            'Lodging Bill',
            'Mailbox Bill',
            'Maintenance Bill',
            'Medical Bill',
            'Memberships Bill',
            'Misc Bill',
            'Mortgage Bill',
            'One-Time Bill',
            'Other Bill',
            'Overdue Monthly Bill',
            'Paid One-Time Bill',
            'Paid Weekly Bill',
            'Paused Weekly Bill',
            'Pets Bill',
            'Phone Bill',
            'Propane Bill',
            'Recurring Monthly Bill',
            'Recurring Weekly Bill',
            'Recurring Yearly Bill',
            'Repairs Bill',
            'Restaurants Bill',
            'Roadside Bill',
            'Software Bill',
            'Storage Bill',
            'Tax Bill',
            'Transport Bill',
            'Travel Bill',
            'Water Bill',
        ], $names);
    }

    public function testSeedCoversEveryCategoryGroup(): void
    {
        $catalog = new CurrencyCatalog();
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $ownerId = $_SESSION['user_id'];
        $actor = new Actor($this->pdo);
        $categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
        self::assertSame(
            ['Needs Default', 'Fuel Bucket', 'Wants Default', 'Savings Default'],
            array_column($categories->list(true), 'name'),
        );

        $auth->enterDemo();
        $categories->create(['name' => 'Scratch Category', 'group' => 'wants']);
        (new DemoLibrary($this->pdo, $catalog))->resetDemo();

        $rows = $categories->list(true, '2026-11-03');
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }
        self::assertSame(
            [
                'Needs Default', 'Fuel Bucket', 'Archived Category', 'Needs 1',
                'Wants Default', 'Wants 1',
                'Savings Default', 'Needed By Date 1', 'Savings 1', 'Target Balance 1',
            ],
            array_column($rows, 'name'),
        );
        self::assertNull($byName['Needs 1']['target']);
        self::assertSame('needed', $byName['Needed By Date 1']['target']['kind']);
        self::assertSame(120000, $byName['Needed By Date 1']['target']['amountMinor']);
        self::assertSame('2027-02-01', $byName['Needed By Date 1']['target']['targetDate']);
        self::assertSame('Monthly Payor', $byName['Needed By Date 1']['target']['payor']);
        self::assertSame(4, $byName['Needed By Date 1']['target']['periodsLeft']);
        self::assertSame(30000, $byName['Needed By Date 1']['target']['neededMinor']);
        self::assertSame(50000, $byName['Target Balance 1']['target']['neededMinor']);
        self::assertFalse($byName['Target Balance 1']['target']['met']);
        self::assertSame('needs', $byName['Needs 1']['group']);
        self::assertNull($byName['Needs 1']['archivedAt']);
        self::assertSame(50000, $byName['Needs 1']['amountMinor']);
        self::assertSame('cover', $byName['Needs 1']['overspend']);
        self::assertSame('wants', $byName['Wants 1']['group']);
        self::assertSame('carry', $byName['Wants 1']['overspend']);
        self::assertSame(-15000, $byName['Wants 1']['amountMinor']);
        self::assertSame('savings', $byName['Savings 1']['group']);
        self::assertSame('needs', $byName['Archived Category']['group']);
        self::assertNotNull($byName['Archived Category']['archivedAt']);
        self::assertNotContains('Scratch Category', array_column($rows, 'name'));
        self::assertNotContains('Archived Category', array_column($categories->list(false), 'name'));

        $_SESSION['user_id'] = $ownerId;
        self::assertSame(
            ['Needs Default', 'Fuel Bucket', 'Wants Default', 'Savings Default'],
            array_column($categories->list(true), 'name'),
        );
    }

    public function testSeedStoresOneCyclePerPayor(): void
    {
        $catalog = new CurrencyCatalog();
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $ownerId = $_SESSION['user_id'];
        $cycles = new PayCycleService($this->pdo, new Actor($this->pdo));
        self::assertSame([], $cycles->list('2026-11-03', true));

        $auth->enterDemo();
        $cycles->create([
            'payor' => 'Scratch Payor',
            'payday' => '2026-01-01',
            'length' => 'weekly',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');
        (new DemoLibrary($this->pdo, $catalog))->resetDemo();

        $rows = $cycles->list('2026-11-03', true);
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['payor']] = $row;
        }
        self::assertSame(
            ['Archived Payor', 'Biweekly Payor', 'Irregular Payor', 'Monthly Payor', 'Monthly Weekday Payor', 'Semimonthly Payor', 'Weekday Payor', 'Weekly Payor'],
            array_column($rows, 'payor'),
        );
        self::assertNotContains('Scratch Payor', array_column($rows, 'payor'));
        self::assertNotContains('Archived Payor', array_column($cycles->list('2026-11-03', false), 'payor'));
        self::assertSame('salary', $byName['Monthly Payor']['payKind']);
        self::assertSame(700000, $byName['Monthly Payor']['paycheckMinor']);
        self::assertSame('monthly', $byName['Monthly Payor']['length']);
        self::assertSame('2026-11-01', $byName['Monthly Payor']['payday']);
        self::assertSame('2026-11-01', $byName['Monthly Payor']['periodStart']);
        self::assertSame('2026-11-30', $byName['Monthly Payor']['periodEnd']);
        self::assertSame('2026-12-01', $byName['Monthly Payor']['rollsOn']);
        self::assertSame('monthly', $byName['Monthly Weekday Payor']['length']);
        self::assertSame('weekdays', $byName['Monthly Weekday Payor']['hoursMode']);
        self::assertSame('8.00', $byName['Monthly Weekday Payor']['hours']);
        self::assertSame(11000, $byName['Monthly Weekday Payor']['rateMinor']);
        self::assertSame(1936000, $byName['Monthly Weekday Payor']['paycheckMinor']);
        self::assertSame(22, $byName['Monthly Weekday Payor']['monthlyWeekday']['weekdays']);
        self::assertSame(15, $byName['Semimonthly Payor']['secondDay']);
        self::assertSame('weekdays', $byName['Weekday Payor']['hoursMode']);
        self::assertSame(3, $byName['Weekday Payor']['averageMonths']);
        self::assertSame(220000, $byName['Weekday Payor']['paycheckMinor']);
        self::assertSame('typed', $byName['Weekly Payor']['hoursMode']);
        self::assertNull($byName['Weekly Payor']['averageMonths']);
        self::assertSame('2026-12-15', $byName['Irregular Payor']['nextPayday']);
        $user = $this->pdo->query("SELECT horizon_days, fuel_price_minor FROM user WHERE role = 'demo'")->fetch();
        self::assertIsArray($user);
        self::assertSame(60, (int) $user['horizon_days']);
        self::assertSame(400, (int) $user['fuel_price_minor']);
        self::assertSame('2026-11-22', $this->pdo->query("SELECT balance_due_on FROM trips WHERE place_name = 'Stay 1'")->fetchColumn());
        self::assertSame('2026-12-01', $this->pdo->query("SELECT balance_due_on FROM trips WHERE place_name = 'Stay 2'")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT rating_half_stars FROM trips WHERE place_name = 'Stay 1'")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT rating_comment FROM trips WHERE place_name = 'Stay 1'")->fetchColumn());
        self::assertSame(7, (int) $this->pdo->query("SELECT rating_half_stars FROM trips WHERE place_name = 'Stay 2'")->fetchColumn());
        self::assertSame('Comment 1', $this->pdo->query("SELECT rating_comment FROM trips WHERE place_name = 'Stay 2'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM postings WHERE payee = 'Inflow 1' AND direction = 'in' AND pay_cycle_id IS NOT NULL")->fetchColumn());
        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM postings WHERE odometer_miles IS NOT NULL')->fetchColumn());
        $vehicle = $this->pdo->query("SELECT name, kind, starred, mpg_min_tenths, mpg_max_tenths FROM vehicles WHERE name = 'Vehicle 1'")->fetch();
        self::assertIsArray($vehicle);
        self::assertSame('rv', $vehicle['kind']);
        self::assertSame(1, (int) $vehicle['starred']);
        self::assertSame(60, (int) $vehicle['mpg_min_tenths']);
        self::assertSame(120, (int) $vehicle['mpg_max_tenths']);
        self::assertNotNull($byName['Archived Payor']['archivedAt']);

        $_SESSION['user_id'] = $ownerId;
        self::assertSame([], $cycles->list('2026-11-03', true));
    }

    /** @param list<array<string, mixed>> $rows
     *  @return array<string, mixed>
     */
    private function named(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        self::fail('Missing ' . $name);
    }

    /** @param list<mixed> $params
     *  @return list<string>
     */
    private function column(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return is_array($values) ? array_map(strval(...), $values) : [];
    }
}
