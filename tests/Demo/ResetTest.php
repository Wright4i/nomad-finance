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
        self::assertCount(11, $seeded);
        $checking = $this->named($seeded, 'Everyday checking');
        self::assertSame('checking', $checking['type']);
        self::assertSame(318000, $checking['balanceMinor']);
        self::assertFalse($checking['assetFavorite']);
        self::assertFalse($checking['gasFavorite']);
        self::assertTrue($this->named($seeded, 'Fuel card')['gasFavorite']);
        self::assertTrue($this->named($seeded, 'Travel card')['shoppingFavorite']);
        self::assertTrue($this->named($seeded, 'Emergency fund')['assetFavorite']);
        $draw = $this->pdo->query(
            "SELECT draw.name FROM bills b
             JOIN accounts draw ON draw.id = b.account_id
             JOIN accounts source ON source.id = b.source_account_id
             JOIN user u ON u.id = source.user_id
             WHERE u.role = 'demo' AND source.name = 'Travel card'"
        )->fetchColumn();
        self::assertSame('Emergency fund', $draw);

        $accounts->create([
            'name' => 'Scratch',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 100,
        ]);
        self::assertCount(12, $accounts->list(false));

        try {
            $accounts->get($harbor['id']);
            self::fail('Demo cannot open the owner account.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }

        (new DemoLibrary($this->pdo, $catalog))->resetDemo();
        $restored = $accounts->list(false);
        self::assertCount(11, $restored);
        $names = array_column($restored, 'name');
        self::assertNotContains('Scratch', $names);
        self::assertContains('Everyday checking', $names);
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
        self::assertSame(['cash', 'checking', 'credit', 'fuel', 'loan', 'other', 'savings'], $types);

        $otherSides = $this->column(
            "SELECT DISTINCT side FROM accounts WHERE user_id = ? AND type = 'other' ORDER BY side",
            [$demoId],
        );
        self::assertSame(['asset', 'liability'], $otherSides);

        $archived = $this->pdo->prepare('SELECT COUNT(*) FROM accounts WHERE user_id = ? AND archived_at IS NOT NULL');
        $archived->execute([$demoId]);
        self::assertSame(1, (int) $archived->fetchColumn());

        $card = $this->pdo->prepare(
            'SELECT interest_bps, minimum_due_minor, due_day, credit_limit_minor FROM accounts WHERE user_id = ? AND name = \'Travel card\''
        );
        $card->execute([$demoId]);
        self::assertSame(
            ['interest_bps' => 1999, 'minimum_due_minor' => 3500, 'due_day' => 15, 'credit_limit_minor' => 600000],
            array_map(intval(...), $card->fetch() ?: []),
        );

        $currencies = $this->column(
            'SELECT DISTINCT currency FROM accounts WHERE user_id = ?',
            [$demoId],
        );
        self::assertSame(['USD'], $currencies);
        $cash = $this->pdo->prepare(
            'SELECT name, balance_minor FROM accounts WHERE user_id = ? AND name IN (\'Rig fund\', \'Camp jar\', \'Glovebox\') ORDER BY name'
        );
        $cash->execute([$demoId]);
        self::assertSame([
            ['name' => 'Camp jar', 'balance_minor' => 14000],
            ['name' => 'Glovebox', 'balance_minor' => 4500],
            ['name' => 'Rig fund', 'balance_minor' => 145000],
        ], $cash->fetchAll());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('exchange_rates', 'postings', 'posting_lines')"
        )->fetchColumn());

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
            'SELECT b.other_label FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? AND b.name = \'Shower tokens\''
        );
        $otherName->execute([$demoId]);
        self::assertSame('Shower tokens', $otherName->fetchColumn());
        $recurrences = $this->column(
            'SELECT DISTINCT b.recurrence FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.recurrence',
            [$demoId],
        );
        self::assertSame(['daily', 'monthly', 'once', 'weekly', 'yearly'], $recurrences);
        $every = $this->pdo->prepare(
            'SELECT b.every_count FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? AND b.name = \'Registration\''
        );
        $every->execute([$demoId]);
        self::assertSame(2, (int) $every->fetchColumn());
        $statuses = $this->column(
            'SELECT DISTINCT b.status FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.status',
            [$demoId],
        );
        self::assertSame(['active', 'archived', 'paid', 'paused'], $statuses);

        $names = $this->column(
            'SELECT b.name FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.name',
            [$demoId],
        );
        self::assertSame([
            'America the Beautiful',
            'Broker sweep',
            'Camp club',
            'Camp soap',
            'Camp store',
            'Cash buffer',
            'Cattail Cove',
            'Clinic visit',
            'Cottonwood',
            'County night',
            'DEF fluid',
            'Diner',
            'Dog food',
            'Emergency move',
            'Estimated tax',
            'Family lot',
            'Fill-up',
            'Fill-up',
            'Fill-up',
            'Fill-up',
            'Fill-up',
            'Fill-up',
            'Fill-up',
            'Fresh water',
            'Fuel card',
            'Fuel stop',
            'Groceries',
            'Health share',
            'La Posa South',
            'Laundry',
            'Lost Dutchman',
            'Mailbox',
            'Map app',
            'McDowell Mountain',
            'Metered electric',
            'Midland',
            'October site',
            'Old meal kit',
            'Park pass',
            'Pedestal',
            'Phone',
            'Propane',
            'RV insurance',
            'Registration',
            'Repair parts',
            'Rig service',
            'Roadside',
            'Shore power',
            'Shower tokens',
            'Site week',
            'Solar loan',
            'Starlink',
            'Storage unit',
            'Streaming',
            'Travel card',
            'Urgent care',
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
        $ownerNames = array_column($categories->list(true), 'name');
        self::assertContains('Rent', $ownerNames);
        self::assertNotContains('Needs Default', $ownerNames);

        $auth->enterDemo();
        $categories->create(['name' => 'Scratch Category', 'group' => 'wants']);
        (new DemoLibrary($this->pdo, $catalog))->resetDemo();

        $rows = $categories->list(true, '2026-11-03');
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }
        $names = array_column($rows, 'name');
        foreach (['Old gym', 'Road cushion', 'Fun money', 'Tires', 'Next rig', 'Repair reserve', 'Fuel', 'Rent'] as $expected) {
            self::assertContains($expected, $names);
        }
        self::assertNotContains('Needs Default', $names);
        self::assertNotContains('Fuel Bucket', $names);
        self::assertNull($byName['Road cushion']['target']);
        self::assertSame('needed', $byName['Tires']['target']['kind']);
        self::assertSame(120000, $byName['Tires']['target']['amountMinor']);
        self::assertSame('2027-02-01', $byName['Tires']['target']['targetDate']);
        self::assertSame('Remote salary', $byName['Tires']['target']['payor']);
        self::assertSame(4, $byName['Tires']['target']['periodsLeft']);
        self::assertSame(30000, $byName['Tires']['target']['neededMinor']);
        self::assertSame(50000, $byName['Repair reserve']['target']['neededMinor']);
        self::assertFalse($byName['Repair reserve']['target']['met']);
        self::assertSame('needs', $byName['Road cushion']['group']);
        self::assertNull($byName['Road cushion']['archivedAt']);
        self::assertSame(50000, $byName['Road cushion']['amountMinor']);
        self::assertSame('wants', $byName['Fun money']['group']);
        self::assertSame(0, $byName['Fun money']['amountMinor']);
        self::assertSame('savings', $byName['Next rig']['group']);
        self::assertSame('needs', $byName['Old gym']['group']);
        self::assertNotNull($byName['Old gym']['archivedAt']);
        self::assertNotContains('Scratch Category', array_column($rows, 'name'));
        self::assertNotContains('Old gym', array_column($categories->list(false), 'name'));

        $_SESSION['user_id'] = $ownerId;
        $restored = array_column($categories->list(true), 'name');
        self::assertContains('Rent', $restored);
        self::assertNotContains('Road cushion', $restored);
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
            ['Camp host', 'Client average', 'Contract days', 'Design retainer', 'Editing days', 'Market booth', 'Photo gigs', 'Remote salary', 'Warehouse job'],
            array_column($rows, 'payor'),
        );
        self::assertNotContains('Scratch Payor', array_column($rows, 'payor'));
        self::assertSame(
            ['Camp host', 'Photo gigs', 'Remote salary'],
            array_column($cycles->list('2026-11-03', false), 'payor'),
        );
        self::assertSame('salary', $byName['Remote salary']['payKind']);
        self::assertSame(560000, $byName['Remote salary']['paycheckMinor']);
        self::assertSame('monthly', $byName['Remote salary']['length']);
        self::assertSame('2026-11-01', $byName['Remote salary']['payday']);
        self::assertSame('2026-11-01', $byName['Remote salary']['periodStart']);
        self::assertSame('2026-11-30', $byName['Remote salary']['periodEnd']);
        self::assertSame('2026-12-01', $byName['Remote salary']['rollsOn']);
        self::assertSame('monthly', $byName['Contract days']['length']);
        self::assertSame('weekdays', $byName['Contract days']['hoursMode']);
        self::assertSame('3.00', $byName['Contract days']['hours']);
        self::assertSame(2000, $byName['Contract days']['rateMinor']);
        self::assertSame(132000, $byName['Contract days']['paycheckMinor']);
        self::assertSame(22, $byName['Contract days']['monthlyWeekday']['weekdays']);
        self::assertSame(15, $byName['Design retainer']['secondDay']);
        self::assertSame('weekdays', $byName['Editing days']['hoursMode']);
        self::assertFalse($byName['Editing days']['averageOn']);
        self::assertSame(105600, $byName['Editing days']['paycheckMinor']);
        self::assertSame('typed', $byName['Camp host']['hoursMode']);
        self::assertFalse($byName['Camp host']['averageOn']);
        self::assertTrue($byName['Client average']['averageOn']);
        self::assertSame(95000, $byName['Client average']['paycheckMinor']);
        self::assertSame('2026-12-15', $byName['Photo gigs']['nextPayday']);
        $user = $this->pdo->query("SELECT acct_period_start, acct_period_length FROM user WHERE role = 'demo'")->fetch();
        self::assertIsArray($user);
        self::assertSame('1 month', $user['acct_period_length']);
        self::assertSame(gmdate('Y-m-01'), $user['acct_period_start']);
        self::assertSame(400, (int) $this->pdo->query(
            "SELECT v.fuel_price_minor FROM vehicles v JOIN user u ON u.id = v.user_id WHERE u.role = 'demo' AND v.name = 'Bunkhouse'"
        )->fetchColumn());
        self::assertSame('fly', $this->pdo->query("SELECT travel FROM trips WHERE place_name = 'Devils Garden'")->fetchColumn());
        self::assertSame('drive', $this->pdo->query("SELECT travel FROM trips WHERE place_name = 'Lost Dutchman'")->fetchColumn());
        self::assertSame('2026-11-22', $this->pdo->query("SELECT balance_due_on FROM trips WHERE place_name = 'Lost Dutchman'")->fetchColumn());
        self::assertSame('2026-12-01', $this->pdo->query("SELECT balance_due_on FROM trips WHERE place_name = 'Cottonwood'")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT rating_half_stars FROM trips WHERE place_name = 'Lost Dutchman'")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT rating_comment FROM trips WHERE place_name = 'Lost Dutchman'")->fetchColumn());
        self::assertSame(7, (int) $this->pdo->query("SELECT rating_half_stars FROM trips WHERE place_name = 'Cottonwood'")->fetchColumn());
        self::assertSame('Warm days, clear nights.', $this->pdo->query("SELECT rating_comment FROM trips WHERE place_name = 'Cottonwood'")->fetchColumn());
        $span = $this->pdo->query(
            "SELECT arrive_on, depart_on FROM trips t JOIN user u ON u.id = t.user_id WHERE u.role = 'demo' ORDER BY arrive_on"
        )->fetchAll();
        self::assertIsArray($span);
        $covered = [];
        $first = null;
        $last = null;
        foreach ($span as $stay) {
            self::assertIsArray($stay);
            $arrive = (string) $stay['arrive_on'];
            $depart = (string) $stay['depart_on'];
            $first = $first === null || $arrive < $first ? $arrive : $first;
            $last = $last === null || $depart > $last ? $depart : $last;
            $day = new \DateTimeImmutable($arrive);
            $stop = new \DateTimeImmutable($depart);
            while ($day <= $stop) {
                $covered[$day->format('Y-m-d')] = true;
                $day = $day->modify('+1 day');
            }
        }
        self::assertSame('2026-09-06', $first);
        self::assertSame('2027-01-08', $last);
        $missing = [];
        for ($day = new \DateTimeImmutable((string) $first); $day <= new \DateTimeImmutable((string) $last); $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            if (!isset($covered[$key])) {
                $missing[] = $key;
            }
        }
        self::assertSame([], $missing);
        self::assertArrayHasKey('2026-11-05', $covered);
        $fuel = $this->pdo->query(
            "SELECT b.anchor_date, b.amount_minor, b.status, b.odometer_miles, b.price_per_gallon_minor, b.vehicle_id IS NOT NULL AS has_vehicle
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             JOIN user u ON u.id = a.user_id
             WHERE u.role = 'demo' AND b.name = 'Fill-up' AND b.status = 'paid'
             ORDER BY b.anchor_date, b.id"
        )->fetchAll();
        self::assertSame([
            ['anchor_date' => '2026-09-15', 'amount_minor' => 1000, 'status' => 'paid', 'odometer_miles' => 9980, 'price_per_gallon_minor' => 400, 'has_vehicle' => 1],
            ['anchor_date' => '2026-10-01', 'amount_minor' => 600, 'status' => 'paid', 'odometer_miles' => 10000, 'price_per_gallon_minor' => 400, 'has_vehicle' => 1],
            ['anchor_date' => '2026-10-01', 'amount_minor' => 400, 'status' => 'paid', 'odometer_miles' => 10000, 'price_per_gallon_minor' => 400, 'has_vehicle' => 1],
            ['anchor_date' => '2026-10-15', 'amount_minor' => 500, 'status' => 'paid', 'odometer_miles' => null, 'price_per_gallon_minor' => null, 'has_vehicle' => 0],
            ['anchor_date' => '2026-11-01', 'amount_minor' => 2000, 'status' => 'paid', 'odometer_miles' => 10100, 'price_per_gallon_minor' => 400, 'has_vehicle' => 1],
            ['anchor_date' => '2026-11-08', 'amount_minor' => 1000, 'status' => 'paid', 'odometer_miles' => 10300, 'price_per_gallon_minor' => 400, 'has_vehicle' => 1],
            ['anchor_date' => '2026-11-18', 'amount_minor' => 1000, 'status' => 'paid', 'odometer_miles' => 10500, 'price_per_gallon_minor' => 400, 'has_vehicle' => 1],
        ], $fuel);
        $vehicle = $this->pdo->query("SELECT name, kind, starred, mpg_min_tenths, mpg_max_tenths FROM vehicles WHERE name = 'Bunkhouse'")->fetch();
        self::assertIsArray($vehicle);
        self::assertSame('rv', $vehicle['kind']);
        self::assertSame(1, (int) $vehicle['starred']);
        self::assertSame(60, (int) $vehicle['mpg_min_tenths']);
        self::assertSame(120, (int) $vehicle['mpg_max_tenths']);
        self::assertSame('utv', $this->pdo->query("SELECT kind FROM vehicles WHERE name = 'Side-by-side'")->fetchColumn());
        self::assertSame('atv', $this->pdo->query("SELECT kind FROM vehicles WHERE name = 'Quad'")->fetchColumn());
        self::assertSame('golf cart', $this->pdo->query("SELECT kind FROM vehicles WHERE name = 'Golf cart'")->fetchColumn());
        self::assertSame('dirt bike', $this->pdo->query("SELECT kind FROM vehicles WHERE name = 'Dirt bike'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT starred FROM vehicles WHERE name = 'Side-by-side'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT starred FROM vehicles WHERE name = 'Dirt bike'")->fetchColumn());
        self::assertNotNull($byName['Warehouse job']['archivedAt']);

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
