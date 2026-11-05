<?php

declare(strict_types=1);

namespace App\Trip;

use App\Support\Dates;

/**
 * Days of a stay. Arrival and departure both count.
 * A label on the calendar. It assigns nothing and posts nothing.
 */
final class StaySpan
{
    public static function covers(string $arriveOn, string $departOn, string $day): bool
    {
        return $arriveOn <= $day && $day <= $departOn;
    }

    /** @return list<string> */
    public static function days(string $arriveOn, string $departOn): array
    {
        if ($departOn < $arriveOn) {
            return [];
        }
        $cursor = Dates::parse($arriveOn);
        $end = Dates::parse($departOn)->format('Y-m-d');
        $listed = [];
        while ($cursor->format('Y-m-d') <= $end) {
            $listed[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $listed;
    }
}
