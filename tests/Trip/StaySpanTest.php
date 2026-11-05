<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Trip\StaySpan;
use PHPUnit\Framework\TestCase;

final class StaySpanTest extends TestCase
{
    public function testArrivalAndDepartureBothCount(): void
    {
        self::assertSame(
            ['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24'],
            StaySpan::days('2026-11-20', '2026-11-24'),
        );
        self::assertTrue(StaySpan::covers('2026-11-20', '2026-11-24', '2026-11-20'));
        self::assertTrue(StaySpan::covers('2026-11-20', '2026-11-24', '2026-11-22'));
        self::assertTrue(StaySpan::covers('2026-11-20', '2026-11-24', '2026-11-24'));
        self::assertFalse(StaySpan::covers('2026-11-20', '2026-11-24', '2026-11-19'));
        self::assertFalse(StaySpan::covers('2026-11-20', '2026-11-24', '2026-11-25'));
    }

    public function testAStayCrossesAMonth(): void
    {
        self::assertSame(['2026-11-30', '2026-12-01'], StaySpan::days('2026-11-30', '2026-12-01'));
        self::assertTrue(StaySpan::covers('2026-11-30', '2026-12-01', '2026-12-01'));
        self::assertFalse(StaySpan::covers('2026-11-30', '2026-12-01', '2026-12-02'));
    }

    public function testOneDayIsAStay(): void
    {
        self::assertSame(['2026-11-04'], StaySpan::days('2026-11-04', '2026-11-04'));
        self::assertTrue(StaySpan::covers('2026-11-04', '2026-11-04', '2026-11-04'));
    }
}
