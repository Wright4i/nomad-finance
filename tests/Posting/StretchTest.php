<?php

declare(strict_types=1);

namespace App\Tests\Posting;

use App\Posting\Stretch;
use PHPUnit\Framework\TestCase;

final class StretchTest extends TestCase
{
    public function testGallonsComeFromTheAmountAndThePrice(): void
    {
        self::assertSame('2.500', Stretch::gallons(1000, 400));
    }

    public function testALaterReadingShowsMilesPerGallon(): void
    {
        $rows = Stretch::decorate([
            ['id' => 1, 'amountMinor' => 1000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => 400],
            ['id' => 2, 'amountMinor' => 500, 'odometerMiles' => null, 'pricePerGallonMinor' => null],
            ['id' => 3, 'amountMinor' => 2000, 'odometerMiles' => 10100, 'pricePerGallonMinor' => 400],
        ]);
        self::assertNull($rows[1]['milesPerGallon']);
        self::assertSame(100, $rows[3]['miles']);
        self::assertSame('5.000', $rows[3]['gallons']);
        self::assertSame('20.0', $rows[3]['milesPerGallon']);
    }

    public function testAReadingThatDoesNotAdvanceIsNamed(): void
    {
        $rows = Stretch::decorate([
            ['id' => 1, 'amountMinor' => 1000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => 400],
            ['id' => 2, 'amountMinor' => 1000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => 400],
        ]);
        self::assertSame('The reading did not advance.', $rows[2]['note']);
        self::assertNull($rows[2]['milesPerGallon']);
    }

    public function testReadingsChainPerVehicle(): void
    {
        $rows = Stretch::decorate([
            ['id' => 1, 'vehicleId' => 1, 'amountMinor' => 1000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => 400],
            ['id' => 2, 'vehicleId' => 2, 'amountMinor' => 1000, 'odometerMiles' => 500, 'pricePerGallonMinor' => 400],
            ['id' => 3, 'vehicleId' => 1, 'amountMinor' => 1000, 'odometerMiles' => 10040, 'pricePerGallonMinor' => 400],
            ['id' => 4, 'vehicleId' => 2, 'amountMinor' => 1000, 'odometerMiles' => 560, 'pricePerGallonMinor' => 400],
        ]);
        self::assertSame(40, $rows[3]['miles']);
        self::assertSame('16.0', $rows[3]['milesPerGallon']);
        self::assertSame(60, $rows[4]['miles']);
        self::assertSame('24.0', $rows[4]['milesPerGallon']);
    }

    public function testTwoChargesAtOneReadingAreOneFillUp(): void
    {
        $rows = [
            ['id' => 1, 'vehicleId' => 1, 'amountMinor' => 4000, 'odometerMiles' => 9800, 'pricePerGallonMinor' => 400],
            ['id' => 2, 'vehicleId' => 1, 'amountMinor' => 4000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => 400],
            ['id' => 3, 'vehicleId' => 1, 'amountMinor' => 2000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => 400],
        ];
        $firstOnly = Stretch::fillUps([$rows[0], $rows[1]]);
        self::assertNull($firstOnly[0]['miles']);
        self::assertSame(200, $firstOnly[1]['miles']);
        self::assertSame('10.000', $firstOnly[1]['gallons']);
        self::assertSame('20.0', $firstOnly[1]['milesPerGallon']);

        $combined = Stretch::fillUps($rows);
        self::assertSame(200, $combined[1]['miles']);
        self::assertSame('15.000', $combined[1]['gallons']);
        self::assertSame('13.3', $combined[1]['milesPerGallon']);

        $missingPrice = Stretch::fillUps([
            $rows[0],
            $rows[1],
            ['id' => 4, 'vehicleId' => 1, 'amountMinor' => 2000, 'odometerMiles' => 10000, 'pricePerGallonMinor' => null],
        ]);
        self::assertNull($missingPrice[1]['gallons']);
        self::assertNull($missingPrice[1]['milesPerGallon']);
    }
}
