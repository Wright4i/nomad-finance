<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\PayCycle\Paycheck;
use PHPUnit\Framework\TestCase;

final class PaycheckTest extends TestCase
{
    public function testSalarySplitsHalfUpAndSkipsAnIrregularCycle(): void
    {
        self::assertSame(4, Paycheck::amount('salary', 'biweekly', 100, null));
        self::assertSame(1, Paycheck::amount('salary', 'biweekly', 13, null));
        self::assertSame(700000, Paycheck::amount('salary', 'monthly', 8400000, null));
        self::assertSame(250000, Paycheck::amount('salary', 'semimonthly', 6000000, null));
        self::assertNull(Paycheck::amount('salary', 'irregular', 5000000, null));
        self::assertNull(Paycheck::amount('salary', 'monthly', 0, null));
    }

    public function testMonthlyAmountScalesTheCheckAndSkipsIrregular(): void
    {
        self::assertSame(433333, Paycheck::monthlyAmount(100000, 'weekly'));
        self::assertSame(520000, Paycheck::monthlyAmount(240000, 'biweekly'));
        self::assertSame(500000, Paycheck::monthlyAmount(250000, 'semimonthly'));
        self::assertSame(700000, Paycheck::monthlyAmount(700000, 'monthly'));
        self::assertNull(Paycheck::monthlyAmount(150000, 'irregular'));
        self::assertNull(Paycheck::monthlyAmount(null, 'monthly'));
    }

    public function testFixedIsTheRateAndHourlyRoundsHalfUp(): void
    {
        self::assertSame(150000, Paycheck::amount('fixed', 'irregular', 150000, null));
        self::assertSame(100000, Paycheck::amount('hourly', 'weekly', 2500, 4000));
        self::assertSame(2, Paycheck::amount('hourly', 'weekly', 1, 150));
        self::assertNull(Paycheck::amount('hourly', 'weekly', 2500, null));
    }

    public function testWeekdayGrossUsesTheMonthBeforeThePayday(): void
    {
        $november = Paycheck::weekdaySplit('2026-11-01', 15, 2500);
        self::assertNotNull($november);
        self::assertSame(22, $november['weekdays']);
        self::assertSame(176, $november['hours']);
        self::assertSame(440000, $november['grossMinor']);
        self::assertSame(1, $november['earlierDay']);
        self::assertSame(220000, $november['earlierMinor']);
        self::assertSame(15, $november['laterDay']);
        self::assertSame(220000, $november['laterMinor']);

        $january = Paycheck::weekdaySplit('2026-01-15', 1, 1);
        self::assertNotNull($january);
        self::assertSame(23, $january['weekdays']);
        self::assertSame(184, $january['grossMinor']);
        self::assertSame(1, $january['earlierDay']);
        self::assertSame(92, $january['earlierMinor']);
        self::assertSame(15, $january['laterDay']);
        self::assertSame(92, $january['laterMinor']);
    }

    public function testAMonthlyWeekdayUsesThePriorMonth(): void
    {
        $october = Paycheck::monthlyWeekday('2026-11-01', 110, 800);
        self::assertNotNull($october);
        self::assertSame('2026-10', $october['month']);
        self::assertSame(22, $october['weekdays']);
        self::assertSame(17600, $october['hoursHundredths']);
        self::assertSame(22 * 8 * 110, $october['paycheckMinor']);
        self::assertSame(self::weekdaysIn('2026-10'), $october['weekdays']);
        self::assertGreaterThan(0, self::weekendDays('2026-10'));
        self::assertSame(6, (int) (new \DateTimeImmutable('2026-10-03'))->format('N'));
        self::assertSame(7, (int) (new \DateTimeImmutable('2026-10-04'))->format('N'));

        $february = Paycheck::monthlyWeekday('2026-03-01', 110, 800);
        self::assertNotNull($february);
        self::assertSame('2026-02', $february['month']);
        self::assertNotSame(22, $february['weekdays']);
        self::assertSame(self::weekdaysIn('2026-02'), $february['weekdays']);
        self::assertSame(0, self::weekdaysIn('2026-02') - $february['weekdays']);
        self::assertGreaterThan(0, self::weekendDays('2026-02'));
    }

    public function testTheOddMinorUnitLandsOnTheEarlierPayday(): void
    {
        $shares = Paycheck::shares(5, 15, 1);
        self::assertSame(1, $shares['earlierDay']);
        self::assertSame(3, $shares['earlierMinor']);
        self::assertSame(15, $shares['laterDay']);
        self::assertSame(2, $shares['laterMinor']);
        self::assertSame(2, Paycheck::shares(4, 1, 15)['earlierMinor']);
        self::assertSame(2, Paycheck::shares(4, 1, 15)['laterMinor']);
    }

    private static function weekdaysIn(string $month): int
    {
        return self::daysIn($month, false);
    }

    private static function weekendDays(string $month): int
    {
        return self::daysIn($month, true);
    }

    private static function daysIn(string $month, bool $weekend): int
    {
        $count = 0;
        $cursor = new \DateTimeImmutable($month . '-01');
        while ($cursor->format('Y-m') === $month) {
            $saturdayOrSunday = (int) $cursor->format('N') >= 6;
            if ($saturdayOrSunday === $weekend) {
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }
}
