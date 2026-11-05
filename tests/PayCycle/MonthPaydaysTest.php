<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\PayCycle\MonthPaydays;
use PHPUnit\Framework\TestCase;

final class MonthPaydaysTest extends TestCase
{
    public function testWeeklyDatesStayOnTheAnchorCadence(): void
    {
        self::assertSame(
            ['2026-11-01', '2026-11-08', '2026-11-15', '2026-11-22', '2026-11-29'],
            MonthPaydays::dates('2026-11-01', 'weekly', null, null, '2026-11'),
        );
        self::assertSame(
            ['2026-12-06', '2026-12-13', '2026-12-20', '2026-12-27'],
            MonthPaydays::dates('2026-11-01', 'weekly', null, null, '2026-12'),
        );
    }

    public function testAMonthBeforeTheAnchorHasNoPayday(): void
    {
        self::assertSame([], MonthPaydays::dates('2026-11-01', 'weekly', null, null, '2026-10'));
    }

    public function testSemimonthlyUsesBothDays(): void
    {
        self::assertSame(
            ['2026-11-01', '2026-11-16'],
            MonthPaydays::dates('2026-11-01', 'semimonthly', 16, null, '2026-11'),
        );
    }
}
