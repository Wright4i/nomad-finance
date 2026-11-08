<?php

declare(strict_types=1);

namespace App\Trip;

/**
 * The stay a calendar day opens.
 * A handoff opens the stay that is arriving.
 * One stay on the day opens that stay.
 * A day inside a stay opens that stay.
 * A day inside overlapping stays opens the nested stay, the latest arrival still there.
 */
final class StayDayChoice
{
    /**
     * @param list<array{id:int,arriveOn:string,departOn:string}> $stays
     */
    public static function id(array $stays, string $day): ?int
    {
        $covering = [];
        foreach ($stays as $stay) {
            if ($day < $stay['arriveOn'] || $day > $stay['departOn']) {
                continue;
            }
            $covering[] = $stay;
            if ($stay['arriveOn'] === $day) {
                return $stay['id'];
            }
        }
        if ($covering === []) {
            return null;
        }
        foreach ($covering as $stay) {
            if ($stay['departOn'] === $day) {
                return $stay['id'];
            }
        }
        $nested = StayDayOverlap::nested($covering, $day);
        if ($nested !== null) {
            return $nested['id'];
        }

        return $covering[0]['id'];
    }
}
