<?php

declare(strict_types=1);

namespace App\Trip;

final class StayDayOverlap
{
    /**
     * @param list<array{arriveOn:string,departOn:string}> $stays
     * @return array{arriveOn:string,departOn:string}|null
     */
    public static function nested(array $stays, string $day): ?array
    {
        $inside = [];
        foreach ($stays as $stay) {
            if ($day > $stay['arriveOn'] && $day < $stay['departOn']) {
                $inside[] = $stay;
            }
        }
        if ($inside === []) {
            return null;
        }
        usort($inside, static function (array $a, array $b): int {
            $cmp = strcmp($b['arriveOn'], $a['arriveOn']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['departOn'], $b['departOn']);
        });

        return $inside[0];
    }
}
