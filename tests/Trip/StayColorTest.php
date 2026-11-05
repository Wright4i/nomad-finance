<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Trip\StayDayChoice;
use App\Trip\StayDayFill;
use App\Trip\StayTone;
use PHPUnit\Framework\TestCase;

final class StayColorTest extends TestCase
{
    public function testColorsRepeatSoNeighborsDiffer(): void
    {
        self::assertSame(
            [
                'poppy', 'lagoon', 'berry', 'citrus', 'violet', 'apricot',
                'tide', 'blossom', 'fern', 'lilac', 'lemon', 'sea',
            ],
            StayTone::SEQUENCE,
        );
        self::assertCount(12, array_unique(StayTone::SEQUENCE));
        $painted = [];
        for ($index = 0; $index < 24; $index++) {
            $painted[] = StayTone::name($index);
        }
        self::assertSame(
            [...StayTone::SEQUENCE, ...StayTone::SEQUENCE],
            $painted,
        );
        for ($index = 1; $index < count($painted); $index++) {
            self::assertNotSame($painted[$index - 1], $painted[$index]);
        }
        self::assertNotSame(StayTone::name(count(StayTone::SEQUENCE) - 1), StayTone::name(count(StayTone::SEQUENCE)));
    }

    public function testAHandoffClickSelectsTheArrivingStay(): void
    {
        $departing = ['id' => 1, 'arriveOn' => '2026-11-01', 'departOn' => '2026-11-05'];
        $arriving = ['id' => 2, 'arriveOn' => '2026-11-05', 'departOn' => '2026-11-08'];

        self::assertSame(2, StayDayChoice::id([$departing, $arriving], '2026-11-05'));
        self::assertSame(2, StayDayChoice::id([$arriving, $departing], '2026-11-05'));
        self::assertSame(2, StayDayChoice::id([
            ['id' => 1, 'arriveOn' => '2026-11-01', 'departOn' => '2026-11-05'],
            ['id' => 2, 'arriveOn' => '2026-11-05', 'departOn' => '2026-11-05'],
        ], '2026-11-05'));
        self::assertSame(1, StayDayChoice::id([$departing, $arriving], '2026-11-01'));
        self::assertSame(1, StayDayChoice::id([$departing, $arriving], '2026-11-03'));
        self::assertSame(1, StayDayChoice::id([$departing], '2026-11-05'));
        self::assertSame(2, StayDayChoice::id([$departing, $arriving], '2026-11-06'));
        self::assertSame(2, StayDayChoice::id([$departing, $arriving], '2026-11-08'));
        self::assertNull(StayDayChoice::id([$departing, $arriving], '2026-10-31'));
        self::assertNull(StayDayChoice::id([], '2026-11-05'));
    }

    public function testArrivalIsTheUpperRightAndDepartureIsTheLowerLeft(): void
    {
        $stays = [
            ['arriveOn' => '2026-11-03', 'departOn' => '2026-11-24', 'color' => 'coral'],
        ];

        self::assertSame(['arrive' => null, 'depart' => null], StayDayFill::halves($stays, '2026-11-02'));
        self::assertSame(['arrive' => 'coral', 'depart' => null], StayDayFill::halves($stays, '2026-11-03'));
        self::assertSame(['arrive' => 'coral', 'depart' => 'coral'], StayDayFill::halves($stays, '2026-11-10'));
        self::assertSame(['arrive' => null, 'depart' => 'coral'], StayDayFill::halves($stays, '2026-11-24'));
        self::assertSame(
            ['arrive' => 'rose', 'depart' => 'rose'],
            StayDayFill::halves([
                ['arriveOn' => '2026-11-05', 'departOn' => '2026-11-05', 'color' => 'rose'],
            ], '2026-11-05'),
        );
    }

    public function testAHandoffSplitsTheTwoColors(): void
    {
        $stays = [
            ['arriveOn' => '2026-11-01', 'departOn' => '2026-11-05', 'color' => 'coral'],
            ['arriveOn' => '2026-11-05', 'departOn' => '2026-11-08', 'color' => 'rose'],
        ];

        self::assertSame(
            ['arrive' => 'rose', 'depart' => 'coral'],
            StayDayFill::halves($stays, '2026-11-05'),
        );
        self::assertSame(
            ['arrive' => 'coral', 'depart' => 'coral'],
            StayDayFill::halves($stays, '2026-11-03'),
        );
        self::assertSame(
            ['arrive' => 'rose', 'depart' => 'rose'],
            StayDayFill::halves($stays, '2026-11-06'),
        );
    }

    public function testAnEarlierStayKeepsAHalfItAlreadyClaimed(): void
    {
        $stays = [
            ['arriveOn' => '2026-11-01', 'departOn' => '2026-11-05', 'color' => 'coral'],
            ['arriveOn' => '2026-11-05', 'departOn' => '2026-11-05', 'color' => 'rose'],
        ];

        self::assertSame(
            ['arrive' => 'rose', 'depart' => 'coral'],
            StayDayFill::halves($stays, '2026-11-05'),
        );
    }
}
