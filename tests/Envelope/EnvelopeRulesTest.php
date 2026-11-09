<?php

declare(strict_types=1);

namespace App\Tests\Envelope;

use App\Account\AccountService;
use App\Calendar\CalendarEntryService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Category\CategoryService;
use App\Database\Database;
use App\Dashboard\DashboardService;
use App\Demo\DemoLibrary;
use App\Envelope\BillFill;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Trip\Drive;
use App\Trip\TripService;
use PDO;
use PHPUnit\Framework\TestCase;

final class EnvelopeRulesTest extends TestCase
{
    private PDO $pdo;

    private EnvelopeService $envelopes;

    private CategoryService $categories;

    private BillService $bills;

    private AccountService $accounts;

    private string $path;

    private int $ownerId;

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
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $this->ownerId = (int) $_SESSION['user_id'];
        $auth->enterDemo();
        $targets = new TargetService($this->pdo, $actor);
        $this->envelopes = new EnvelopeService($this->pdo, $actor, $catalog, $targets, new PayCycleService($this->pdo, $actor), new Drive(false));
        $this->categories = new CategoryService($this->pdo, $actor, $targets);
        $this->bills = new BillService($this->pdo, $catalog, $actor);
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testThePoolUsesCashAndNamesACurrencyWithNoRate(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');

        self::assertSame('Remote salary', $this->payor($sheet));
        self::assertSame('2026-11-01', $sheet['period']['start']);
        self::assertSame('2026-11-30', $sheet['period']['end']);
        self::assertTrue($sheet['period']['editable']);
        self::assertSame(50000, $this->amount($sheet, 'Road cushion'));
        self::assertSame(0, $this->amount($sheet, 'Fun money'));
        self::assertSame($this->sourceTotal($sheet), $sheet['poolMinor']);
        self::assertSame($sheet['poolMinor'] - $sheet['assignedMinor'], $sheet['availableMinor']);
        self::assertLessThan($sheet['poolMinor'], $sheet['availableMinor']);
        $poolNames = array_column($sheet['sources']['accounts'], 'name');
        self::assertContains('Everyday checking', $poolNames);
        self::assertContains('Wallet', $poolNames);
        self::assertContains('Solar kit', $poolNames);
        self::assertNotContains('Emergency fund', $poolNames);
        self::assertNotContains('Rig fund', $poolNames);
        $listedIncome = 0;
        foreach ($sheet['sources']['income'] as $row) {
            $listedIncome += (int) $row['amountMinor'];
        }
        $pay = new PayCycleService($this->pdo, new Actor($this->pdo));
        $stillComing = 0;
        $wholeMonth = 0;
        foreach ($pay->monthPay('2026-11', '2026-11-03') as $row) {
            $wholeMonth += (int) $row['paycheckMinor'];
            if ((string) $row['on'] >= '2026-11-03') {
                $stillComing += (int) $row['paycheckMinor'];
            }
        }
        self::assertGreaterThan($stillComing, $wholeMonth);
        self::assertSame($stillComing, $listedIncome);
        $savings = $this->balance('Emergency fund');
        $this->setBalance('Emergency fund', $savings - 5000);
        $withoutSavings = $this->envelopes->show('2026-11-03');
        self::assertSame($sheet['availableMinor'], $withoutSavings['availableMinor']);
        self::assertSame($sheet['poolMinor'], $withoutSavings['poolMinor']);
        $this->setBalance('Emergency fund', $savings);
        self::assertGreaterThan(50000, $sheet['assignedMinor']);
        self::assertSame(120000, $this->targetAmount($sheet, 'Tires'));
        self::assertSame(50000, $this->targetAmount($sheet, 'Repair reserve'));
        self::assertNull($this->category($sheet, 'Road cushion')['target']);
        self::assertNotSame($sheet['assignedMinor'], $sheet['availableMinor']);
        self::assertSame([], $sheet['leftOut']);
        self::assertSame(84000, $this->amount($sheet, 'Rent'));
        self::assertSame(84000, $this->billMinor($sheet, 'Rent'));
        self::assertSame(46000, $this->billMinor($sheet, 'Groceries'));
        self::assertSame(0, $this->billMinor($sheet, 'Entertainment'));
        self::assertSame(['2026-12-01', '2026-12-31'], [$sheet['nextPeriod']['start'], $sheet['nextPeriod']['end']]);
        self::assertFalse($sheet['celebrate']);

        $closed = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame('2026-10-01', $closed['period']['start']);
        self::assertFalse($closed['period']['editable']);
        self::assertSame(20000, $this->amount($closed, 'Road cushion'));
        self::assertSame(50000, $this->namedCategory('Road cushion')['amountMinor']);

        $checking = $this->balance('Everyday checking');
        $cash = $this->balance('Wallet');
        $this->setBalance('Everyday checking', $checking - 1000);
        $this->setBalance('Wallet', $cash + 1000);
        $moved = $this->envelopes->show('2026-11-03');
        self::assertSame($sheet['availableMinor'], $moved['availableMinor']);

        $card = $this->balance('Travel card');
        $this->setBalance('Travel card', $card + 50000);
        $untouched = $this->envelopes->show('2026-11-03');
        self::assertSame($sheet['availableMinor'], $untouched['availableMinor']);

        $bill = $this->bill('Site week');
        self::assertSame('Road cushion', $bill['categoryName']);
        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-11-03']);
        self::assertSame('paid', $paid['status']);
        self::assertSame('Road cushion', $paid['categoryName']);
        self::assertSame($checking - 1000, $this->balance('Everyday checking'));
        self::assertSame(50000, $this->namedCategory('Road cushion')['amountMinor']);

        $_SESSION['user_id'] = $this->ownerId;
        $owner = $this->envelopes->show('2026-11-03');
        self::assertSame([], $owner['payors']);
        self::assertSame([], $owner['leftOut']);
        self::assertSame(0, $owner['assignedMinor']);
    }

    public function testAvailableSubtractsAssignmentsNotMaximums(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Road cushion');
        $wants = $this->categoryId($sheet, 'Fun money');
        self::assertSame(50000, $this->amount($sheet, 'Road cushion'));
        self::assertNull($this->category($sheet, 'Road cushion')['target']);
        self::assertSame($this->sourceTotal($sheet), $sheet['poolMinor']);
        self::assertSame($sheet['poolMinor'] - $sheet['assignedMinor'], $sheet['availableMinor']);

        $raised = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 50000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => 80000]],
        ]);
        self::assertSame(50000, $this->amount($raised, 'Road cushion'));
        self::assertSame(80000, $this->targetAmount($raised, 'Road cushion'));
        self::assertSame($sheet['assignedMinor'], $raised['assignedMinor']);
        self::assertSame($sheet['availableMinor'], $raised['availableMinor']);

        $assigned = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 90000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => 80000]],
        ]);
        self::assertSame(90000, $this->amount($assigned, 'Road cushion'));
        self::assertSame($raised['assignedMinor'] + 40000, $assigned['assignedMinor']);
        self::assertSame($raised['availableMinor'] - 40000, $assigned['availableMinor']);

        $empty = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $needs, 'amountMinor' => 90000],
                ['categoryId' => $wants, 'amountMinor' => 0],
            ],
            'maximums' => [['categoryId' => $wants, 'amountMinor' => 4000]],
        ]);
        self::assertSame(0, $this->amount($empty, 'Fun money'));
        self::assertSame($assigned['availableMinor'], $empty['availableMinor']);

        $weekly = $this->payCycleId($sheet, 'Camp host');
        $week = $this->envelopes->show('2026-11-03', $weekly);
        $again = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $weekly,
            'amounts' => [['categoryId' => $wants, 'amountMinor' => 2500]],
        ]);
        self::assertSame(2500, $this->amount($again, 'Fun money'));
        self::assertSame($week['assignedMinor'] + 2500, $again['assignedMinor']);
        self::assertSame($week['availableMinor'] - 2500, $again['availableMinor']);

        $monthly = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        self::assertSame($empty['availableMinor'] - 2500, $monthly['availableMinor']);
        self::assertSame(90000, $this->amount($monthly, 'Road cushion'));
        self::assertSame($again['poolMinor'], $monthly['poolMinor']);
        self::assertSame(
            $again['availableMinor'] - ($monthly['assignedMinor'] - $again['assignedMinor']),
            $monthly['availableMinor'],
        );

        $rent = $this->categoryId($sheet, 'Rent');
        $bills = $this->billMinor($monthly, 'Rent');
        $held = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $needs, 'amountMinor' => 90000],
                ['categoryId' => $wants, 'amountMinor' => 7000],
            ],
            'maximums' => [['categoryId' => $rent, 'amountMinor' => $bills + 1500]],
        ]);
        self::assertSame($bills, $this->amount($held, 'Rent'));
        self::assertSame($bills + 1500, $this->targetAmount($held, 'Rent'));
        self::assertSame($monthly['poolMinor'], $held['poolMinor']);
        self::assertGreaterThan($monthly['assignedMinor'], $held['assignedMinor']);
        self::assertSame(
            $monthly['availableMinor'] - ($held['assignedMinor'] - $monthly['assignedMinor']),
            $held['availableMinor'],
        );

        $closed = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame($held['availableMinor'], $closed['availableMinor']);
        $upcoming = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], null, true);
        self::assertSame($held['availableMinor'], $upcoming['availableMinor']);

        $cleared = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 90000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => 0]],
        ]);
        self::assertNull($this->category($cleared, 'Road cushion')['target']);
        self::assertSame($held['poolMinor'], $cleared['poolMinor']);
        self::assertSame(
            $held['availableMinor'] - ($cleared['assignedMinor'] - $held['assignedMinor']),
            $cleared['availableMinor'],
        );
    }

    public function testAStayRaisesTheFuelAssignmentByItsEstimate(): void
    {
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        $trips = new TripService($this->pdo, $actor, $catalog, new Drive(false));
        $november = $trips->fuelEstimates('2026-11-01', '2026-11-30', '2026-11-03');
        $names = array_column($november, 'name');
        self::assertContains('McDowell Mountain', $names);
        self::assertContains('Lost Dutchman', $names);
        self::assertContains('Cattail Cove', $names);
        self::assertNotContains('Usery Mountain', $names);
        self::assertNotContains('Cottonwood', $names);
        $stayMinor = array_sum(array_column($november, 'costMinor'));
        self::assertGreaterThan(0, $stayMinor);

        $trips->syncAllFuelBills('2026-11-03');
        $sheet = $this->envelopes->show('2026-11-03');
        $fuel = $this->category($sheet, 'Fuel');
        $stayBills = 0;
        foreach ($fuel['bills'] as $bill) {
            if (in_array($bill['name'], $names, true)) {
                $stayBills += (int) $bill['amountMinor'];
            }
        }
        self::assertSame($stayMinor, $stayBills);
        $otherBills = 0;
        foreach ($fuel['bills'] as $bill) {
            if (!in_array($bill['name'], $names, true)) {
                $otherBills += (int) $bill['amountMinor'];
            }
        }
        self::assertSame($stayBills + $otherBills, (int) $fuel['billMinor']);
        self::assertGreaterThanOrEqual(8500, $otherBills);
        self::assertGreaterThanOrEqual((int) $fuel['billMinor'], (int) $fuel['amountMinor']);

        try {
            $this->envelopes->save([
                'today' => '2026-11-03',
                'payCycleId' => $sheet['payCycleId'],
                'amounts' => [['categoryId' => (int) $fuel['id'], 'amountMinor' => (int) $fuel['billMinor'] - 1]],
            ]);
            self::fail('Fuel accepted an assignment under its stays.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Assigned cannot be lower than the bills.', $e->fields['amount-' . $fuel['id']]);
        }

        $december = $trips->fuelEstimates('2026-12-01', '2026-12-31', '2026-11-03');
        $later = $trips->fuelEstimates('2026-11-01', '2026-11-30', '2026-11-25');
        $laterNames = array_column($later, 'name');
        self::assertContains('Cattail Cove', $laterNames);
        self::assertNotContains('Lost Dutchman', $laterNames);
        self::assertNotContains('McDowell Mountain', $laterNames);
        $laterSheet = $this->envelopes->show('2026-11-25');
        $laterListed = array_column($this->category($laterSheet, 'Fuel')['bills'], 'name');
        self::assertContains('Cattail Cove', $laterListed);
        self::assertNotContains('Lost Dutchman', $laterListed);
        self::assertNotContains('McDowell Mountain', $laterListed);
        $decemberNames = array_column($december, 'name');
        self::assertContains('Cottonwood', $decemberNames);
        self::assertContains('Midland', $decemberNames);
        self::assertContains('La Posa South', $decemberNames);
        self::assertNotContains('Cattail Cove', $decemberNames);
        $next = $this->envelopes->show('2026-11-03', $this->payCycleId($sheet, 'Remote salary'), null, true);
        $nextFuel = $this->category($next, 'Fuel');
        $nextStay = 0;
        foreach ($nextFuel['bills'] as $bill) {
            if (in_array($bill['name'], $decemberNames, true)) {
                $nextStay += (int) $bill['amountMinor'];
            }
        }
        self::assertSame(array_sum(array_column($december, 'costMinor')), $nextStay);
        self::assertGreaterThanOrEqual((int) $nextFuel['billMinor'], (int) $nextFuel['amountMinor']);
    }

    public function testAnOpenLiabilityLogShrinksTheStoredGroceriesEnvelope(): void
    {
        $this->pdo->prepare('UPDATE user SET envelope_adopted = 1 WHERE id = ?')->execute([$this->ownerId]);
        $sheet = $this->envelopes->show('2026-11-07', null, null, false, true);
        $groceries = $this->category($sheet, 'Groceries');
        $beforeBills = $this->billMinor($sheet, 'Groceries');
        $envelope = 25000;
        $saved = $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => $this->totalsWithEnvelopes($sheet, ['Groceries' => $envelope]),
            'acctPeriod' => '1',
        ]);
        self::assertSame($envelope, $this->amount($saved, 'Groceries') - $this->billMinor($saved, 'Groceries'));

        $logged = $this->bills->log([
            'kind' => 'need',
            'type' => 'groceries',
            'name' => 'Market',
            'accountId' => $this->accountId('Travel card'),
            'amountMinor' => 10000,
            'date' => '2026-11-07',
            'today' => '2026-11-07',
        ]);
        self::assertSame('active', $logged['status']);

        $after = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame($beforeBills + 10000, $this->billMinor($after, 'Groceries'));
        self::assertSame($envelope - 10000, $this->amount($after, 'Groceries') - $this->billMinor($after, 'Groceries'));
        $stmt = $this->pdo->prepare('SELECT envelope_minor FROM categories WHERE id = ?');
        $stmt->execute([(int) $groceries['id']]);
        self::assertSame($envelope - 10000, (int) $stmt->fetchColumn());
    }

    public function testAPaidAssetNeedLogShrinksTheStoredGroceriesEnvelope(): void
    {
        $this->pdo->prepare('UPDATE user SET envelope_adopted = 1 WHERE id = ?')->execute([$this->ownerId]);
        $sheet = $this->envelopes->show('2026-11-07', null, null, false, true);
        $groceries = $this->category($sheet, 'Groceries');
        $beforeBills = $this->billMinor($sheet, 'Groceries');
        $envelope = 25000;
        $saved = $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => $this->totalsWithEnvelopes($sheet, ['Groceries' => $envelope]),
            'acctPeriod' => '1',
        ]);
        self::assertSame($envelope, $this->amount($saved, 'Groceries') - $this->billMinor($saved, 'Groceries'));

        $logged = $this->bills->log([
            'kind' => 'need',
            'type' => 'groceries',
            'name' => 'Cash market',
            'accountId' => $this->accountId('Everyday checking'),
            'amountMinor' => 10000,
            'date' => '2026-11-07',
            'today' => '2026-11-07',
        ]);
        self::assertSame('paid', $logged['status']);

        $after = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame($beforeBills, $this->billMinor($after, 'Groceries'));
        self::assertSame($envelope - 10000, $this->amount($after, 'Groceries') - $this->billMinor($after, 'Groceries'));
        $stmt = $this->pdo->prepare('SELECT envelope_minor FROM categories WHERE id = ?');
        $stmt->execute([(int) $groceries['id']]);
        self::assertSame($envelope - 10000, (int) $stmt->fetchColumn());
    }

    public function testRecalculateReplacesTheMonthAndALogLeavesTheBucketsAlone(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $before = $this->storedAssignments();
        $balance = $this->balance('Everyday checking');
        $checking = $this->accountId('Everyday checking');
        $vehicle = $this->pdo->query("SELECT id FROM vehicles WHERE name = 'Bunkhouse'");
        $vehicleId = (int) $vehicle->fetchColumn();
        $logged = $this->bills->log([
            'kind' => 'need',
            'type' => 'groceries',
            'name' => 'Market run',
            'accountId' => $checking,
            'amountMinor' => 1800,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        $fuel = $this->bills->log([
            'kind' => 'fuel',
            'accountId' => $checking,
            'amountMinor' => 2500,
            'vehicleId' => $vehicleId,
            'odometerMiles' => 10600,
            'date' => '2026-11-05',
            'today' => '2026-11-05',
        ]);
        self::assertSame('once', $logged['recurrence']);
        self::assertSame('paid', $logged['status']);
        self::assertSame('2026-11-05', $logged['lastPaidOn']);
        self::assertSame('once', $fuel['recurrence']);
        self::assertSame('paid', $fuel['status']);
        self::assertSame('Fuel', $fuel['name']);
        self::assertNotSame($before, $this->storedAssignments());
        self::assertSame($balance - 4300, $this->balance('Everyday checking'));
        $afterLog = $this->envelopes->show('2026-11-05');
        $groceries = $this->category($afterLog, 'Groceries');
        $market = 0;
        foreach ($groceries['bills'] as $bill) {
            if ($bill['name'] === 'Market run') {
                $market += (int) $bill['amountMinor'];
            }
        }
        self::assertSame(0, $market);
        self::assertGreaterThanOrEqual((int) $groceries['billMinor'], (int) $groceries['amountMinor']);
        $fuelBucket = $this->category($afterLog, 'Fuel');
        self::assertGreaterThanOrEqual((int) $fuelBucket['billMinor'], (int) $fuelBucket['amountMinor']);

        $needs = $this->categoryId($sheet, 'Road cushion');
        $held = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 50000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => 80000]],
        ]);
        $october = $this->period($held, '2026-10-01');
        $again = $this->envelopes->recalculate([
            'today' => '2026-11-03',
            'payCycleId' => $held['payCycleId'],
        ]);
        self::assertSame(38500, $this->amount($again, 'Road cushion'));
        self::assertSame(38500, $this->billMinor($again, 'Road cushion'));
        self::assertSame(80000, $this->targetAmount($again, 'Road cushion'));
        self::assertSame($this->billMinor($again, 'Fuel'), $this->amount($again, 'Fuel'));
        self::assertGreaterThan(8500, $this->billMinor($again, 'Fuel'));
        $groceries = $this->category($again, 'Groceries');
        $market = 0;
        foreach ($groceries['bills'] as $bill) {
            if ($bill['name'] === 'Market run') {
                $market += (int) $bill['amountMinor'];
            }
        }
        self::assertSame(0, $market);
        self::assertSame((int) $groceries['billMinor'], (int) $groceries['amountMinor']);
        self::assertSame($balance - 4300, $this->balance('Everyday checking'));
        $closed = $this->envelopes->show('2026-11-03', $again['payCycleId'], $october);
        self::assertSame(20000, $this->amount($closed, 'Road cushion'));
    }

    public function testALockedBucketAssignsTheBillsAndKeepsTheMaximumOpen(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $rent = $this->categoryId($sheet, 'Rent');
        $needs = $this->categoryId($sheet, 'Road cushion');
        $bills = $this->billMinor($sheet, 'Rent');
        try {
            $this->envelopes->save([
                'today' => '2026-11-03',
                'payCycleId' => $sheet['payCycleId'],
                'amounts' => [
                    ['categoryId' => $needs, 'amountMinor' => 50000],
                    ['categoryId' => $rent, 'amountMinor' => $bills - 1],
                ],
            ]);
            self::fail('A locked bucket accepted an assignment under its bills.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Assigned cannot be lower than the bills.', $e->fields['amount-' . $rent]);
        }
        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $needs, 'amountMinor' => 50000],
                ['categoryId' => $rent, 'amountMinor' => $bills + 1500],
            ],
            'maximums' => [
                ['categoryId' => $needs, 'amountMinor' => 80000],
                ['categoryId' => $rent, 'amountMinor' => 90000],
            ],
        ]);
        self::assertSame($bills + 1500, $this->amount($saved, 'Rent'));
        self::assertSame($bills, $this->billMinor($saved, 'Rent'));
        self::assertSame($sheet['assignedMinor'] + 1500, $saved['assignedMinor']);
        self::assertSame(90000, $this->targetAmount($saved, 'Rent'));
        self::assertSame(80000, $this->targetAmount($saved, 'Road cushion'));
        self::assertSame($sheet['poolMinor'], $saved['poolMinor']);
        self::assertSame($sheet['availableMinor'] - 1500, $saved['availableMinor']);
        $december = $this->envelopes->show('2026-12-01', $sheet['payCycleId']);
        self::assertSame($this->billMinor($december, 'Rent'), $this->amount($december, 'Rent'));
        self::assertNotSame($bills + 1500, $this->amount($december, 'Rent'));
    }

    public function testABillFillDoesNotStackOnATypedTarget(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $road = $this->categoryId($sheet, 'Road cushion');
        $camp = $this->pdo->prepare(
            'SELECT p.id FROM pay_periods p
             JOIN pay_cycles c ON c.id = p.pay_cycle_id
             WHERE c.user_id = ? AND c.payor = ? AND p.period_start = ?'
        );
        $camp->execute([$_SESSION['user_id'], 'Camp host', '2026-11-01']);
        $periodId = (int) $camp->fetchColumn();
        self::assertGreaterThan(0, $periodId);
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor) VALUES (?, ?, 38500, 38500)'
        )->execute([$periodId, $road]);

        $stacked = $this->envelopes->show('2026-11-03');
        self::assertSame(50000, $this->amount($stacked, 'Road cushion'));
        self::assertSame(38500, $this->billMinor($stacked, 'Road cushion'));

        $this->bills->create([
            'name' => 'Extra night',
            'type' => 'rent',
            'accountId' => $this->accountId('Everyday checking'),
            'categoryId' => $road,
            'amountMinor' => 1000,
            'currency' => 'USD',
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-12',
            'today' => '2026-11-03',
        ]);
        $after = $this->envelopes->show('2026-11-03');
        self::assertSame(39500, $this->billMinor($after, 'Road cushion'));
        self::assertSame(50000, $this->amount($after, 'Road cushion'));
    }

    public function testIncludeInBudgetChoosesWhichAssetsCount(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $names = array_column($sheet['sources']['accounts'], 'name');
        self::assertNotContains('Emergency fund', $names);
        self::assertContains('Everyday checking', $names);

        $fund = $this->namedAccount('Emergency fund');
        $this->accounts->update($fund['id'], $this->accountInput($fund, true));
        $withFund = $this->envelopes->show('2026-11-03');
        self::assertContains('Emergency fund', array_column($withFund['sources']['accounts'], 'name'));
        self::assertSame($sheet['poolMinor'] + $fund['balanceMinor'], $withFund['poolMinor']);

        $checking = $this->namedAccount('Everyday checking');
        $this->accounts->update($checking['id'], $this->accountInput($checking, false));
        $without = $this->envelopes->show('2026-11-03');
        self::assertNotContains('Everyday checking', array_column($without['sources']['accounts'], 'name'));
        self::assertSame($withFund['poolMinor'] - $checking['balanceMinor'], $without['poolMinor']);
    }

    public function testAnAssignmentStaysOnTheOpenPeriod(): void
    {
        $before = $this->balance('Everyday checking');
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Road cushion');

        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 60000]],
        ]);
        self::assertSame(60000, $this->amount($saved, 'Road cushion'));
        self::assertSame($sheet['assignedMinor'] + 10000, $saved['assignedMinor']);
        self::assertSame($sheet['availableMinor'] - 10000, $saved['availableMinor']);
        self::assertSame($before, $this->balance('Everyday checking'));

        $closed = $this->envelopes->show('2026-11-03', $saved['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame(20000, $this->amount($closed, 'Road cushion'));

        $over = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => $saved['poolMinor'] + 1]],
        ]);
        self::assertSame($saved['poolMinor'] + 1, $this->amount($over, 'Road cushion'));
        self::assertSame($over['poolMinor'] - $over['assignedMinor'], $over['availableMinor']);
        self::assertLessThan(0, $over['availableMinor']);

        $capped = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 50000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => $saved['poolMinor'] + 1]],
        ]);
        self::assertSame($capped['poolMinor'] - $capped['assignedMinor'], $capped['availableMinor']);
        self::assertGreaterThan($over['availableMinor'], $capped['availableMinor']);

        $cleared = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 0]],
        ]);
        self::assertSame(0, $this->amount($cleared, 'Road cushion'));
        self::assertSame(0, $this->namedCategory('Road cushion')['amountMinor']);
        $archived = $this->categories->archive($needs);
        self::assertNotNull($archived['archivedAt']);
    }

    public function testANewMonthStartsEmptyExceptItsBills(): void
    {
        $current = $this->envelopes->show('2026-11-03');
        $monthly = $this->payCycleId($current, 'Remote salary');
        $opened = $this->envelopes->show('2026-12-01', $monthly);
        self::assertSame('2026-12-01', $opened['period']['start']);
        self::assertSame('2026-12-31', $opened['period']['end']);
        self::assertSame(0, $this->amount($opened, 'Road cushion'));
        self::assertSame(0, $this->amount($opened, 'Fun money'));
        self::assertSame($this->billMinor($opened, 'Rent'), $this->amount($opened, 'Rent'));
        self::assertGreaterThan(0, $this->billMinor($opened, 'Rent'));
        self::assertGreaterThan(50000, $opened['assignedMinor']);
        self::assertSame($this->sourceTotal($opened), $opened['poolMinor']);
        self::assertSame($opened['poolMinor'] - $opened['assignedMinor'], $opened['availableMinor']);
        self::assertLessThan($opened['poolMinor'], $opened['availableMinor']);

        $november = $this->period($opened, '2026-11-01');
        $october = $this->period($opened, '2026-10-01');
        $readNovember = $this->envelopes->show('2026-12-01', $opened['payCycleId'], $november);
        $readOctober = $this->envelopes->show('2026-12-01', $opened['payCycleId'], $october);
        self::assertSame(50000, $this->amount($readNovember, 'Road cushion'));
        self::assertSame(20000, $this->amount($readOctober, 'Road cushion'));
        self::assertSame(0, $this->amount($readOctober, 'Fun money'));
        self::assertFalse($readNovember['period']['editable']);

        $needs = $this->categoryId($opened, 'Road cushion');
        $edited = $this->envelopes->save([
            'today' => '2026-12-01',
            'payCycleId' => $opened['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 1000]],
        ]);
        self::assertSame(1000, $this->amount($edited, 'Road cushion'));

        $back = $this->envelopes->show('2026-11-03', $monthly);
        self::assertSame('2026-11-01', $back['period']['start']);
        self::assertSame(50000, $this->amount($back, 'Road cushion'));
        self::assertSame(50000, $this->namedCategory('Road cushion')['amountMinor']);

        $again = $this->envelopes->show('2026-12-01', $monthly);
        self::assertSame(1000, $this->amount($again, 'Road cushion'));
        self::assertSame(20000, $this->amount(
            $this->envelopes->show('2026-12-01', $again['payCycleId'], $october),
            'Road cushion',
        ));
    }

    public function testAnAssignmentCannotBeNegative(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Road cushion');
        try {
            $this->envelopes->save([
                'today' => '2026-11-03',
                'payCycleId' => $sheet['payCycleId'],
                'amounts' => [['categoryId' => $needs, 'amountMinor' => -10000]],
            ]);
            self::fail('A negative assignment was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('amount-' . $needs, $e->fields);
        }
        $kept = $this->envelopes->show('2026-11-03');
        self::assertSame(50000, $this->amount($kept, 'Road cushion'));
        self::assertSame($sheet['availableMinor'], $kept['availableMinor']);
    }

    public function testZeroCelebratesOnceInTheOpenPeriod(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE accounts SET balance_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('UPDATE pay_cycles SET rate_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'DELETE FROM payroll_entries WHERE pay_cycle_id IN (SELECT id FROM pay_cycles WHERE user_id = ?)'
        )->execute([$userId]);

        $empty = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $empty['poolMinor']);
        self::assertGreaterThan(0, $empty['assignedMinor']);
        self::assertSame(-$empty['assignedMinor'], $empty['availableMinor']);
        self::assertFalse($empty['celebrate']);

        $this->setBalance('Everyday checking', $empty['assignedMinor']);
        $first = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $first['availableMinor']);
        self::assertTrue($first['celebrate']);

        $again = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $again['availableMinor']);
        self::assertFalse($again['celebrate']);

        $this->setBalance('Everyday checking', $empty['assignedMinor'] + 100);
        $moved = $this->envelopes->show('2026-11-03');
        self::assertSame(100, $moved['availableMinor']);
        self::assertFalse($moved['celebrate']);

        $this->setBalance('Everyday checking', $empty['assignedMinor']);
        $return = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $return['availableMinor']);
        self::assertFalse($return['celebrate']);
    }

    public function testTheMonthIncludesEveryPayorOnceAndSplitsTheAvailableTotal(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $rent = $this->billMinor($sheet, 'Rent');
        $weekly = $this->payCycleId($sheet, 'Camp host');
        self::assertGreaterThan(1, count($sheet['payors']));
        self::assertGreaterThan(0, $rent);

        $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $weekly,
            'amounts' => [['categoryId' => $this->categoryId($sheet, 'Fun money'), 'amountMinor' => 3333]],
        ]);
        $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $this->categoryId($sheet, 'Road cushion'), 'amountMinor' => 50000]],
        ]);

        $month = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        $week = $this->envelopes->show('2026-11-03', $weekly);
        self::assertSame(50000, $this->amount($month, 'Road cushion'));
        self::assertSame(3333, $this->amount($month, 'Fun money'));
        self::assertSame(50000, $this->amount($week, 'Road cushion'));
        self::assertSame(3333, $this->amount($week, 'Fun money'));
        self::assertSame($rent, $this->billMinor($month, 'Rent'));
        self::assertSame($rent, $this->billMinor($week, 'Rent'));
        self::assertNotSame($rent * count($month['payors']), $this->billMinor($month, 'Rent'));
        self::assertSame($month['poolMinor'], $week['poolMinor']);
        self::assertSame(
            $month['availableMinor'] - ($week['assignedMinor'] - $month['assignedMinor']),
            $week['availableMinor'],
        );
        self::assertSame($this->sourceTotal($month), $month['poolMinor']);
        self::assertSame($month['poolMinor'] - $month['assignedMinor'], $month['availableMinor']);
        $this->assertSplit($month, $month['poolMinor']);
        self::assertNotEmpty($month['sources']['income']);
        self::assertNotEmpty($month['sources']['accounts']);
        $names = array_column($month['sources']['accounts'], 'name');
        self::assertContains('Everyday checking', $names);
        self::assertContains('Solar kit', $names);
        self::assertNotContains('Emergency fund', $names);
        self::assertNotContains('Travel card', $names);
        self::assertNotContains('Solar loan', $names);
        self::assertNotContains('Fuel card', $names);
        foreach ($month['sources']['accounts'] as $account) {
            self::assertContains($account['type'], ['checking', 'cash', 'other']);
        }
    }

    public function testTheDashboardShowsIncomingPayForTheAccountingPeriod(): void
    {
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        $dashboard = new DashboardService(
            $this->pdo,
            $catalog,
            $actor,
            $this->envelopes,
            new PayCycleService($this->pdo, $actor),
            new TripService($this->pdo, $actor, $catalog, new Drive(false)),
            new CalendarEntryService($this->pdo, $actor),
        );

        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-11-01', '1 month', $_SESSION['user_id']]);
        $sheet = $dashboard->show('2026-11-03');

        self::assertSame(624200, $sheet['incomeMinor']);
        self::assertSame($this->sourceTotal($sheet['envelopes']), $sheet['envelopes']['poolMinor']);
        self::assertSame(
            $sheet['envelopes']['poolMinor'] - $sheet['envelopes']['assignedMinor'],
            $sheet['envelopes']['availableMinor'],
        );
        self::assertLessThan($sheet['envelopes']['poolMinor'], $sheet['envelopes']['availableMinor']);
        self::assertGreaterThan(50000, $sheet['envelopes']['assignedMinor']);
        $byPayor = [];
        foreach ($sheet['income'] as $row) {
            $byPayor[$row['payor']] = $row['paycheckMinor'];
        }
        self::assertSame(19200, $byPayor['Camp host']);
        self::assertSame(560000, $byPayor['Remote salary']);
        self::assertSame(45000, $byPayor['Photo gigs']);
        self::assertCount(3, $byPayor);
        self::assertNull($sheet['envelopes']['previousPeriod']);
        self::assertNull($sheet['envelopes']['stepNext']);
        self::assertSame('2026-11-01', $sheet['envelopes']['period']['start']);
        self::assertSame('2026-11-30', $sheet['envelopes']['period']['end']);
        self::assertTrue($sheet['envelopes']['period']['editable']);
        self::assertFalse($sheet['envelopes']['period']['upcoming']);

        $ignored = $dashboard->show('2026-11-03', $sheet['envelopes']['payCycleId'], null, true);
        self::assertSame('2026-11-01', $ignored['envelopes']['period']['start']);
        self::assertSame('2026-11-30', $ignored['envelopes']['period']['end']);
        self::assertFalse($ignored['envelopes']['period']['upcoming']);
        self::assertSame($this->amount($sheet['envelopes'], 'Road cushion'), $this->amount($ignored['envelopes'], 'Road cushion'));
        self::assertSame($sheet['envelopes']['availableMinor'], $ignored['envelopes']['availableMinor']);
        self::assertSame(624200, $ignored['incomeMinor']);
    }

    public function testAGroupPastItsShareIsOver(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->group($sheet, 'needs');
        self::assertSame(50, $needs['targetPercent']);
        self::assertSame(50000, $needs['amountMinor']);
        self::assertSame(10000, $needs['shareBasisPoints']);
        self::assertTrue($needs['over']);
        self::assertSame(30, $this->group($sheet, 'wants')['targetPercent']);
        self::assertSame(0, $this->group($sheet, 'wants')['amountMinor']);
        self::assertFalse($this->group($sheet, 'wants')['over']);
        self::assertSame(20, $this->group($sheet, 'savings')['targetPercent']);
        self::assertSame(0, $this->group($sheet, 'savings')['amountMinor']);
        self::assertFalse($this->group($sheet, 'savings')['over']);

        $balanced = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $this->categoryId($sheet, 'Road cushion'), 'amountMinor' => 5000],
                ['categoryId' => $this->categoryId($sheet, 'Fun money'), 'amountMinor' => 3000],
                ['categoryId' => $this->categoryId($sheet, 'Next rig'), 'amountMinor' => 2000],
            ],
        ]);
        self::assertSame(5000, $this->group($balanced, 'needs')['shareBasisPoints']);
        self::assertFalse($this->group($balanced, 'needs')['over']);
        self::assertSame(3000, $this->group($balanced, 'wants')['shareBasisPoints']);
        self::assertFalse($this->group($balanced, 'wants')['over']);
        self::assertSame(2000, $this->group($balanced, 'savings')['shareBasisPoints']);
        self::assertFalse($this->group($balanced, 'savings')['over']);

        $wantsHeavy = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $this->categoryId($sheet, 'Road cushion'), 'amountMinor' => 5000],
                ['categoryId' => $this->categoryId($sheet, 'Fun money'), 'amountMinor' => 4000],
                ['categoryId' => $this->categoryId($sheet, 'Next rig'), 'amountMinor' => 1000],
            ],
        ]);
        self::assertFalse($this->group($wantsHeavy, 'needs')['over']);
        self::assertSame(4000, $this->group($wantsHeavy, 'wants')['shareBasisPoints']);
        self::assertTrue($this->group($wantsHeavy, 'wants')['over']);
        self::assertFalse($this->group($wantsHeavy, 'savings')['over']);
    }

    public function testAGroupHeadingSumsAssignedMaxAndThePayorSplit(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        self::assertSame('Remote salary', $this->payor($sheet));
        self::assertSame(560000, $sheet['incomeMinor']);
        $this->assertSplit($sheet, $sheet['poolMinor']);
        foreach (['needs', 'wants', 'savings'] as $group) {
            $heading = $this->group($sheet, $group);
            self::assertSame($this->sumAssigned($sheet, $group), $heading['assignedMinor']);
            self::assertSame($this->sumAuto($sheet, $group), $heading['autoMinor']);
            self::assertSame($this->sumEnvelope($sheet, $group), $heading['envelopeMinor']);
            self::assertSame($heading['assignedMinor'] > $heading['targetMinor'] && $heading['targetMinor'] > 0, $heading['goalOver']);
            self::assertSame($this->sumMaximum($sheet, $group), $heading['maximumMinor']);
            self::assertGreaterThanOrEqual(0, $heading['assignedMinor']);
            self::assertGreaterThanOrEqual(0, $heading['maximumMinor']);
        }
        self::assertGreaterThanOrEqual(50000 + $this->billMinor($sheet, 'Rent'), $this->group($sheet, 'needs')['assignedMinor']);
        self::assertGreaterThanOrEqual(120000 + 50000, $this->group($sheet, 'savings')['maximumMinor']);

        $closed = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame(560000, $closed['incomeMinor']);
        self::assertSame(intdiv($closed['poolMinor'] * 50 + 50, 100), $this->group($closed, 'needs')['targetMinor']);
        self::assertSame($this->sumAssigned($closed, 'needs'), $this->group($closed, 'needs')['assignedMinor']);
        self::assertNotSame($this->group($sheet, 'needs')['assignedMinor'], $this->group($closed, 'needs')['assignedMinor']);

        $paychecks = [
            'Camp host' => 19200,
            'Photo gigs' => 45000,
        ];
        foreach ($paychecks as $payor => $income) {
            $view = $this->envelopes->show('2026-11-03', $this->payCycleId($sheet, $payor));
            self::assertSame($income, $view['incomeMinor'], $payor);
            self::assertSame($sheet['poolMinor'], $view['poolMinor'], $payor);
            self::assertSame(
                $sheet['availableMinor'] - ($view['assignedMinor'] - $sheet['assignedMinor']),
                $view['availableMinor'],
                $payor,
            );
            $this->assertSplit($view, $view['poolMinor']);
        }

        $weekday = (int) $this->pdo->query(
            "SELECT id FROM pay_cycles WHERE user_id = {$_SESSION['user_id']} AND payor = 'Contract days'"
        )->fetchColumn();
        $this->pdo->prepare('UPDATE pay_cycles SET archived_at = NULL WHERE id = ?')->execute([$weekday]);
        try {
            $december = $this->envelopes->show('2026-11-03', $weekday, null, true);
        } finally {
            $this->pdo->prepare('UPDATE pay_cycles SET archived_at = ? WHERE id = ?')->execute([gmdate('c'), $weekday]);
        }
        $decemberPay = 126000;
        self::assertSame('2026-12-01', $december['period']['start']);
        self::assertSame($decemberPay, $december['incomeMinor']);
        $this->assertSplit($december, $december['poolMinor']);

        $needsBefore = $this->group($sheet, 'needs');
        $wantsBefore = $this->group($sheet, 'wants');
        $rentBills = $this->billMinor($sheet, 'Rent');
        $amounts = [];
        foreach ($sheet['categories'] as $category) {
            if (($category['role'] ?? '') === 'locked') {
                continue;
            }
            $amount = (int) $category['amountMinor'];
            if ($category['name'] === 'Fun money') {
                $amount = 4500;
            }
            $amounts[] = ['categoryId' => (int) $category['id'], 'amountMinor' => $amount];
        }
        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => $amounts,
            'maximums' => [
                ['categoryId' => $this->categoryId($sheet, 'Rent'), 'amountMinor' => 100],
                ['categoryId' => $this->categoryId($sheet, 'Fun money'), 'amountMinor' => 12300],
            ],
        ]);
        self::assertSame(560000, $saved['incomeMinor']);
        self::assertSame($needsBefore['maximumMinor'] - $rentBills + 100, $this->group($saved, 'needs')['maximumMinor']);
        self::assertSame($wantsBefore['assignedMinor'] + 4500, $this->group($saved, 'wants')['assignedMinor']);
        self::assertSame($wantsBefore['maximumMinor'] + 12300, $this->group($saved, 'wants')['maximumMinor']);
        self::assertSame($this->sumAssigned($saved, 'needs'), $this->group($saved, 'needs')['assignedMinor']);
        self::assertSame($this->sumMaximum($saved, 'needs'), $this->group($saved, 'needs')['maximumMinor']);
        self::assertSame(100, $this->targetAmount($saved, 'Rent'));
        self::assertSame($rentBills, $this->billMinor($saved, 'Rent'));
    }

    public function testAPlanForTheNextPeriodIsWhatOpens(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $preview = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], null, true);
        self::assertTrue($preview['period']['editable']);
        self::assertSame(0, $this->amount($preview, 'Road cushion'));
        self::assertSame($sheet['availableMinor'], $preview['availableMinor']);
        self::assertNull($preview['period']['id']);

        $needs = $this->categoryId($preview, 'Road cushion');
        $wants = $this->categoryId($preview, 'Fun money');
        $planned = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'upcoming' => true,
            'amounts' => [
                ['categoryId' => $needs, 'amountMinor' => 1000],
                ['categoryId' => $wants, 'amountMinor' => 0],
            ],
        ]);
        self::assertTrue($planned['period']['upcoming']);
        self::assertSame(1000, $this->amount($planned, 'Road cushion'));
        self::assertSame($sheet['availableMinor'], $planned['availableMinor']);
        self::assertSame(50000, $this->amount($this->envelopes->show('2026-11-03', $sheet['payCycleId']), 'Road cushion'));

        $opened = $this->envelopes->show('2026-12-01', $sheet['payCycleId']);
        self::assertSame('2026-12-01', $opened['period']['start']);
        self::assertFalse($opened['period']['upcoming']);
        self::assertSame(1000, $this->amount($opened, 'Road cushion'));
        self::assertSame(0, $this->amount($opened, 'Fun money'));
        self::assertSame($this->sourceTotal($opened), $opened['poolMinor']);
        self::assertSame($opened['poolMinor'] - $opened['assignedMinor'], $opened['availableMinor']);
    }

    public function testAnAmountHeldBeforeEnvelopesBecomesTheOpenAssignment(): void
    {
        $this->pdo->prepare(
            'DELETE FROM assignments WHERE pay_period_id IN (SELECT id FROM pay_periods WHERE user_id = ?)'
        )->execute([$_SESSION['user_id']]);
        $this->pdo->prepare(
            'UPDATE categories SET amount_minor = 4200 WHERE user_id = ? AND name = ?'
        )->execute([$_SESSION['user_id'], 'Road cushion']);

        $sheet = $this->envelopes->show('2026-11-03');

        self::assertSame('Camp host', $this->payor($sheet));
        self::assertSame(4200, $this->amount($sheet, 'Road cushion'));
        self::assertSame(4200, $this->namedCategory('Road cushion')['amountMinor']);
        self::assertSame(4200, $this->amount($this->envelopes->show('2026-11-03', $this->payCycleId($sheet, 'Remote salary')), 'Road cushion'));
    }

    public function testAnotherOpenPeriodCountsAgainstTheSamePool(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $weekly = null;
        foreach ($sheet['payors'] as $payor) {
            if ($payor['payor'] === 'Camp host') {
                $weekly = $payor['id'];
            }
        }
        self::assertNotNull($weekly);
        $week = $this->envelopes->show('2026-11-03', $weekly);
        self::assertSame('2026-11-01', $week['period']['start']);
        self::assertSame('2026-11-07', $week['period']['end']);
        self::assertSame(0, $this->amount($week, 'Fun money'));

        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $weekly,
            'amounts' => [['categoryId' => $this->categoryId($week, 'Fun money'), 'amountMinor' => 2500]],
        ]);
        self::assertSame(2500, $this->amount($saved, 'Fun money'));
        self::assertSame($week['assignedMinor'] + 2500, $saved['assignedMinor']);
        self::assertSame($week['availableMinor'] - 2500, $saved['availableMinor']);
        self::assertSame(2500, $this->namedCategory('Fun money')['amountMinor']);

        $monthly = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        self::assertSame(50000, $this->amount($monthly, 'Road cushion'));
        self::assertSame($sheet['assignedMinor'] + 2500, $monthly['assignedMinor']);
    }

    /** @param array<string, mixed> $sheet */
    private function assertSplit(array $sheet, int $income): void
    {
        self::assertSame(intdiv($income * 50 + 50, 100), $this->group($sheet, 'needs')['targetMinor']);
        self::assertSame(intdiv($income * 30 + 50, 100), $this->group($sheet, 'wants')['targetMinor']);
        self::assertSame(intdiv($income * 20 + 50, 100), $this->group($sheet, 'savings')['targetMinor']);
    }

    /** @param array<string, mixed> $sheet */
    private function sumAuto(array $sheet, string $group): int
    {
        $total = 0;
        foreach ($sheet['categories'] as $category) {
            if ($category['group'] !== $group) {
                continue;
            }
            $total += (int) ($category['billMinor'] ?? 0);
        }

        return $total;
    }

    private function sumEnvelope(array $sheet, string $group): int
    {
        $total = 0;
        foreach ($sheet['categories'] as $category) {
            if ($category['group'] !== $group) {
                continue;
            }
            $total += max(0, (int) $category['amountMinor'] - (int) ($category['billMinor'] ?? 0));
        }

        return $total;
    }

    private function sumAssigned(array $sheet, string $group): int
    {
        $total = 0;
        foreach ($sheet['categories'] as $category) {
            if ($category['group'] !== $group) {
                continue;
            }
            $amount = (int) $category['amountMinor'];
            if ($amount > 0) {
                $total += $amount;
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $sheet */
    private function sumMaximum(array $sheet, string $group): int
    {
        $total = 0;
        foreach ($sheet['categories'] as $category) {
            if ($category['group'] !== $group) {
                continue;
            }
            $target = is_array($category['target'] ?? null) ? ($category['target']['amountMinor'] ?? null) : null;
            $saved = is_int($target) ? $target : null;
            if (($category['role'] ?? '') === 'locked') {
                $total += $saved !== null && $saved >= 1 ? $saved : max(0, (int) ($category['billMinor'] ?? 0));
                continue;
            }
            if ($saved !== null && $saved >= 1) {
                $total += $saved;
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $sheet
     *  @return array<string, mixed>
     */
    private function group(array $sheet, string $name): array
    {
        foreach ($sheet['groups'] as $group) {
            if ($group['group'] === $name) {
                return $group;
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @param array<string, mixed> $sheet */
    private function sourceTotal(array $sheet): int
    {
        $total = 0;
        foreach ($sheet['sources']['income'] as $row) {
            $total += (int) $row['amountMinor'];
        }
        foreach ($sheet['sources']['accounts'] as $row) {
            $total += (int) $row['amountMinor'];
        }
        foreach ($sheet['sources']['floats'] ?? [] as $row) {
            $total -= (int) $row['uncoveredMinor'];
        }

        return $total;
    }

    private function amount(array $sheet, string $name): int
    {
        return (int) $this->category($sheet, $name)['amountMinor'];
    }

    private function billMinor(array $sheet, string $name): int
    {
        return (int) $this->category($sheet, $name)['billMinor'];
    }

    private function targetAmount(array $sheet, string $name): int
    {
        $target = $this->category($sheet, $name)['target'];
        self::assertIsArray($target);

        return (int) $target['amountMinor'];
    }

    /** @param array<string, mixed> $sheet
     *  @return array<string, mixed>
     */
    private function category(array $sheet, string $name): array
    {
        foreach ($sheet['categories'] as $category) {
            if ($category['name'] === $name) {
                return $category;
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @param array<string, mixed> $sheet */
    private function categoryId(array $sheet, string $name): int
    {
        foreach ($sheet['categories'] as $category) {
            if ($category['name'] === $name) {
                return (int) $category['id'];
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @param array<string, mixed> $sheet */
    private function payCycleId(array $sheet, string $name): int
    {
        foreach ($sheet['payors'] as $payor) {
            if ($payor['payor'] === $name) {
                return (int) $payor['id'];
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @param array<string, mixed> $sheet */
    private function payor(array $sheet): string
    {
        foreach ($sheet['payors'] as $payor) {
            if ($payor['id'] === $sheet['payCycleId']) {
                return (string) $payor['payor'];
            }
        }
        self::fail('The selected payor is missing.');
    }

    /** @param array<string, mixed> $sheet */
    private function period(array $sheet, string $start): int
    {
        foreach ($sheet['closed'] as $period) {
            if ($period['start'] === $start) {
                return (int) $period['id'];
            }
        }
        self::fail($start . ' is missing.');
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function namedAccount(string $name): array
    {
        foreach ($this->accounts->list(true) as $account) {
            if ($account['name'] === $name) {
                return $account;
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @param array<string, mixed> $account
     *  @return array<string, mixed>
     */
    private function accountInput(array $account, bool $inBudget): array
    {
        return [
            'name' => $account['name'],
            'type' => $account['type'],
            'side' => $account['side'],
            'balanceMinor' => $account['balanceMinor'],
            'interestBps' => $account['interestBps'],
            'inBudget' => $inBudget,
        ];
    }

    /** @return array<string, mixed> */
    private function namedCategory(string $name): array
    {
        foreach ($this->categories->list(true) as $category) {
            if ($category['name'] === $name) {
                return $category;
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @return array<string, mixed> */
    private function bill(string $name): array
    {
        foreach ($this->bills->list() as $bill) {
            if ($bill['name'] === $name) {
                return $bill;
            }
        }
        self::fail($name . ' is missing.');
    }

    private function accountId(string $name): int
    {
        foreach ($this->accounts->list(true) as $account) {
            if ($account['name'] === $name) {
                return (int) $account['id'];
            }
        }
        self::fail($name . ' is missing.');
    }

    /** @return list<array<string, mixed>> */
    private function storedAssignments(): array
    {
        $rows = $this->pdo->query(
            'SELECT p.period_start, c.name, a.amount_minor, a.filled_minor
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             JOIN categories c ON c.id = a.category_id
             ORDER BY p.period_start, c.name'
        )->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function testAPaydayOutsideTheSpanAndAPostedPaydayStayOutOfThePool(): void
    {
        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-15', '1 month', $_SESSION['user_id']]);
        $pay = new PayCycleService($this->pdo, new Actor($this->pdo));
        $cycle = $pay->create([
            'payor' => 'Later check',
            'payday' => '2026-11-16',
            'length' => 'monthly',
            'payKind' => 'fixed',
            'rateMinor' => 25000,
        ], '2026-11-06');
        $outside = $this->envelopes->show('2026-11-06');
        self::assertNotContains('Later check', array_column($outside['sources']['income'], 'payor'));

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-11-01', $_SESSION['user_id']]);
        $inside = $this->envelopes->show('2026-11-06');
        self::assertContains('Later check', array_column($inside['sources']['income'], 'payor'));

        $pay->savePayroll((int) $cycle['id'], [
            'entries' => [[
                'payday' => '2026-11-16',
                'amountMinor' => 25000,
                'posted' => true,
            ]],
        ], '2026-11-06');
        $fresh = new EnvelopeService(
            $this->pdo,
            new Actor($this->pdo),
            new CurrencyCatalog(),
            new TargetService($this->pdo, new Actor($this->pdo)),
            new PayCycleService($this->pdo, new Actor($this->pdo)),
            new Drive(false),
        );
        $posted = $fresh->show('2026-11-06');
        self::assertNotContains('Later check', array_column($posted['sources']['income'], 'payor'));
    }

    public function testEachPaydayStillComingInIsItsOwnDatedLine(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $camp = array_values(array_filter(
            $sheet['sources']['income'],
            static fn (array $row): bool => $row['payor'] === 'Camp host',
        ));
        self::assertGreaterThan(1, count($camp));
        $dates = array_column($camp, 'on');
        self::assertSame($dates, array_values(array_unique($dates)));
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates);
        foreach ($camp as $row) {
            self::assertGreaterThanOrEqual('2026-11-03', $row['on']);
            self::assertLessThanOrEqual('2026-11-30', $row['on']);
            self::assertGreaterThan(0, $row['amountMinor']);
        }
    }

    public function testAClosedAccountingPeriodShowsTheSameEnvelope(): void
    {
        $open = $this->envelopes->show('2026-11-07', null, null, false, true);
        $openEnvelope = $this->amount($open, 'Road cushion') - $this->billMinor($open, 'Road cushion');
        self::assertGreaterThan(0, $openEnvelope);

        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-01', '1 month', $_SESSION['user_id']]);

        $closed = $this->envelopes->show('2026-11-07', null, null, false, true);

        self::assertSame('closed', $closed['acctPeriod']['phase']);
        self::assertSame('2026-10-01', $closed['period']['start']);
        self::assertSame($openEnvelope, $this->amount($closed, 'Road cushion') - $this->billMinor($closed, 'Road cushion'));
        self::assertNotSame([], $open['sources']['income']);
        self::assertSame([], $closed['sources']['income']);
        self::assertNotSame($open['poolMinor'], $closed['poolMinor']);
        self::assertNotSame($open['assignedMinor'], $closed['assignedMinor']);
        self::assertSame($closed['poolMinor'] - $closed['assignedMinor'], $closed['availableMinor']);
        self::assertNotSame($open['availableMinor'], $closed['availableMinor']);
    }

    public function testALaterPeriodsBillsDoNotRaiseThisPeriodsEnvelope(): void
    {
        $this->pdo->prepare(
            "UPDATE pay_cycles SET archived_at = ? WHERE user_id = ? AND payor != 'Remote salary'"
        )->execute([gmdate('c'), $_SESSION['user_id']]);
        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-16', '1 month', $_SESSION['user_id']]);
        (new BillFill($this->pdo, new CurrencyCatalog(), new Actor($this->pdo), new Drive(false)))->apply('2026-11-07');

        $earlier = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame('2026-10-16', $earlier['acctPeriod']['start']);
        self::assertSame('2026-11-15', $earlier['acctPeriod']['end']);
        self::assertSame(0, $this->billMinor($earlier, 'Healthcare'));
        self::assertSame($this->billMinor($earlier, 'Healthcare'), $this->amount($earlier, 'Healthcare'));
        $road = $this->amount($earlier, 'Road cushion') - $this->billMinor($earlier, 'Road cushion');
        self::assertGreaterThan(0, $road);
        self::assertSame($road, $this->group($earlier, 'needs')['envelopeMinor']);
        foreach ($earlier['categories'] as $category) {
            if (($category['role'] ?? '') !== 'locked') {
                continue;
            }
            $bills = (int) ($category['billMinor'] ?? 0);
            self::assertSame($bills, (int) $category['amountMinor'], (string) $category['name']);
        }

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-11-16', $_SESSION['user_id']]);
        $later = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame('2026-11-16', $later['acctPeriod']['start']);
        self::assertGreaterThan(0, $this->billMinor($later, 'Healthcare'));
        self::assertSame($this->billMinor($later, 'Healthcare'), $this->amount($later, 'Healthcare'));
        self::assertSame($road, $this->amount($later, 'Road cushion') - $this->billMinor($later, 'Road cushion'));
        self::assertSame($road, $this->group($later, 'needs')['envelopeMinor']);

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-10-16', $_SESSION['user_id']]);
        $amounts = [];
        foreach ($later['categories'] as $category) {
            if ($category['archivedAt'] !== null) {
                continue;
            }
            $bills = (int) ($category['billMinor'] ?? 0);
            $envelope = max(0, (int) $category['amountMinor'] - $bills);
            $amounts[] = ['categoryId' => (int) $category['id'], 'amountMinor' => $bills + $envelope];
        }
        $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $later['payCycleId'],
            'amounts' => $amounts,
            'upcoming' => true,
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-11-16',
        ]);
        $back = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame(0, $this->billMinor($back, 'Healthcare'));
        self::assertSame(0, $this->amount($back, 'Healthcare'));
        self::assertSame($road, $this->amount($back, 'Road cushion') - $this->billMinor($back, 'Road cushion'));
        self::assertSame($road, $this->group($back, 'needs')['envelopeMinor']);
    }

    public function testRecalculateNamesAndUsesTheAccountingPeriod(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-16', '1 month', $userId]);
        $pets = (int) $this->pdo->query(
            "SELECT id FROM categories WHERE user_id = {$userId} AND name = 'Pets'"
        )->fetchColumn();
        $fun = (int) $this->pdo->query(
            "SELECT id FROM categories WHERE user_id = {$userId} AND name = 'Fun money'"
        )->fetchColumn();
        $checking = $this->accountId('Everyday checking');
        foreach ([
            ['Before the period', '2026-10-01', 2222],
            ['Inside October', '2026-10-20', 1111],
            ['Inside November', '2026-11-10', 4444],
            ['After the period', '2026-11-20', 3333],
        ] as [$name, $due, $amount]) {
            $this->bills->create([
                'name' => $name,
                'type' => 'pets',
                'accountId' => $checking,
                'categoryId' => $pets,
                'amountMinor' => $amount,
                'currency' => 'USD',
                'recurrence' => 'once',
                'nextDueDate' => $due,
                'today' => '2026-11-07',
            ]);
        }

        $camp = $this->pdo->prepare('SELECT id FROM pay_cycles WHERE user_id = ? AND payor = ?');
        $camp->execute([$userId, 'Camp host']);
        $campId = (int) $camp->fetchColumn();
        $insertPeriod = $this->pdo->prepare(
            'INSERT INTO pay_periods (user_id, pay_cycle_id, period_start, period_end, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $insertPeriod->execute([$userId, $campId, '2026-10-26', '2026-11-01', gmdate('c')]);
        $insertPeriod->execute([$userId, $campId, '2026-11-22', '2026-11-28', gmdate('c')]);
        $assign = $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor)
             SELECT id, ?, ?, 0 FROM pay_periods WHERE pay_cycle_id = ? AND period_start = ?'
        );
        $assign->execute([$fun, 7777, $campId, '2026-10-26']);
        $assign->execute([$fun, 8888, $campId, '2026-11-22']);

        $stored = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.amount_minor), 0)
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             JOIN pay_cycles y ON y.id = p.pay_cycle_id
             WHERE y.payor = ? AND p.period_start = ? AND a.category_id = ?'
        );
        $goal = $this->pdo->prepare(
            'SELECT a.amount_minor FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.pay_cycle_id = ? AND p.period_start = ? AND a.category_id = ?'
        );
        $stored->execute(['Remote salary', '2026-11-01', $pets]);
        $petsBefore = (int) $stored->fetchColumn();
        $goal->execute([$campId, '2026-10-26', $fun]);
        $insideBefore = (int) $goal->fetchColumn();
        $goal->execute([$campId, '2026-11-22', $fun]);
        $outsideBefore = (int) $goal->fetchColumn();

        $remote = $this->pdo->prepare('SELECT id FROM pay_cycles WHERE user_id = ? AND payor = ?');
        $remote->execute([$userId, 'Remote salary']);
        $again = $this->envelopes->recalculate([
            'today' => '2026-11-07',
            'payCycleId' => (int) $remote->fetchColumn(),
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-10-16',
        ]);
        $stored->execute(['Remote salary', '2026-11-01', $pets]);
        $goal->execute([$campId, '2026-10-26', $fun]);
        $inside = (int) $goal->fetchColumn();
        $goal->execute([$campId, '2026-11-22', $fun]);
        $outside = (int) $goal->fetchColumn();

        self::assertSame($petsBefore, (int) $stored->fetchColumn());
        self::assertSame([$insideBefore, $outsideBefore, 'Oct 16 – Nov 15'], [$inside, $outside, $again['acctPeriod']['label'] ?? '']);
        $names = array_column($this->category($again, 'Pets')['bills'], 'name');
        self::assertContains('Inside October', $names);
        self::assertContains('Inside November', $names);
        self::assertNotContains('After the period', $names);
    }

    public function testAGoalEnvelopeTypedInThisPeriodStaysWhenTheSelectedPaydayHasNotStarted(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-16', '1 month', $userId]);
        $trailer = $this->categories->create([
            'name' => 'New Trailer',
            'group' => 'savings',
            'target' => ['kind' => 'balance', 'amountMinor' => 5000000],
        ], '2026-11-07');
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO pay_cycles (
                user_id, payor, payday, length, pay_kind, rate_minor, hours_mode, average_on, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, 'Future salary', '2026-11-16', 'monthly', 'fixed', 100000, 'typed', 0, $now, $now]);
        $cycleId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO pay_periods (user_id, pay_cycle_id, period_start, period_end, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $cycleId, '2026-11-16', '2026-12-15', $now]);
        $periodId = (int) $this->pdo->lastInsertId();
        $before = $this->envelopes->show('2026-11-07', null, null, false, true);
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor) VALUES (?, ?, ?, ?)'
        )->execute([$periodId, $this->categoryId($before, 'Rent'), 900000, 900000]);

        $sheet = $this->envelopes->show('2026-11-07', $cycleId, null, false, true);
        self::assertSame('balance', $this->category($sheet, 'New Trailer')['target']['kind']);
        self::assertSame(0, $this->amount($sheet, 'New Trailer'));
        $road = $this->amount($sheet, 'Road cushion');
        $amounts = [];
        foreach ($sheet['categories'] as $category) {
            if ($category['archivedAt'] !== null) {
                continue;
            }
            $amount = (int) $category['amountMinor'];
            if ($category['name'] === 'New Trailer') {
                $amount = 4000;
            }
            $amounts[] = ['categoryId' => (int) $category['id'], 'amountMinor' => $amount];
        }

        $saved = $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $cycleId,
            'amounts' => $amounts,
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-10-16',
        ]);

        self::assertSame(4000, $this->amount($saved, 'New Trailer'));
        self::assertSame(0, $this->billMinor($saved, 'New Trailer'));
        self::assertSame($road, $this->amount($saved, 'Road cushion'));
        self::assertSame($sheet['availableMinor'] - 4000, $saved['availableMinor']);
        $home = $this->pdo->prepare('SELECT envelope_minor FROM categories WHERE user_id = ? AND id = ?');
        $home->execute([$userId, (int) $trailer['id']]);
        self::assertSame(4000, (int) $home->fetchColumn());

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-11-16', $userId]);
        $later = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame(4000, $this->amount($later, 'New Trailer') - $this->billMinor($later, 'New Trailer'));
    }

    public function testAnEnvelopeTypedOnOnePeriodIsTheEnvelopeOnEveryPeriod(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE user SET envelope_adopted = 1, acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-16', '1 month', $userId]);
        $this->pdo->prepare('UPDATE categories SET envelope_minor = 0 WHERE user_id = ?')->execute([$userId]);

        $earlier = $this->envelopes->show('2026-11-07', null, null, false, true);
        $healthcareBills = $this->billMinor($earlier, 'Healthcare');
        $rentBills = $this->billMinor($earlier, 'Rent');
        self::assertSame(0, $healthcareBills);
        self::assertSame('locked', $this->category($earlier, 'Rent')['role']);
        self::assertSame('locked', $this->category($earlier, 'Healthcare')['role']);
        self::assertNull($this->category($earlier, 'Fun money')['target']);
        self::assertNotNull($this->category($earlier, 'Tires')['target']);
        $before = $earlier['availableMinor'];
        $amounts = $this->totalsWithEnvelopes($earlier, [
            'Healthcare' => 4200,
            'Rent' => 1500,
            'Fun money' => 800,
            'Tires' => 600,
        ]);

        $saved = $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $earlier['payCycleId'],
            'amounts' => $amounts,
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-10-16',
        ]);
        self::assertSame(4200, $this->amount($saved, 'Healthcare') - $this->billMinor($saved, 'Healthcare'));
        self::assertSame(1500, $this->amount($saved, 'Rent') - $this->billMinor($saved, 'Rent'));
        self::assertSame(800, $this->amount($saved, 'Fun money') - $this->billMinor($saved, 'Fun money'));
        self::assertSame(600, $this->amount($saved, 'Tires') - $this->billMinor($saved, 'Tires'));
        self::assertSame($healthcareBills, $this->billMinor($saved, 'Healthcare'));
        self::assertSame($rentBills, $this->billMinor($saved, 'Rent'));
        self::assertSame($before - 4200 - 1500 - 800 - 600, $saved['availableMinor']);

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-11-16', $userId]);
        $later = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame('future', $later['acctPeriod']['phase']);
        self::assertGreaterThan(0, $this->billMinor($later, 'Healthcare'));
        self::assertNotSame($healthcareBills, $this->billMinor($later, 'Healthcare'));
        $photo = array_values(array_filter(
            $later['sources']['income'],
            static fn (array $row): bool => $row['payor'] === 'Photo gigs',
        ));
        self::assertSame(['2026-12-15'], array_column($photo, 'on'));
        self::assertSame(45000, $photo[0]['amountMinor']);
        self::assertNotContains('Photo gigs', array_column($saved['sources']['income'], 'payor'));
        foreach ($later['sources']['income'] as $row) {
            self::assertGreaterThanOrEqual('2026-11-16', $row['on']);
            self::assertLessThanOrEqual('2026-12-15', $row['on']);
            self::assertGreaterThanOrEqual('2026-11-07', $row['on']);
        }
        self::assertGreaterThan($saved['poolMinor'], $later['poolMinor']);
        self::assertGreaterThan($saved['assignedMinor'], $later['assignedMinor']);
        self::assertSame($later['poolMinor'] - $later['assignedMinor'], $later['availableMinor']);
        self::assertNotSame($saved['availableMinor'], $later['availableMinor']);
        self::assertSame(4200, $this->amount($later, 'Healthcare') - $this->billMinor($later, 'Healthcare'));
        self::assertSame(1500, $this->amount($later, 'Rent') - $this->billMinor($later, 'Rent'));
        self::assertSame(800, $this->amount($later, 'Fun money') - $this->billMinor($later, 'Fun money'));
        self::assertSame(600, $this->amount($later, 'Tires') - $this->billMinor($later, 'Tires'));

        $changed = $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $later['payCycleId'],
            'amounts' => $this->totalsWithEnvelopes($later, [
                'Healthcare' => 900,
                'Rent' => 1500,
                'Fun money' => 250,
                'Tires' => 600,
            ]),
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-11-16',
        ]);
        self::assertSame(900, $this->amount($changed, 'Healthcare') - $this->billMinor($changed, 'Healthcare'));
        self::assertSame($this->billMinor($later, 'Healthcare'), $this->billMinor($changed, 'Healthcare'));
        self::assertSame($later['poolMinor'], $changed['poolMinor']);
        self::assertSame($later['availableMinor'] + (4200 - 900) + (800 - 250), $changed['availableMinor']);
        self::assertContains('Photo gigs', array_column($changed['sources']['income'], 'payor'));

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')
            ->execute(['2026-10-16', $userId]);
        $back = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertSame(0, $this->billMinor($back, 'Healthcare'));
        self::assertSame(900, $this->amount($back, 'Healthcare') - $this->billMinor($back, 'Healthcare'));
        self::assertSame(1500, $this->amount($back, 'Rent') - $this->billMinor($back, 'Rent'));
        self::assertSame(250, $this->amount($back, 'Fun money') - $this->billMinor($back, 'Fun money'));
        self::assertSame(600, $this->amount($back, 'Tires') - $this->billMinor($back, 'Tires'));
        self::assertSame($before - 900 - 1500 - 250 - 600, $back['availableMinor']);
    }

    public function testRecalculateRefreshesBillAssignmentsAndKeepsTheEnvelope(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE user SET envelope_adopted = 1, acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-16', '1 month', $userId]);
        $this->pdo->prepare('UPDATE categories SET envelope_minor = 0 WHERE user_id = ?')->execute([$userId]);

        $sheet = $this->envelopes->show('2026-11-07', null, null, false, true);
        $saved = $this->envelopes->save([
            'today' => '2026-11-07',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => $this->totalsWithEnvelopes($sheet, [
                'Healthcare' => 4200,
                'Rent' => 1500,
                'Fun money' => 800,
            ]),
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-10-16',
        ]);
        $healthcareBills = $this->billMinor($saved, 'Healthcare');
        $rentBills = $this->billMinor($saved, 'Rent');

        $again = $this->envelopes->recalculate([
            'today' => '2026-11-07',
            'payCycleId' => $saved['payCycleId'],
            'acctPeriod' => '1',
            'acctPeriodStart' => '2026-10-16',
        ]);

        self::assertSame(4200, $this->amount($again, 'Healthcare') - $this->billMinor($again, 'Healthcare'));
        self::assertSame(1500, $this->amount($again, 'Rent') - $this->billMinor($again, 'Rent'));
        self::assertSame(800, $this->amount($again, 'Fun money') - $this->billMinor($again, 'Fun money'));
        self::assertSame($healthcareBills, $this->billMinor($again, 'Healthcare'));
        self::assertSame($rentBills, $this->billMinor($again, 'Rent'));
        $stored = $this->pdo->prepare('SELECT envelope_minor FROM categories WHERE user_id = ? AND name = ?');
        $stored->execute([$userId, 'Healthcare']);
        self::assertSame(4200, (int) $stored->fetchColumn());
        $stored->execute([$userId, 'Fun money']);
        self::assertSame(800, (int) $stored->fetchColumn());

        $this->pdo->prepare('UPDATE user SET acct_period_start = ? WHERE id = ?')->execute(['2026-11-16', $userId]);
        $later = $this->envelopes->show('2026-11-07', null, null, false, true);
        self::assertGreaterThan($healthcareBills, $this->billMinor($later, 'Healthcare'));
        self::assertSame(4200, $this->amount($later, 'Healthcare') - $this->billMinor($later, 'Healthcare'));
        self::assertSame(800, $this->amount($later, 'Fun money') - $this->billMinor($later, 'Fun money'));
    }

    public function testAPayPeriodThatHasNotStartedDoesNotReduceAvailable(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE id = ?')
            ->execute(['2026-10-16', '1 month', $userId]);
        $before = $this->envelopes->show('2026-11-07', null, null, false, true);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO pay_cycles (
                user_id, payor, payday, length, pay_kind, rate_minor, hours_mode, average_on, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, 'Future salary', '2026-11-16', 'monthly', 'fixed', 100000, 'typed', 0, $now, $now]);
        $cycleId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO pay_periods (user_id, pay_cycle_id, period_start, period_end, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $cycleId, '2026-11-16', '2026-12-15', $now]);
        $periodId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor) VALUES (?, ?, ?, ?)'
        )->execute([$periodId, $this->categoryId($before, 'Road cushion'), 500000, 0]);
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor) VALUES (?, ?, ?, ?)'
        )->execute([$periodId, $this->categoryId($before, 'Rent'), 800000, 800000]);

        $after = $this->envelopes->show('2026-11-07', null, null, false, true);

        self::assertSame($before['availableMinor'], $after['availableMinor']);
        self::assertSame($before['assignedMinor'], $after['assignedMinor']);
        self::assertSame('2026-10-16', $after['acctPeriod']['start']);
        self::assertSame('2026-11-15', $after['acctPeriod']['end']);
    }

    /**
     * @param array<string, mixed> $sheet
     * @param array<string, int> $envelopes
     * @return list<array{categoryId: int, amountMinor: int}>
     */
    private function totalsWithEnvelopes(array $sheet, array $envelopes): array
    {
        $amounts = [];
        foreach ($sheet['categories'] as $category) {
            if (!is_array($category) || $category['archivedAt'] !== null) {
                continue;
            }
            $bills = (int) ($category['billMinor'] ?? 0);
            $name = (string) $category['name'];
            $typed = $envelopes[$name] ?? max(0, (int) $category['amountMinor'] - $bills);
            $amounts[] = ['categoryId' => (int) $category['id'], 'amountMinor' => $bills + $typed];
        }

        return $amounts;
    }

    private function balance(string $name): int
    {
        foreach ($this->accounts->list(true) as $account) {
            if ($account['name'] === $name) {
                return (int) $account['balanceMinor'];
            }
        }
        self::fail($name . ' is missing.');
    }

    private function setBalance(string $name, int $minor): void
    {
        $this->pdo->prepare(
            'UPDATE accounts SET balance_minor = ? WHERE user_id = ? AND name = ?'
        )->execute([$minor, $_SESSION['user_id'], $name]);
    }
}
