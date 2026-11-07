<?php

declare(strict_types=1);

namespace App\Tests\Span;

use App\Span\Span;
use PHPUnit\Framework\TestCase;

final class SpanLengthTest extends TestCase
{
    public function testWeeksAreInclusiveAndMonthsClampJanuaryThirtyFirst(): void
    {
        self::assertSame('2026-11-07', Span::end('2026-11-01', '1 week'));
        self::assertSame('2026-11-14', Span::end('2026-11-01', '2 weeks'));
        self::assertSame('2026-11-28', Span::end('2026-11-01', '4 weeks'));
        self::assertSame('2026-11-29', Span::shift('2026-11-01', '4 weeks', 1));
        self::assertSame('2026-10-04', Span::shift('2026-11-01', '4 weeks', -1));

        self::assertSame('2026-11-30', Span::end('2026-11-01', '1 month'));
        self::assertSame('2026-02-28', Span::end('2026-02-01', '1 month'));
        self::assertSame('2026-03-14', Span::end('2026-02-15', '1 month'));
        self::assertSame('2026-02-27', Span::end('2026-01-31', '1 month'));
        self::assertSame('2026-03-30', Span::end('2026-01-31', '2 months'));
        self::assertSame('2026-04-29', Span::end('2026-01-31', '3 months'));
        self::assertSame('2024-02-28', Span::end('2024-01-31', '1 month'));

        self::assertSame('2026-12-01', Span::shift('2026-11-01', '1 month', 1));
        self::assertSame('2026-02-28', Span::shift('2026-01-31', '1 month', 1));
        self::assertSame('current', Span::phase('2026-11-01', '2026-11-30', '2026-11-06'));
        self::assertSame('closed', Span::phase('2026-10-01', '2026-10-31', '2026-11-06'));
        self::assertSame('future', Span::phase('2026-12-01', '2026-12-31', '2026-11-06'));
    }
}
