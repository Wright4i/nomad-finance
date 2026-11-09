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

    public function testARoundTripFlightAddsAReturnLegToTheOngoingStay(): void
    {
        $drive = new Drive(false);
        $legs = $drive->legs([
            [
                'id' => 1,
                'latitude' => '33.48',
                'longitude' => '-111.6',
                'travel' => 'drive',
                'arrive_on' => '2026-11-01',
                'depart_on' => '2026-11-30',
            ],
            [
                'id' => 2,
                'latitude' => '37.77',
                'longitude' => '-122.41',
                'travel' => 'fly',
                'arrive_on' => '2026-11-10',
                'depart_on' => '2026-11-15',
            ],
        ]);
        self::assertCount(2, $legs);
        self::assertSame([1, 2], [$legs[0]['fromId'], $legs[0]['toId']]);
        self::assertSame('fly', $legs[0]['travel']);
        self::assertSame([2, 1], [$legs[1]['fromId'], $legs[1]['toId']]);
        self::assertSame('fly', $legs[1]['travel']);
    }

    public function testAFlightReturnsToBaseBeforeADriveToANestedStay(): void
    {
        $drive = new Drive(false);
        $legs = $drive->legs([
            [
                'id' => 1,
                'latitude' => '36.17',
                'longitude' => '-115.14',
                'travel' => 'drive',
                'arrive_on' => '2026-10-23',
                'depart_on' => '2026-11-23',
            ],
            [
                'id' => 2,
                'latitude' => '33.66',
                'longitude' => '-117.69',
                'travel' => 'fly',
                'arrive_on' => '2026-11-08',
                'depart_on' => '2026-11-10',
            ],
            [
                'id' => 3,
                'latitude' => '36.27',
                'longitude' => '-115.24',
                'travel' => 'drive',
                'arrive_on' => '2026-11-22',
                'depart_on' => '2026-11-23',
            ],
        ]);
        self::assertCount(3, $legs);
        self::assertSame([1, 2], [$legs[0]['fromId'], $legs[0]['toId']]);
        self::assertSame('fly', $legs[0]['travel']);
        self::assertSame([2, 1], [$legs[1]['fromId'], $legs[1]['toId']]);
        self::assertSame('fly', $legs[1]['travel']);
        self::assertSame('drive', $legs[2]['travel']);
        self::assertSame([1, 3], [$legs[2]['fromId'], $legs[2]['toId']]);
        self::assertGreaterThan(0, $legs[2]['miles']);
    }

    public function testAFlightReturnsToBaseBeforeTheNextCampgroundHandoff(): void
    {
        $drive = new Drive(false);
        $legs = $drive->legs([
            [
                'id' => 1,
                'latitude' => '44.16',
                'longitude' => '-124.11',
                'travel' => 'drive',
                'arrive_on' => '2026-07-21',
                'depart_on' => '2026-07-26',
            ],
            [
                'id' => 2,
                'latitude' => '44.05',
                'longitude' => '-123.10',
                'travel' => 'fly',
                'arrive_on' => '2026-07-23',
                'depart_on' => '2026-07-25',
            ],
            [
                'id' => 3,
                'latitude' => '44.63',
                'longitude' => '-123.11',
                'travel' => 'drive',
                'arrive_on' => '2026-07-26',
                'depart_on' => '2026-07-28',
            ],
        ]);
        self::assertCount(3, $legs);
        self::assertSame('fly', $legs[0]['travel']);
        self::assertSame([1, 2], [$legs[0]['fromId'], $legs[0]['toId']]);
        self::assertSame('fly', $legs[1]['travel']);
        self::assertSame([2, 1], [$legs[1]['fromId'], $legs[1]['toId']]);
        self::assertSame('drive', $legs[2]['travel']);
        self::assertSame([1, 3], [$legs[2]['fromId'], $legs[2]['toId']]);
    }

    public function testAOneWayFlightContinuesWithADriveWhenTheNextStayDoesNotOverlap(): void
    {
        $drive = new Drive(false);
        $legs = $drive->legs([
            [
                'id' => 1,
                'latitude' => '33.48',
                'longitude' => '-111.6',
                'travel' => 'drive',
                'arrive_on' => '2026-11-01',
                'depart_on' => '2026-11-20',
            ],
            [
                'id' => 2,
                'latitude' => '37.78',
                'longitude' => '-122.42',
                'travel' => 'fly',
                'arrive_on' => '2026-11-05',
                'depart_on' => '2026-11-08',
            ],
            [
                'id' => 3,
                'latitude' => '34.05',
                'longitude' => '-118.24',
                'travel' => 'drive',
                'arrive_on' => '2026-11-09',
                'depart_on' => '2026-11-25',
            ],
        ]);
        self::assertCount(2, $legs);
        self::assertSame('fly', $legs[0]['travel']);
        self::assertSame([1, 2], [$legs[0]['fromId'], $legs[0]['toId']]);
        self::assertSame('drive', $legs[1]['travel']);
        self::assertSame([2, 3], [$legs[1]['fromId'], $legs[1]['toId']]);
        self::assertGreaterThan(0, $legs[1]['miles']);
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
