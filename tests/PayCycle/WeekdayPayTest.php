<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\Auth\Actor;
use App\Database\Database;
use App\Http\ApiException;
use App\PayCycle\PayCycleService;
use App\PayCycle\Paycheck;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class WeekdayPayTest extends TestCase
{
    private PDO $pdo;

    private PayCycleService $cycles;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $this->cycles = new PayCycleService($this->pdo, new Actor($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testWeekdayModeIsRefusedOnAWeeklyCycle(): void
    {
        try {
            $this->cycles->create([
                'payor' => 'Weekly Hours',
                'payday' => '2026-11-01',
                'length' => 'weekly',
                'payKind' => 'hourly',
                'rateMinor' => 2500,
                'hours' => '40.00',
                'hoursMode' => 'weekdays',
            ], '2026-11-04');
            self::fail('Weekday hours were stored on a weekly cycle.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('hoursMode', $e->fields);
        }

        self::assertSame([], $this->cycles->list('2026-11-04', true));
    }

    public function testAWeekdayPreviewDoesNotPost(): void
    {
        $postings = $this->rows('postings');
        $assignments = $this->rows('assignments');
        $cycle = $this->cycles->create([
            'payor' => 'Weekday Payor',
            'payday' => '2026-11-01',
            'length' => 'semimonthly',
            'secondDay' => 15,
            'payKind' => 'hourly',
            'rateMinor' => 2500,
            'hoursMode' => 'weekdays',
        ], '2026-11-04');

        self::assertSame('weekdays', $cycle['hoursMode']);
        self::assertNull($cycle['hours']);
        self::assertNull($cycle['averageMonths']);
        self::assertSame(220000, $cycle['paycheckMinor']);
        self::assertSame(440000, $cycle['weekdayPay']['grossMinor']);
        self::assertSame(440000, $cycle['weekdayPay']['earlierMinor'] + $cycle['weekdayPay']['laterMinor']);
        self::assertSame($postings, $this->rows('postings'));
        self::assertSame($assignments, $this->rows('assignments'));

        $again = $this->cycles->get((int) $cycle['id'], '2026-11-04');
        self::assertSame($cycle['weekdayPay'], $again['weekdayPay']);
        self::assertSame($postings, $this->rows('postings'));
        self::assertSame($assignments, $this->rows('assignments'));
    }

    public function testAMonthlyWeekdayPaysThePriorMonth(): void
    {
        $cycle = $this->cycles->create([
            'payor' => 'Monthly Weekday Payor',
            'payday' => '2026-11-01',
            'length' => 'monthly',
            'payKind' => 'hourly',
            'rateMinor' => 110,
            'hours' => '8.00',
            'hoursMode' => 'weekdays',
        ], '2026-11-05');

        self::assertSame('monthly', $cycle['length']);
        self::assertSame('weekdays', $cycle['hoursMode']);
        self::assertSame('8.00', $cycle['hours']);
        self::assertNull($cycle['secondDay']);
        self::assertSame(22, $cycle['monthlyWeekday']['weekdays']);
        self::assertSame('2026-10', $cycle['monthlyWeekday']['month']);
        self::assertSame('176.00', $cycle['monthlyWeekday']['hours']);
        self::assertSame(22 * 8 * 110, $cycle['paycheckMinor']);

        $november = $this->payday($this->cycles->monthPay('2026-11', '2026-11-05'), 'Monthly Weekday Payor');
        self::assertSame('2026-11-01', $november['on']);
        self::assertSame(22 * 8 * 110, $november['paycheckMinor']);

        $december = $this->payday($this->cycles->monthPay('2026-12', '2026-11-05'), 'Monthly Weekday Payor');
        self::assertSame('2026-12-01', $december['on']);
        self::assertNotSame(22, Paycheck::monthlyWeekday('2026-12-01', 110, 800)['weekdays']);
        self::assertSame(21 * 8 * 110, $december['paycheckMinor']);

        try {
            $this->cycles->create([
                'payor' => 'Missing Weekday Hours',
                'payday' => '2026-11-01',
                'length' => 'monthly',
                'payKind' => 'hourly',
                'rateMinor' => 110,
                'hoursMode' => 'weekdays',
            ], '2026-11-05');
            self::fail('A monthly weekday needs hours per weekday.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('hours', $e->fields);
        }
    }

    /**
     * @param list<array<string, mixed>> $paydays
     * @return array<string, mixed>
     */
    private function payday(array $paydays, string $payor): array
    {
        foreach ($paydays as $payday) {
            if ($payday['payor'] === $payor) {
                return $payday;
            }
        }
        self::fail('Missing payday for ' . $payor);
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
