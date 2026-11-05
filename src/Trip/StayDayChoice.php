<?php

declare(strict_types=1);

namespace App\Trip;

/**
 * The stay a calendar day opens.
 * A handoff opens the stay that is arriving.
 * One stay on the day opens that stay.
 * A day inside a stay opens the stay that covers it.
 */
final class StayDayChoice
{
    /**
     * @param list<array{id:int,arriveOn:string,departOn:string}> $stays
     */
    public static function id(array $stays, string $day): ?int
    {
        $open = null;
        foreach ($stays as $stay) {
            if ($day < $stay['arriveOn'] || $day > $stay['departOn']) {
                continue;
            }
            if ($stay['arriveOn'] === $day) {
                return $stay['id'];
            }
            if ($open === null) {
                $open = $stay['id'];
            }
        }

        return $open;
    }
}
