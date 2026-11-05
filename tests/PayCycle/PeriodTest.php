<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\PayCycle\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testWeeklyAndBiweeklyStepFromThePayday(): void
    {
        $weekly = Period::open('2026-11-01', 'weekly', null, null, '2026-11-03');
        self::assertSame('2026-11-01', $weekly['start']);
        self::assertSame('2026-11-08', $weekly['next']);

        $rolled = Period::open('2026-11-01', 'weekly', null, null, '2026-11-08');
        self::assertSame('2026-11-08', $rolled['start']);
        self::assertSame('2026-11-15', $rolled['next']);

        $biweekly = Period::open('2026-11-01', 'biweekly', null, null, '2026-11-20');
        self::assertSame('2026-11-15', $biweekly['start']);
        self::assertSame('2026-11-29', $biweekly['next']);

        $ahead = Period::open('2026-12-01', 'weekly', null, null, '2026-11-03');
        self::assertSame('2026-12-01', $ahead['start']);
        self::assertSame('2026-12-08', $ahead['next']);
    }

    public function testMonthlyKeepsThePaydayAndClampsAShortMonth(): void
    {
        $open = Period::open('2026-01-31', 'monthly', null, null, '2026-02-10');
        self::assertSame('2026-01-31', $open['start']);
        self::assertSame('2026-02-28', $open['next']);

        $february = Period::open('2026-01-31', 'monthly', null, null, '2026-02-28');
        self::assertSame('2026-02-28', $february['start']);
        self::assertSame('2026-03-31', $february['next']);

        $march = Period::open('2026-01-31', 'monthly', null, null, '2026-03-31');
        self::assertSame('2026-03-31', $march['start']);
        self::assertSame('2026-04-30', $march['next']);

        $leap = Period::open('2024-01-31', 'monthly', null, null, '2024-02-29');
        self::assertSame('2024-02-29', $leap['start']);
        self::assertSame('2024-03-31', $leap['next']);
    }

    public function testSemimonthlyUsesBothDaysAndClamps(): void
    {
        $first = Period::open('2026-01-15', 'semimonthly', 31, null, '2026-01-20');
        self::assertSame('2026-01-15', $first['start']);
        self::assertSame('2026-01-31', $first['next']);

        $february = Period::open('2026-01-15', 'semimonthly', 31, null, '2026-02-20');
        self::assertSame('2026-02-15', $february['start']);
        self::assertSame('2026-02-28', $february['next']);

        $march = Period::open('2026-01-15', 'semimonthly', 31, null, '2026-03-01');
        self::assertSame('2026-02-28', $march['start']);
        self::assertSame('2026-03-15', $march['next']);
    }

    public function testIrregularStaysOpenUntilTheNextPaydayIsReached(): void
    {
        $open = Period::open('2026-01-01', 'irregular', null, null, '2026-04-01');
        self::assertSame('2026-01-01', $open['start']);
        self::assertNull($open['next']);
        self::assertFalse($open['rolled']);

        $waiting = Period::open('2026-01-01', 'irregular', null, '2026-06-01', '2026-04-01');
        self::assertSame('2026-01-01', $waiting['start']);
        self::assertSame('2026-06-01', $waiting['next']);
        self::assertFalse($waiting['rolled']);

        $reached = Period::open('2026-01-01', 'irregular', null, '2026-02-01', '2026-02-01');
        self::assertSame('2026-02-01', $reached['start']);
        self::assertNull($reached['next']);
        self::assertTrue($reached['rolled']);
    }
}
