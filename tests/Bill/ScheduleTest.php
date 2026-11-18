<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Bill\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public function testMonthlyClampsThenRestoresTheAnchorDay(): void
    {
        $february = Schedule::advance('monthly', '2026-01-31', '2026-01-31');
        self::assertSame('2026-02-28', $february);
        self::assertSame('2026-03-31', Schedule::advance('monthly', '2026-01-31', $february));
    }

    public function testWeeklyAddsSevenDays(): void
    {
        self::assertSame('2026-02-06', Schedule::advance('weekly', '2026-01-30', '2026-01-30'));
    }

    public function testYearlyKeepsLeapDayWhenTheYearHasIt(): void
    {
        $next = Schedule::advance('yearly', '2024-02-29', '2024-02-29');
        self::assertSame('2025-02-28', $next);
        $through2027 = Schedule::advance('yearly', '2024-02-29', '2026-02-28');
        self::assertSame('2027-02-28', $through2027);
        self::assertSame('2028-02-29', Schedule::advance('yearly', '2024-02-29', '2027-02-28'));
    }

    public function testRewindRestoresTheClampedAnchorDay(): void
    {
        self::assertSame('2026-01-31', Schedule::rewind('monthly', '2026-01-31', '2026-02-28'));
        self::assertSame('2026-02-28', Schedule::rewind('monthly', '2026-01-31', '2026-03-31'));
        self::assertSame('2026-01-30', Schedule::rewind('weekly', '2026-01-30', '2026-02-06'));
        self::assertSame('2024-02-29', Schedule::rewind('yearly', '2024-02-29', '2025-02-28'));
    }

    public function testEveryTwoStepsTwoIntervals(): void
    {
        self::assertSame('2028-06-15', Schedule::advance('yearly', '2026-06-15', '2026-06-15', 2));
        self::assertSame('2026-06-15', Schedule::rewind('yearly', '2026-06-15', '2028-06-15', 2));
        self::assertSame('2026-03-31', Schedule::advance('monthly', '2026-01-31', '2026-01-31', 2));
        self::assertSame('2026-01-31', Schedule::rewind('monthly', '2026-01-31', '2026-03-31', 2));
        self::assertSame('2026-02-02', Schedule::advance('daily', '2026-01-30', '2026-01-30', 3));
        self::assertSame('2026-01-30', Schedule::rewind('daily', '2026-01-30', '2026-02-02', 3));
        self::assertSame('2026-02-13', Schedule::advance('weekly', '2026-01-30', '2026-01-30', 2));
        self::assertSame('2026-01-30', Schedule::rewind('weekly', '2026-01-30', '2026-02-13', 2));
        self::assertSame('2026-02-28', Schedule::advance('yearly', '2024-02-29', '2024-02-29', 2));
    }
}
