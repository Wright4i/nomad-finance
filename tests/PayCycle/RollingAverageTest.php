<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\Auth\Actor;
use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\PayCycle\RollingAverage;
use App\Target\TargetService;
use App\Trip\Drive;
use PDO;
use PHPUnit\Framework\TestCase;

final class RollingAverageTest extends TestCase
{
    private PDO $pdo;

    private string $path;

    private PayCycleService $cycles;

    private RollingAverage $average;

    private EnvelopeService $envelopes;

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
        $auth->enterDemo();
        $this->cycles = new PayCycleService($this->pdo, $actor);
        $this->average = new RollingAverage($this->pdo, $actor);
        $this->envelopes = new EnvelopeService(
            $this->pdo,
            $actor,
            $catalog,
            new TargetService($this->pdo, $actor),
            $this->cycles,
            new Drive(false),
        );
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testTheDivisorKeepsAnEmptyMonthAndNamesAMissingRate(): void
    {
        $result = RollingAverage::rolling([
            ['month' => '2026-08', 'baseMinor' => 100, 'name' => 'August Pay'],
            ['month' => '2026-10', 'baseMinor' => 50, 'name' => 'October Pay'],
            ['month' => '2026-10', 'baseMinor' => null, 'name' => 'Unrated Pay'],
            ['month' => '2026-11', 'baseMinor' => 999, 'name' => 'November Pay'],
        ], ['2026-08', '2026-09', '2026-10']);

        self::assertSame(50, $result['averageMinor']);
        self::assertSame(['Unrated Pay'], $result['excluded']);
        self::assertSame(2, RollingAverage::rolling([
            ['month' => '2026-08', 'baseMinor' => 5, 'name' => 'August Pay'],
        ], ['2026-08', '2026-09', '2026-10'])['averageMinor']);
    }

    public function testKeyedAmountsAverageEarlierPayAndLeaveThePoolAlone(): void
    {
        $weekly = $this->cycle('Side job');
        $saved = $this->cycles->update((int) $weekly['id'], $this->hourly($weekly, true), '2026-11-04');
        self::assertTrue($saved['averageOn']);
        $pool = $this->envelopes->show('2026-11-04')['poolMinor'];
        $this->cycles->savePayroll((int) $weekly['id'], [
            'entries' => [
                ['payday' => '2026-10-01', 'amountMinor' => 300000],
                ['payday' => '2026-10-15', 'amountMinor' => 500000],
                ['payday' => '2026-10-16', 'amountMinor' => ''],
                ['payday' => '2026-11-01', 'amountMinor' => 400000],
            ],
        ], '2026-11-05');
        self::assertSame(400000, $this->cycles->projected($this->cycle('Side job'), '2026-11-15'));
        $this->cycles->savePayroll((int) $weekly['id'], [
            'entries' => [
                ['payday' => '2026-11-15', 'amountMinor' => 574000],
            ],
        ], '2026-11-05');
        self::assertSame(574000, $this->cycles->projected($this->cycle('Side job'), '2026-11-15'));
        self::assertSame(443500, $this->cycles->projected($this->cycle('Side job'), '2026-12-01'));
        self::assertGreaterThan($pool, $this->envelopes->show('2026-11-04')['poolMinor']);
    }

    public function testAnEmptyAmountIsNotKeyedAndAverageStartsOff(): void
    {
        $weekly = $this->cycle('Weekend shift');
        self::assertFalse($weekly['averageOn']);
        $this->cycles->savePayroll((int) $weekly['id'], [
            'entries' => [
                ['payday' => '2026-11-01', 'amountMinor' => 100],
                ['payday' => '2026-11-08', 'amountMinor' => ''],
            ],
        ], '2026-11-05');
        $sheet = $this->cycles->payroll((int) $weekly['id'], '2026-11-05');
        $byDate = [];
        foreach ($sheet['rows'] as $row) {
            $byDate[$row['payday']] = $row;
        }
        self::assertTrue($byDate['2026-11-01']['keyed']);
        self::assertFalse($byDate['2026-11-08']['keyed'] ?? false);
    }

    /**
     * @param array<string, mixed> $cycle
     * @return array<string, mixed>
     */
    private function hourly(array $cycle, bool $averageOn): array
    {
        $row = [
            'payor' => $cycle['payor'],
            'payday' => $cycle['payday'],
            'length' => $cycle['length'],
            'payKind' => 'hourly',
            'rateMinor' => $cycle['rateMinor'],
            'hours' => $cycle['hours'],
            'hoursMode' => 'typed',
            'averageOn' => $averageOn,
        ];
        if (($cycle['length'] ?? '') === 'semimonthly') {
            $row['secondDay'] = $cycle['secondDay'];
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function cycle(string $payor): array
    {
        foreach ($this->cycles->list('2026-11-04', true) as $cycle) {
            if ($cycle['payor'] === $payor) {
                return $cycle;
            }
        }
        self::fail('Missing ' . $payor);
    }
}
