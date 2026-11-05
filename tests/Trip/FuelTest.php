<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Trip\Fuel;
use PHPUnit\Framework\TestCase;

final class FuelTest extends TestCase
{
    public function testTheFirstStayHasNoCost(): void
    {
        $first = Fuel::estimate(null, 300, 400);
        self::assertNull($first['miles']);
        self::assertNull($first['milesPerGallon']);
        self::assertNull($first['costMinor']);
        self::assertNull($first['priceMinor']);

        $leg = Fuel::estimate(100, 300, 400);
        self::assertSame(100, $leg['miles']);
        self::assertSame('30.0', $leg['milesPerGallon']);
        self::assertSame(1333, $leg['costMinor']);
        self::assertSame(400, $leg['priceMinor']);

        $noPrice = Fuel::estimate(100, 200, null);
        self::assertSame(100, $noPrice['miles']);
        self::assertSame('20.0', $noPrice['milesPerGallon']);
        self::assertNull($noPrice['costMinor']);
        self::assertNull($noPrice['priceMinor']);
        self::assertNull(Fuel::estimate(10, 200, 0)['costMinor']);
        self::assertNull(Fuel::estimate(10, 200, 0)['priceMinor']);

        $noMpg = Fuel::estimate(80, null, 400);
        self::assertSame(80, $noMpg['miles']);
        self::assertNull($noMpg['milesPerGallon']);
        self::assertNull($noMpg['costMinor']);
        self::assertNull($noMpg['priceMinor']);

        self::assertSame(1, Fuel::estimate(1, 20, 1)['costMinor']);
    }

    public function testAnOutOfRangeFillUpIsLeftOutOfThePooledAverage(): void
    {
        $pooled = Fuel::pooled([
            ['miles' => 20, 'gallons' => '2.500', 'milesPerGallon' => '8.0'],
            ['miles' => 100, 'gallons' => '5.000', 'milesPerGallon' => '20.0'],
            ['miles' => null, 'gallons' => null, 'milesPerGallon' => null],
        ], 60, 120);
        self::assertSame(80, $pooled);
        self::assertNull(Fuel::pooled([
            ['miles' => 100, 'gallons' => '5.000', 'milesPerGallon' => '20.0'],
        ], 60, 120));
        self::assertSame(90, Fuel::midpoint(60, 120));
    }
}
