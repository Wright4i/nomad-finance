<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Trip\Drive;
use App\Trip\Locality;
use PHPUnit\Framework\TestCase;

final class DriveTest extends TestCase
{
    public function testMilesRoundHalfUpFromMeters(): void
    {
        self::assertSame(0, Drive::miles(0));
        self::assertSame(0, Drive::miles(804));
        self::assertSame(1, Drive::miles(805));
        self::assertSame(1, Drive::miles(1609));
    }

    public function testTheStraightLineFromLasVegasToLosAngelesIsAbout230Miles(): void
    {
        $meters = Drive::haversineMeters(36.1699, -115.1398, 34.0522, -118.2437);
        $miles = Drive::miles($meters);
        self::assertGreaterThan(220, $miles);
        self::assertLessThan(245, $miles);
    }

    public function testARoadResponseKeepsTheLineAndTheDistance(): void
    {
        $parsed = Drive::geometry([
            'code' => 'Ok',
            'routes' => [[
                'distance' => 430000,
                'geometry' => ['coordinates' => [[-115.1, 36.1], [-118.2, 34.0]]],
            ]],
        ]);
        self::assertNotNull($parsed);
        self::assertSame(430000.0, $parsed['meters']);
        self::assertSame([[-115.1, 36.1], [-118.2, 34.0]], $parsed['coordinates']);
        self::assertNull(Drive::geometry(['code' => 'NoRoute']));
    }

    public function testACityAndStateComeFromTheAddress(): void
    {
        $place = Locality::fromAddress([
            'house_number' => '1',
            'road' => 'Fremont St',
            'city' => 'Las Vegas',
            'postcode' => '89101',
            'state' => 'Nevada',
            'ISO3166-2-lvl4' => 'US-NV',
        ]);
        self::assertSame([
            'city' => 'Las Vegas',
            'region' => 'NV',
            'address' => '1 Fremont St, Las Vegas, NV 89101',
        ], $place);
        $named = Locality::fromAddress(['town' => 'Bend', 'state' => 'Oregon']);
        self::assertSame(['city' => 'Bend', 'region' => 'OR', 'address' => 'Bend, OR'], $named);
        self::assertNull(Locality::fromAddress(['state' => 'Nevada']));
    }
}
