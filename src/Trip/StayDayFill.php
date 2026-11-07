<?php

declare(strict_types=1);

namespace App\Trip;

/**
 * How a stay's color sits on one calendar day.
 * The split runs from the top-left corner to the bottom-right.
 * Arrival is the upper-right half. Departure is the lower-left half.
 * A day strictly inside the stay fills both halves.
 * A stay that arrives while another is still there takes the upper-right half.
 * The stay already there keeps the lower-left half.
 * The first arrival on a day is the one that shows.
 */
final class StayDayFill
{
    /**
     * @param list<array{arriveOn:string,departOn:string,color:string}> $stays
     * @return array{arrive:?string,depart:?string}
     */
    public static function halves(array $stays, string $day): array
    {
        $covering = [];
        foreach ($stays as $stay) {
            if ($day >= $stay['arriveOn'] && $day <= $stay['departOn']) {
                $covering[] = $stay;
            }
        }
        $ongoing = null;
        foreach ($covering as $stay) {
            if ($day !== $stay['arriveOn'] && $day !== $stay['departOn']) {
                $ongoing = $stay['color'];
                break;
            }
        }
        $arrive = null;
        $depart = null;
        foreach ($covering as $stay) {
            if ($day === $stay['arriveOn'] && $arrive === null) {
                $arrive = $stay['color'];
            }
            $sameDay = $day === $stay['arriveOn'] && $day === $stay['departOn'];
            if ($day === $stay['departOn'] && $depart === null && !($sameDay && $ongoing !== null)) {
                $depart = $stay['color'];
            }
        }
        if ($arrive !== null && $depart === null && $ongoing !== null) {
            $depart = $ongoing;
        }
        if ($depart !== null && $arrive === null && $ongoing !== null) {
            $arrive = $ongoing;
        }
        if ($arrive === null && $depart === null && $ongoing !== null) {
            $arrive = $ongoing;
            $depart = $ongoing;
        }

        return ['arrive' => $arrive, 'depart' => $depart];
    }
}
