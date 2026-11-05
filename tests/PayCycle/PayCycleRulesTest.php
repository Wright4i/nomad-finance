<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\Auth\Actor;
use App\Database\Database;
use App\Http\ApiException;
use App\PayCycle\PayCycleService;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class PayCycleRulesTest extends TestCase
{
    private PDO $pdo;

    private PayCycleService $cycles;

    private string $path;

    private int $demoId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $this->demoId = DemoSession::start($this->pdo);
        $this->cycles = new PayCycleService($this->pdo, new Actor($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testEachPayorKeepsItsOwnCycle(): void
    {
        self::assertSame([], $this->cycles->list('2026-11-03', false));

        $first = $this->cycles->create([
            'payor' => 'Job A',
            'payday' => '2026-01-15',
            'length' => 'semimonthly',
            'secondDay' => '31',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-02-20');
        self::assertSame('Job A', $first['payor']);
        self::assertSame('semimonthly', $first['length']);
        self::assertSame(31, $first['secondDay']);
        self::assertNull($first['nextPayday']);
        self::assertSame('2026-02-15', $first['periodStart']);
        self::assertSame('2026-02-27', $first['periodEnd']);
        self::assertSame('2026-02-28', $first['rollsOn']);

        $second = $this->cycles->create([
            'payor' => 'Job B',
            'payday' => '2026-03-01',
            'length' => 'weekly',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-03-10');
        self::assertSame('weekly', $second['length']);
        self::assertSame('2026-03-08', $second['periodStart']);

        $edited = $this->cycles->update($first['id'], [
            'payor' => 'Job A',
            'payday' => '2026-01-31',
            'length' => 'monthly',
            'secondDay' => 15,
            'nextPayday' => '2026-03-01',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-03-31');
        self::assertSame('2026-01-31', $edited['payday']);
        self::assertSame('monthly', $edited['length']);
        self::assertNull($edited['secondDay']);
        self::assertNull($edited['nextPayday']);
        self::assertSame('2026-03-31', $edited['periodStart']);
        self::assertSame('2026-04-29', $edited['periodEnd']);

        $kept = $this->cycles->get($second['id'], '2026-03-10');
        self::assertSame('Job B', $kept['payor']);
        self::assertSame('weekly', $kept['length']);
        self::assertCount(2, $this->cycles->list('2026-03-10', false));
    }

    public function testIrregularRollsWhenTodayReachesTheNextPayday(): void
    {
        $waiting = $this->cycles->create([
            'payor' => 'Client 1',
            'payday' => '2026-01-01',
            'length' => 'irregular',
            'nextPayday' => '2026-06-01',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-04-01');
        self::assertSame('2026-01-01', $waiting['payday']);
        self::assertSame('2026-06-01', $waiting['nextPayday']);
        self::assertSame('2026-05-31', $waiting['periodEnd']);

        $reached = $this->cycles->get($waiting['id'], '2026-06-01');
        self::assertSame('2026-06-01', $reached['payday']);
        self::assertNull($reached['nextPayday']);
        self::assertSame('2026-06-01', $reached['periodStart']);
        self::assertNull($reached['rollsOn']);

        $again = $this->cycles->get($waiting['id'], '2026-08-01');
        self::assertSame('2026-06-01', $again['payday']);
        self::assertNull($again['nextPayday']);
    }

    public function testARepeatedPayorAndABlankNameAreRefused(): void
    {
        $this->cycles->create([
            'payor' => 'Job A',
            'payday' => '2026-11-01',
            'length' => 'monthly',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');

        try {
            $this->cycles->create([
                'payor' => 'job a',
                'payday' => '2026-12-01',
                'length' => 'weekly',
            
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');
            self::fail('A payor has one cycle.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('That payor already has a pay cycle.', $e->fields['payor']);
        }

        try {
            $this->cycles->create(['payor' => '  ', 'payday' => 'March', 'length' => 'yearly',
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');
            self::fail('The payor, payday, and length are required.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('payor', $e->fields);
            self::assertArrayHasKey('payday', $e->fields);
            self::assertArrayHasKey('length', $e->fields);
        }

        try {
            $this->cycles->create([
                'payor' => 'Job C',
                'payday' => '2026-11-15',
                'length' => 'semimonthly',
                'secondDay' => 15,
            
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');
            self::fail('Semimonthly needs a second day.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('secondDay', $e->fields);
        }

        self::assertCount(1, $this->cycles->list('2026-11-03', true));
    }

    public function testArchiveHidesAPayorUntilAsked(): void
    {
        $cycle = $this->cycles->create([
            'payor' => 'Job A',
            'payday' => '2026-11-01',
            'length' => 'monthly',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');
        $hidden = $this->cycles->archive($cycle['id'], '2026-11-03');
        self::assertNotNull($hidden['archivedAt']);
        self::assertSame([], $this->cycles->list('2026-11-03', false));
        self::assertSame('Job A', $this->cycles->list('2026-11-03', true)[0]['payor']);

        $back = $this->cycles->unarchive($cycle['id'], '2026-11-03');
        self::assertNull($back['archivedAt']);
        self::assertSame('Job A', $this->cycles->list('2026-11-03', false)[0]['payor']);
    }

    public function testAnotherPersonCannotSeeTheseCycles(): void
    {
        $demo = $this->cycles->create([
            'payor' => 'Job A',
            'payday' => '2026-11-01',
            'length' => 'monthly',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');

        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'EUR\', \'light\', \'owner\', ?)'
        )->execute(['Ada', password_hash('correct horse battery', PASSWORD_DEFAULT), $now]);
        $_SESSION['user_id'] = (int) $this->pdo->lastInsertId();

        self::assertSame([], $this->cycles->list('2026-11-03', true));
        try {
            $this->cycles->get($demo['id'], '2026-11-03');
            self::fail('Another person cannot open this cycle.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        $this->cycles->create([
            'payor' => 'Job A',
            'payday' => '2026-12-01',
            'length' => 'weekly',
        
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');

        $_SESSION['user_id'] = $this->demoId;
        $rows = $this->cycles->list('2026-11-03', true);
        self::assertCount(1, $rows);
        self::assertSame('monthly', $rows[0]['length']);
        self::assertSame('2026-11-01', $rows[0]['payday']);
    }

    public function testAMonthPaydayCarriesTheCycleLength(): void
    {
        $this->cycles->create([
            'payor' => 'Weekly Job',
            'payday' => '2026-11-01',
            'length' => 'weekly',
            'payKind' => 'fixed',
            'rateMinor' => 100000,
        ], '2026-11-03');
        $this->cycles->create([
            'payor' => 'Monthly Job',
            'payday' => '2026-11-15',
            'length' => 'monthly',
            'payKind' => 'fixed',
            'rateMinor' => 250000,
        ], '2026-11-03');

        $paydays = $this->cycles->monthPay('2026-11', '2026-11-03');
        $byPayor = [];
        foreach ($paydays as $payday) {
            $byPayor[$payday['payor']][] = $payday;
        }

        self::assertCount(5, $byPayor['Weekly Job']);
        foreach ($byPayor['Weekly Job'] as $payday) {
            self::assertSame('weekly', $payday['length']);
            self::assertSame(100000, $payday['paycheckMinor']);
        }
        self::assertSame([[
            'id' => $byPayor['Monthly Job'][0]['id'],
            'payor' => 'Monthly Job',
            'on' => '2026-11-15',
            'paycheckMinor' => 250000,
            'length' => 'monthly',
        ]], $byPayor['Monthly Job']);
    }

    public function testAPaycheckFollowsTheKind(): void
    {
        $salary = $this->cycles->create([
            'payor' => 'Salary Job',
            'payday' => '2026-11-01',
            'length' => 'monthly',
            'payKind' => 'salary',
            'rateMinor' => 8400000,
        ], '2026-11-03');
        self::assertSame(700000, $salary['paycheckMinor']);

        $hourly = $this->cycles->create([
            'payor' => 'Hourly Job',
            'payday' => '2026-11-01',
            'length' => 'weekly',
            'payKind' => 'hourly',
            'rateMinor' => 2500,
            'hours' => '40.00',
        ], '2026-11-03');
        self::assertSame(100000, $hourly['paycheckMinor']);
        self::assertSame('40.00', $hourly['hours']);

        try {
            $this->cycles->create([
                'payor' => 'Missing Hours',
                'payday' => '2026-11-01',
                'length' => 'weekly',
                'payKind' => 'hourly',
                'rateMinor' => 2500,
            ], '2026-11-03');
            self::fail('Hourly needs hours.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('hours', $e->fields);
        }

        $irregular = $this->cycles->create([
            'payor' => 'Irregular Salary',
            'payday' => '2026-11-01',
            'length' => 'irregular',
            'nextPayday' => '2026-12-15',
            'payKind' => 'salary',
            'rateMinor' => 5000000,
        ], '2026-11-03');
        self::assertNull($irregular['paycheckMinor']);

        try {
            $this->cycles->create([
                'payor' => 'No Rate',
                'payday' => '2026-11-01',
                'length' => 'monthly',
                'payKind' => 'fixed',
                'rateMinor' => 0,
            ], '2026-11-03');
            self::fail('A rate has to be greater than zero.');
        } catch (ApiException $e) {
            self::assertArrayHasKey('rateMinor', $e->fields);
        }
    }
}
