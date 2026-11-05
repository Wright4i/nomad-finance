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
        $this->envelopes = new EnvelopeService($this->pdo, $actor, $catalog, $targets);
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
        self::assertSame(-15000, $this->amount($sheet, 'Wants 1'));
        self::assertSame('cover', $this->namedCategory('Needs 1')['overspend']);
        self::assertSame('carry', $this->namedCategory('Wants 1')['overspend']);
        self::assertSame(1379500, $sheet['poolMinor']);
        self::assertSame(50000, $sheet['assignedMinor']);
        self::assertSame(1329500, $sheet['availableMinor']);
        self::assertSame([], $sheet['leftOut']);
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
        self::assertSame(1329500, $moved['availableMinor']);

        $card = $this->balance('Card 1');
        $this->setBalance('Card 1', $card + 50000);
        $untouched = $this->envelopes->show('2026-11-03');
        self::assertSame(1329500, $untouched['availableMinor']);

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
        self::assertSame(1319500, $saved['availableMinor']);
        self::assertSame($before, $this->balance('Checking 1'));

        $closed = $this->envelopes->show('2026-11-03', $saved['payCycleId'], $sheet['closed'][0]['id']);
        self::assertSame(20000, $this->amount($closed, 'Needs 1'));

        $over = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => $saved['poolMinor'] + 1]],
        ]);
        self::assertSame(-1, $over['availableMinor']);

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
        self::assertSame(-15000, $this->amount($opened, 'Wants 1'));
        self::assertSame(50000, $opened['assignedMinor']);
        self::assertSame($opened['poolMinor'] - 50000, $opened['availableMinor']);
        self::assertSame(50000, $this->namedCategory('Needs 1')['amountMinor']);

        $november = $this->period($opened, '2026-11-01');
        $october = $this->period($opened, '2026-10-01');
        $readNovember = $this->envelopes->show('2026-12-01', $opened['payCycleId'], $november);
        $readOctober = $this->envelopes->show('2026-12-01', $opened['payCycleId'], $october);
        self::assertSame(50000, $this->amount($readNovember, 'Needs 1'));
        self::assertSame(20000, $this->amount($readOctober, 'Needs 1'));
        self::assertSame(-15000, $this->amount($readOctober, 'Wants 1'));
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

    public function testCoverNowPullsANegativeAndLeavesItBehind(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Needs 1');
        $covered = $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => -10000]],
        ]);
        self::assertSame(-10000, $this->amount($covered, 'Needs 1'));
        self::assertSame(10000, $covered['assignedMinor']);
        self::assertSame($covered['poolMinor'] - 10000, $covered['availableMinor']);

        $next = $this->envelopes->show('2026-12-01', $sheet['payCycleId']);
        self::assertSame(0, $this->amount($next, 'Needs 1'));
        self::assertSame(0, $next['assignedMinor']);
        self::assertSame($next['poolMinor'], $next['availableMinor']);

        $november = $this->period($next, '2026-11-01');
        $closed = $this->envelopes->show('2026-12-01', $next['payCycleId'], $november);
        self::assertSame('2026-11-01', $closed['period']['start']);
        self::assertSame(-10000, $this->amount($closed, 'Needs 1'));
    }

    public function testChangingToCoverNowPullsACarriedNegative(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $wants = $this->namedCategory('Wants 1');
        self::assertSame('carry', $wants['overspend']);
        self::assertSame($sheet['poolMinor'] - 50000, $sheet['availableMinor']);

        $covered = $this->categories->update((int) $wants['id'], [
            'name' => 'Wants 1',
            'group' => 'wants',
            'overspend' => 'cover',
        ]);
        self::assertSame('cover', $covered['overspend']);
        $pulled = $this->envelopes->show('2026-11-03');
        self::assertSame(-15000, $this->amount($pulled, 'Wants 1'));
        self::assertSame(65000, $pulled['assignedMinor']);
        self::assertSame($pulled['poolMinor'] - 65000, $pulled['availableMinor']);

        $this->categories->update((int) $wants['id'], [
            'name' => 'Wants 1',
            'group' => 'wants',
            'overspend' => 'carry',
        ]);
        $kept = $this->categories->update((int) $wants['id'], [
            'name' => 'Wants 1',
            'group' => 'wants',
        ]);
        self::assertSame('carry', $kept['overspend']);

        try {
            $this->categories->update((int) $wants['id'], [
                'name' => 'Wants 1',
                'group' => 'wants',
                'overspend' => 'debt',
            ]);
            self::fail('Overspend is cover or carry.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('overspend', $e->fields);
        }
    }

    public function testZeroCelebratesOnceInTheOpenPeriod(): void
    {
        $sheet = $this->envelopes->show('2026-11-03');
        $needs = $this->categoryId($sheet, 'Needs 1');
        $fill = [
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => $sheet['poolMinor']]],
        ];

        $first = $this->envelopes->save($fill);
        self::assertSame(0, $first['availableMinor']);
        self::assertTrue($first['celebrate']);

        $again = $this->envelopes->show('2026-11-03');
        self::assertSame(0, $again['availableMinor']);
        self::assertFalse($again['celebrate']);

        $this->envelopes->save([
            'today' => '2026-11-03',
            'payCycleId' => $sheet['payCycleId'],
            'amounts' => [['categoryId' => $needs, 'amountMinor' => 1]],
        ]);
        $return = $this->envelopes->save($fill);
        self::assertSame(0, $return['availableMinor']);
        self::assertFalse($return['celebrate']);
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
        self::assertSame(1329500, $sheet['envelopes']['availableMinor']);
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
        self::assertSame(1329500, $closed['envelopes']['availableMinor']);
        self::assertSame(3996000, $closed['incomeMinor']);

        $upcoming = $dashboard->show('2026-11-03', $sheet['envelopes']['payCycleId'], null, true);
        self::assertTrue($upcoming['envelopes']['period']['upcoming']);
        self::assertTrue($upcoming['envelopes']['period']['editable']);
        self::assertSame('2026-12-01', $upcoming['envelopes']['period']['start']);
        self::assertSame(50000, $this->amount($upcoming['envelopes'], 'Needs 1'));
        self::assertSame(-15000, $this->amount($upcoming['envelopes'], 'Wants 1'));
        self::assertSame(1329500, $upcoming['envelopes']['availableMinor']);
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
                ['categoryId' => $wants, 'amountMinor' => -15000],
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
        self::assertSame(-15000, $this->amount($opened, 'Wants 1'));
        self::assertSame($opened['poolMinor'] - 1000, $opened['availableMinor']);
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
        self::assertSame(0, $this->amount($this->envelopes->show('2026-11-03', $this->payCycleId($sheet, 'Monthly Payor')), 'Needs 1'));
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
        self::assertSame(1329500 - 2500, $saved['availableMinor']);
        self::assertSame(-12500, $this->namedCategory('Wants 1')['amountMinor']);

        $monthly = $this->envelopes->show('2026-11-03', $sheet['payCycleId']);
        self::assertSame(50000, $this->amount($monthly, 'Needs 1'));
        self::assertSame(52500, $monthly['assignedMinor']);
    }

    /** @param array<string, mixed> $sheet */
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

    private function amount(array $sheet, string $name): int
    {
        foreach ($sheet['categories'] as $category) {
            if ($category['name'] === $name) {
                return (int) $category['amountMinor'];
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
