<?php

declare(strict_types=1);

namespace App\Trip;

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
        $nested = StayDayOverlap::nested($covering, $day);
        $ongoing = $nested === null ? null : $nested['color'];
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
