<?php

declare(strict_types=1);

namespace App\Tests\Envelope;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Category\CategoryService;
use App\Database\Database;
use App\Dashboard\DashboardService;
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
        $this->envelopes = new EnvelopeService($this->pdo, $actor, $catalog, $targets, new PayCycleService($this->pdo, $actor));
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

        self::assertSame('Monthly Payor', $this->payor($sheet));
        self::assertSame('2026-11-01', $sheet['period']['start']);
        self::assertSame('2026-11-30', $sheet['period']['end']);
        self::assertTrue($sheet['period']['editable']);
        self::assertSame(50000, $this->amount($sheet, 'Needs 1'));
        self::assertSame(0, $this->amount($sheet, 'Wants 1'));
        self::assertSame(1379500, $sheet['poolMinor']);
        self::assertGreaterThan(50000, $sheet['assignedMinor']);
        self::assertSame(120000, $this->targetAmount($sheet, 'Needed By Date 1'));
        self::assertSame(50000, $this->targetAmount($sheet, 'Target Balance 1'));
        self::assertNull($this->category($sheet, 'Needs 1')['target']);
        self::assertSame($this->sourceTotal($sheet), $sheet['availableMinor']);
        self::assertGreaterThan($sheet['poolMinor'], $sheet['availableMinor']);
        self::assertNotSame($sheet['assignedMinor'], $sheet['availableMinor']);
        self::assertSame([], $sheet['leftOut']);
        self::assertSame(60000, $this->amount($sheet, 'Rent'));
        self::assertSame(60000, $this->billMinor($sheet, 'Rent'));
        self::assertSame(21600, $this->billMinor($sheet, 'Groceries'));
        self::assertSame(0, $this->billMinor($sheet, 'Entertainment'));
        self::assertSame(['2026-12-01', '2026-12-31'], [$sheet['nextPeriod']['start'], $sheet['nextPeriod']['end']]);
        self::assertFalse($sheet['celebrate']);

        $closed = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame('2026-10-01', $closed['period']['start']);
        self::assertFalse($closed['period']['editable']);
        self::assertSame(20000, $this->amount($closed, 'Needs 1'));
        self::assertSame(50000, $this->namedCategory('Needs 1')['amountMinor']);

        $checking = $this->balance('Checking 1');
        $cash = $this->balance('Cash 1');
        $this->setBalance('Checking 1', $checking - 1000);
        $this->setBalance('Cash 1', $cash + 1000);
        $moved = $this->envelopes->show('2026-11-03');
        self::assertSame($sheet['availableMinor'], $moved['availableMinor']);

        $card = $this->balance('Card 1');
        $this->setBalance('Card 1', $card + 50000);
        $untouched = $this->envelopes->show('2026-11-03');
        self::assertSame($sheet['availableMinor'], $untouched['availableMinor']);

        $bill = $this->bill('One-Time Bill');
        self::assertSame('Needs 1', $bill['categoryName']);
        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-11-03']);
        self::assertSame('paid', $paid['status']);
        self::assertSame('Needs 1', $paid['categoryName']);
        self::assertSame($checking - 1000, $this->balance('Checking 1'));
        self::assertSame(50000, $this->namedCategory('Needs 1')['amountMinor']);

        $_SESSION['user_id'] = $this->ownerId;
        $owner = $this->envelopes->show('2026-11-03');
        self::assertSame([], $owner['payors']);
        self::assertSame([], $owner['leftOut']);
        self::assertSame(0, $owner['assignedMinor']);
    }

    public function testAvailableSubtractsMaximumsNotAssignments(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Needs 1');
        $wants = $this->categoryId($sheet, 'Wants 1');
        self::assertSame(50000, $this->amount($sheet, 'Needs 1'));
        self::assertNull($this->category($sheet, 'Needs 1')['target']);
        self::assertSame($this->sourceTotal($sheet), $sheet['availableMinor']);

        $raised = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 50000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => 80000]],
        ]);
        self::assertSame(50000, $this->amount($raised, 'Needs 1'));
        self::assertSame(80000, $this->targetAmount($raised, 'Needs 1'));
        self::assertSame($sheet['assignedMinor'], $raised['assignedMinor']);
        self::assertSame($sheet['availableMinor'], $raised['availableMinor']);

        $assigned = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 90000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => 80000]],
        ]);
        self::assertSame(90000, $this->amount($assigned, 'Needs 1'));
        self::assertSame($raised['availableMinor'], $assigned['availableMinor']);
        self::assertNotSame($raised['assignedMinor'], $assigned['assignedMinor']);

        $empty = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $needs, 'amountMinor' => 90000],
                ['categoryId' => $wants, 'amountMinor' => 0],
            ],
            'maximums' => [['categoryId' => $wants, 'amountMinor' => 4000]],
        ]);
        self::assertSame(0, $this->amount($empty, 'Wants 1'));
        self::assertSame($assigned['availableMinor'], $empty['availableMinor']);

        $weekly = $this->payCycleId($sheet, 'Weekly Payor');
        $week = $this->envelopes->show('2026-11-03', $weekly);
        $again = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $weekly,
            'amounts' => [['categoryId' => $wants, 'amountMinor' => 2500]],
        ]);
        self::assertSame(2500, $this->amount($again, 'Wants 1'));
        self::assertSame($week['availableMinor'], $again['availableMinor']);

        $monthly = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        self::assertSame($empty['availableMinor'], $monthly['availableMinor']);
        self::assertSame(90000, $this->amount($monthly, 'Needs 1'));
        self::assertSame($again['availableMinor'], $monthly['availableMinor']);

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
        self::assertSame($monthly['availableMinor'], $held['availableMinor']);

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
        self::assertNull($this->category($cleared, 'Needs 1')['target']);
        self::assertSame($held['availableMinor'], $cleared['availableMinor']);
    }

    public function testALockedBucketAssignsTheBillsAndKeepsTheMaximumOpen(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $rent = $this->categoryId($sheet, 'Rent');
        $needs = $this->categoryId($sheet, 'Needs 1');
        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [
                ['categoryId' => $needs, 'amountMinor' => 50000],
                ['categoryId' => $rent, 'amountMinor' => 12500],
            ],
            'maximums' => [
                ['categoryId' => $needs, 'amountMinor' => 80000],
                ['categoryId' => $rent, 'amountMinor' => 90000],
            ],
        ]);
        self::assertSame(60000, $this->amount($saved, 'Rent'));
        self::assertSame(60000, $this->billMinor($saved, 'Rent'));
        self::assertSame(90000, $this->targetAmount($saved, 'Rent'));
        self::assertSame(80000, $this->targetAmount($saved, 'Needs 1'));
        self::assertSame($sheet['availableMinor'], $saved['availableMinor']);
    }

    public function testAnAssignmentStaysOnTheOpenPeriod(): void
    {
        $before = $this->balance('Checking 1');
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Needs 1');

        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 60000]],
        ]);
        self::assertSame(60000, $this->amount($saved, 'Needs 1'));
        self::assertSame($sheet['availableMinor'], $saved['availableMinor']);
        self::assertSame($before, $this->balance('Checking 1'));

        $closed = $this->envelopes->show('2026-11-03', $saved['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame(20000, $this->amount($closed, 'Needs 1'));

        $over = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => $saved['poolMinor'] + 1]],
        ]);
        self::assertSame($saved['poolMinor'] + 1, $this->amount($over, 'Needs 1'));
        self::assertSame($saved['availableMinor'], $over['availableMinor']);

        $capped = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 50000]],
            'maximums' => [['categoryId' => $needs, 'amountMinor' => $saved['poolMinor'] + 1]],
        ]);
        self::assertSame($saved['availableMinor'], $capped['availableMinor']);
        self::assertGreaterThanOrEqual(0, $capped['availableMinor']);

        $cleared = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 0]],
        ]);
        self::assertSame(0, $this->amount($cleared, 'Needs 1'));
        self::assertSame(0, $this->namedCategory('Needs 1')['amountMinor']);
        $archived = $this->categories->archive($needs);
        self::assertNotNull($archived['archivedAt']);
    }

    public function testTheNextPeriodCarriesTheOpenAmount(): void
    {
        $opened = $this->envelopes->show('2026-12-01');
        self::assertSame('2026-12-01', $opened['period']['start']);
        self::assertSame('2026-12-31', $opened['period']['end']);
        self::assertSame(50000, $this->amount($opened, 'Needs 1'));
        self::assertSame(0, $this->amount($opened, 'Wants 1'));
        self::assertGreaterThan(50000, $opened['assignedMinor']);
        self::assertSame($this->sourceTotal($opened), $opened['availableMinor']);
        self::assertGreaterThan($opened['poolMinor'], $opened['availableMinor']);
        self::assertSame(50000, $this->namedCategory('Needs 1')['amountMinor']);

        $november = $this->period($opened, '2026-11-01');
        $october = $this->period($opened, '2026-10-01');
        $readNovember = $this->envelopes->show('2026-12-01', $opened['payCycleId'], $november);
        $readOctober = $this->envelopes->show('2026-12-01', $opened['payCycleId'], $october);
        self::assertSame(50000, $this->amount($readNovember, 'Needs 1'));
        self::assertSame(20000, $this->amount($readOctober, 'Needs 1'));
        self::assertSame(0, $this->amount($readOctober, 'Wants 1'));
        self::assertFalse($readNovember['period']['editable']);

        $needs = $this->categoryId($opened, 'Needs 1');
        $edited = $this->envelopes->save([
            'today' => '2026-12-01',
            'payCycleId' => $opened['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 1000]],
        ]);
        self::assertSame(1000, $this->amount($edited, 'Needs 1'));

        $back = $this->envelopes->show('2026-11-03');
        self::assertSame('2026-11-01', $back['period']['start']);
        self::assertSame(50000, $this->amount($back, 'Needs 1'));
        self::assertSame(50000, $this->namedCategory('Needs 1')['amountMinor']);

        $again = $this->envelopes->show('2026-12-01');
        self::assertSame(1000, $this->amount($again, 'Needs 1'));
        self::assertSame(20000, $this->amount(
            $this->envelopes->show('2026-12-01', $again['payCycleId'], $october),
            'Needs 1',
        ));
    }

    public function testAnAssignmentCannotBeNegative(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Needs 1');
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
        self::assertSame(50000, $this->amount($kept, 'Needs 1'));
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

        $first = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $first['availableMinor']);
        self::assertTrue($first['celebrate']);

        $again = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $again['availableMinor']);
        self::assertFalse($again['celebrate']);

        $this->setBalance('Checking 1', 100);
        $moved = $this->envelopes->show('2026-11-03');
        self::assertSame(100, $moved['availableMinor']);
        self::assertFalse($moved['celebrate']);

        $this->setBalance('Checking 1', 0);
        $return = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $return['availableMinor']);
        self::assertFalse($return['celebrate']);
    }

    public function testTheMonthIncludesEveryPayorOnceAndSplitsTheAvailableTotal(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $rent = $this->billMinor($sheet, 'Rent');
        $weekly = $this->payCycleId($sheet, 'Weekly Payor');
        self::assertGreaterThan(1, count($sheet['payors']));
        self::assertGreaterThan(0, $rent);

        $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $weekly,
            'amounts' => [['categoryId' => $this->categoryId($sheet, 'Wants 1'), 'amountMinor' => 3333]],
        ]);
        $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $this->categoryId($sheet, 'Needs 1'), 'amountMinor' => 50000]],
        ]);

        $month = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        $week = $this->envelopes->show('2026-11-03', $weekly);
        self::assertSame(50000, $this->amount($month, 'Needs 1'));
        self::assertSame(3333, $this->amount($month, 'Wants 1'));
        self::assertSame(50000, $this->amount($week, 'Needs 1'));
        self::assertSame(3333, $this->amount($week, 'Wants 1'));
        self::assertSame($rent, $this->billMinor($month, 'Rent'));
        self::assertSame($rent, $this->billMinor($week, 'Rent'));
        self::assertNotSame($rent * count($month['payors']), $this->billMinor($month, 'Rent'));
        self::assertSame($month['availableMinor'], $week['availableMinor']);
        self::assertSame($this->sourceTotal($month), $month['availableMinor']);
        $this->assertSplit($month, $month['availableMinor']);
        self::assertNotEmpty($month['sources']['income']);
        self::assertNotEmpty($month['sources']['accounts']);
        $names = array_column($month['sources']['accounts'], 'name');
        self::assertContains('Checking 1', $names);
        self::assertNotContains('Card 1', $names);
        self::assertNotContains('Loan 1', $names);
        self::assertNotContains('Fuel 1', $names);
        foreach ($month['sources']['accounts'] as $account) {
            self::assertContains($account['type'], ['checking', 'savings', 'cash']);
        }
    }

    public function testTheDashboardShowsIncomingPayAndStepsThePeriod(): void
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
        );

        $sheet = $dashboard->show('2026-11-03');

        self::assertSame(3996000, $sheet['incomeMinor']);
        self::assertSame($this->sourceTotal($sheet['envelopes']), $sheet['envelopes']['availableMinor']);
        self::assertGreaterThan($sheet['envelopes']['poolMinor'], $sheet['envelopes']['availableMinor']);
        self::assertGreaterThan(50000, $sheet['envelopes']['assignedMinor']);
        $byPayor = [];
        foreach ($sheet['income'] as $row) {
            $byPayor[$row['payor']] = $row['paycheckMinor'];
        }
        self::assertSame(100000, $byPayor['Weekly Payor']);
        self::assertSame(240000, $byPayor['Biweekly Payor']);
        self::assertSame(250000, $byPayor['Semimonthly Payor']);
        self::assertSame(700000, $byPayor['Monthly Payor']);
        self::assertSame(150000, $byPayor['Irregular Payor']);
        self::assertSame(220000, $byPayor['Weekday Payor']);
        self::assertSame(1936000, $byPayor['Monthly Weekday Payor']);
        self::assertSame(400000, $byPayor['Averaged Payor']);
        self::assertCount(8, $byPayor);
        self::assertSame('2026-10-01', $sheet['envelopes']['previousPeriod']['start']);
        self::assertSame('2026-12-01', $sheet['envelopes']['stepNext']['start']);
        self::assertTrue($sheet['envelopes']['stepNext']['upcoming']);

        $closed = $dashboard->show(
            '2026-11-03',
            $sheet['envelopes']['payCycleId'],
            $sheet['envelopes']['previousPeriod']['id'],
        );
        self::assertSame('2026-10-01', $closed['envelopes']['period']['start']);
        self::assertFalse($closed['envelopes']['period']['editable']);
        self::assertSame(20000, $this->amount($closed['envelopes'], 'Needs 1'));
        self::assertSame($sheet['envelopes']['availableMinor'], $closed['envelopes']['availableMinor']);
        self::assertSame(3996000, $closed['incomeMinor']);

        $upcoming = $dashboard->show('2026-11-03', $sheet['envelopes']['payCycleId'], null, true);
        self::assertTrue($upcoming['envelopes']['period']['upcoming']);
        self::assertTrue($upcoming['envelopes']['period']['editable']);
        self::assertSame('2026-12-01', $upcoming['envelopes']['period']['start']);
        self::assertSame(50000, $this->amount($upcoming['envelopes'], 'Needs 1'));
        self::assertSame(0, $this->amount($upcoming['envelopes'], 'Wants 1'));
        self::assertSame($sheet['envelopes']['availableMinor'], $upcoming['envelopes']['availableMinor']);
        self::assertSame(3996000, $upcoming['incomeMinor']);
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
                ['categoryId' => $this->categoryId($sheet, 'Needs 1'), 'amountMinor' => 5000],
                ['categoryId' => $this->categoryId($sheet, 'Wants 1'), 'amountMinor' => 3000],
                ['categoryId' => $this->categoryId($sheet, 'Savings 1'), 'amountMinor' => 2000],
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
                ['categoryId' => $this->categoryId($sheet, 'Needs 1'), 'amountMinor' => 5000],
                ['categoryId' => $this->categoryId($sheet, 'Wants 1'), 'amountMinor' => 4000],
                ['categoryId' => $this->categoryId($sheet, 'Savings 1'), 'amountMinor' => 1000],
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
        self::assertSame('Monthly Payor', $this->payor($sheet));
        self::assertSame(700000, $sheet['incomeMinor']);
        $this->assertSplit($sheet, $sheet['availableMinor']);
        foreach (['needs', 'wants', 'savings'] as $group) {
            $heading = $this->group($sheet, $group);
            self::assertSame($this->sumAssigned($sheet, $group), $heading['assignedMinor']);
            self::assertSame($this->sumMaximum($sheet, $group), $heading['maximumMinor']);
            self::assertGreaterThanOrEqual(0, $heading['assignedMinor']);
            self::assertGreaterThanOrEqual(0, $heading['maximumMinor']);
        }
        self::assertGreaterThanOrEqual(50000 + $this->billMinor($sheet, 'Rent'), $this->group($sheet, 'needs')['assignedMinor']);
        self::assertGreaterThanOrEqual(120000 + 50000, $this->group($sheet, 'savings')['maximumMinor']);

        $closed = $this->envelopes->show('2026-11-03', $sheet['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame(700000, $closed['incomeMinor']);
        self::assertSame(intdiv($closed['availableMinor'] * 50 + 50, 100), $this->group($closed, 'needs')['targetMinor']);
        self::assertSame($this->sumAssigned($closed, 'needs'), $this->group($closed, 'needs')['assignedMinor']);
        self::assertNotSame($this->group($sheet, 'needs')['assignedMinor'], $this->group($closed, 'needs')['assignedMinor']);

        $paychecks = [
            'Weekly Payor' => 100000,
            'Biweekly Payor' => 240000,
            'Semimonthly Payor' => 250000,
            'Weekday Payor' => 220000,
            'Irregular Payor' => 150000,
            'Monthly Weekday Payor' => 22 * 8 * 11000,
        ];
        foreach ($paychecks as $payor => $income) {
            $view = $this->envelopes->show('2026-11-03', $this->payCycleId($sheet, $payor));
            self::assertSame($income, $view['incomeMinor'], $payor);
            self::assertSame($sheet['availableMinor'], $view['availableMinor'], $payor);
            $this->assertSplit($view, $view['availableMinor']);
        }

        $weekday = $this->payCycleId($sheet, 'Monthly Weekday Payor');
        $december = $this->envelopes->show('2026-11-03', $weekday, null, true);
        $decemberPay = 21 * 8 * 11000;
        self::assertSame('2026-12-01', $december['period']['start']);
        self::assertSame($decemberPay, $december['incomeMinor']);
        $this->assertSplit($december, $december['availableMinor']);

        $needsBefore = $this->group($sheet, 'needs');
        $wantsBefore = $this->group($sheet, 'wants');
        $rentBills = $this->billMinor($sheet, 'Rent');
        $amounts = [];
        foreach ($sheet['categories'] as $category) {
            if (($category['role'] ?? '') === 'locked') {
                continue;
            }
            $amount = (int) $category['amountMinor'];
            if ($category['name'] === 'Wants 1') {
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
                ['categoryId' => $this->categoryId($sheet, 'Wants 1'), 'amountMinor' => 12300],
            ],
        ]);
        self::assertSame(700000, $saved['incomeMinor']);
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
        self::assertSame(50000, $this->amount($preview, 'Needs 1'));
        self::assertSame($sheet['availableMinor'], $preview['availableMinor']);
        self::assertNull($preview['period']['id']);

        $needs = $this->categoryId($preview, 'Needs 1');
        $wants = $this->categoryId($preview, 'Wants 1');
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
        self::assertSame(1000, $this->amount($planned, 'Needs 1'));
        self::assertSame($sheet['availableMinor'], $planned['availableMinor']);
        self::assertSame(50000, $this->amount($this->envelopes->show('2026-11-03', $sheet['payCycleId']), 'Needs 1'));

        $opened = $this->envelopes->show('2026-12-01', $sheet['payCycleId']);
        self::assertSame('2026-12-01', $opened['period']['start']);
        self::assertFalse($opened['period']['upcoming']);
        self::assertSame(1000, $this->amount($opened, 'Needs 1'));
        self::assertSame(0, $this->amount($opened, 'Wants 1'));
        self::assertSame($this->sourceTotal($opened), $opened['availableMinor']);
    }

    public function testAnAmountHeldBeforeEnvelopesBecomesTheOpenAssignment(): void
    {
        $this->pdo->prepare(
            'DELETE FROM assignments WHERE pay_period_id IN (SELECT id FROM pay_periods WHERE user_id = ?)'
        )->execute([$_SESSION['user_id']]);
        $this->pdo->prepare(
            'UPDATE categories SET amount_minor = 4200 WHERE user_id = ? AND name = ?'
        )->execute([$_SESSION['user_id'], 'Needs 1']);

        $sheet = $this->envelopes->show('2026-11-03');

        self::assertSame('Averaged Payor', $this->payor($sheet));
        self::assertSame(4200, $this->amount($sheet, 'Needs 1'));
        self::assertSame(4200, $this->namedCategory('Needs 1')['amountMinor']);
        self::assertSame(4200, $this->amount($this->envelopes->show('2026-11-03', $this->payCycleId($sheet, 'Monthly Payor')), 'Needs 1'));
    }

    public function testAnotherOpenPeriodCountsAgainstTheSamePool(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $weekly = null;
        foreach ($sheet['payors'] as $payor) {
            if ($payor['payor'] === 'Weekly Payor') {
                $weekly = $payor['id'];
            }
        }
        self::assertNotNull($weekly);
        $week = $this->envelopes->show('2026-11-03', $weekly);
        self::assertSame('2026-11-01', $week['period']['start']);
        self::assertSame('2026-11-07', $week['period']['end']);
        self::assertSame(0, $this->amount($week, 'Wants 1'));

        $saved = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $weekly,
            'amounts' => [['categoryId' => $this->categoryId($week, 'Wants 1'), 'amountMinor' => 2500]],
        ]);
        self::assertSame(2500, $this->amount($saved, 'Wants 1'));
        self::assertSame($week['availableMinor'], $saved['availableMinor']);
        self::assertSame(2500, $this->namedCategory('Wants 1')['amountMinor']);

        $monthly = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        self::assertSame(50000, $this->amount($monthly, 'Needs 1'));
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
