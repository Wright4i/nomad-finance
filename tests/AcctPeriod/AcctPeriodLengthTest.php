<?php

declare(strict_types=1);

namespace App\Tests\AcctPeriod;

use App\AcctPeriod\AcctPeriod;
use PHPUnit\Framework\TestCase;

final class AcctPeriodLengthTest extends TestCase
{
    public function testWeeksAreSevenDaysAndMonthsStopTheDayBeforeTheAnniversary(): void
    {
        self::assertSame('2026-11-07', AcctPeriod::end('2026-11-01', '1 week'));
        self::assertSame('2026-11-14', AcctPeriod::end('2026-11-01', '2 weeks'));
        self::assertSame('2026-11-28', AcctPeriod::end('2026-11-01', '4 weeks'));
        self::assertSame('2026-11-29', AcctPeriod::shift('2026-11-01', '4 weeks', 1));
        self::assertSame('2026-10-04', AcctPeriod::shift('2026-11-01', '4 weeks', -1));

        self::assertSame('2026-11-30', AcctPeriod::end('2026-11-01', '1 month'));
        self::assertSame('2026-02-28', AcctPeriod::end('2026-02-01', '1 month'));
        self::assertSame('2026-03-14', AcctPeriod::end('2026-02-15', '1 month'));
        self::assertSame('2026-02-27', AcctPeriod::end('2026-01-31', '1 month'));
        self::assertSame('2026-03-30', AcctPeriod::end('2026-01-31', '2 months'));
        self::assertSame('2026-04-29', AcctPeriod::end('2026-01-31', '3 months'));
        self::assertSame('2024-02-28', AcctPeriod::end('2024-01-31', '1 month'));

        self::assertSame('2026-12-01', AcctPeriod::shift('2026-11-01', '1 month', 1));
        self::assertSame('2026-02-28', AcctPeriod::shift('2026-01-31', '1 month', 1));
        self::assertSame('current', AcctPeriod::phase('2026-11-01', '2026-11-30', '2026-11-06'));
        self::assertSame('closed', AcctPeriod::phase('2026-10-01', '2026-10-31', '2026-11-06'));
        self::assertSame('future', AcctPeriod::phase('2026-12-01', '2026-12-31', '2026-11-06'));
    }
}
