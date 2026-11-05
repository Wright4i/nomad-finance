<?php

declare(strict_types=1);

namespace App\Trip;

/**
 * How a stay's color sits on one calendar day.
 * The split runs from the top-left corner to the bottom-right.
 * Arrival is the upper-right half. Departure is the lower-left half.
 * A day strictly inside the stay fills both halves.
 * A half already claimed by an earlier stay stays with that stay.
 */
final class StayDayFill
{
    /**
     * @param list<array{arriveOn:string,departOn:string,color:string}> $stays
     * @return array{arrive:?string,depart:?string}
     */
    public static function halves(array $stays, string $day): array
    {
        $arrive = null;
        $depart = null;
        foreach ($stays as $stay) {
            $arriveOn = $stay['arriveOn'];
            $departOn = $stay['departOn'];
            if ($day < $arriveOn || $day > $departOn) {
                continue;
            }
            $color = $stay['color'];
            if ($day === $arriveOn && $arrive === null) {
                $arrive = $color;
            }
            if ($day === $departOn && $depart === null) {
                $depart = $color;
            }
            if ($day !== $arriveOn && $day !== $departOn) {
                $arrive = $color;
                $depart = $color;
            }
        }

        return ['arrive' => $arrive, 'depart' => $depart];
    }
}
