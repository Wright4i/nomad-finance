<?php

declare(strict_types=1);

namespace App\Trip;

/**
 * Which stays can trade places on the route.
 * A later stay that ends after the earlier one is already second.
 * A stay that arrives and departs during another can trade places with the stay beside it.
 * A handoff shares a date and does not trade places.
 */
final class StayRoute
{
    /**
     * @param array{arriveOn:string,departOn:string} $stay
     * @param array{arriveOn:string,departOn:string} $other
     */
    public static function orderable(array $stay, array $other): bool
    {
        if ($stay['arriveOn'] >= $other['departOn'] || $other['arriveOn'] >= $stay['departOn']) {
            return false;
        }
        $stayFirst = self::startsFirst($stay, $other);
        $earlier = $stayFirst ? $stay : $other;
        $later = $stayFirst ? $other : $stay;
        if ($later['arriveOn'] === $earlier['arriveOn'] && $later['departOn'] === $earlier['departOn']) {
            return true;
        }

        return $later['departOn'] <= $earlier['departOn'];
    }

    /**
     * @param array{arriveOn:string,departOn:string} $stay
     * @param array{arriveOn:string,departOn:string} $other
     */
    private static function startsFirst(array $stay, array $other): bool
    {
        if ($stay['arriveOn'] !== $other['arriveOn']) {
            return $stay['arriveOn'] < $other['arriveOn'];
        }

        return $stay['departOn'] <= $other['departOn'];
    }
}
