<?php

declare(strict_types=1);

namespace App\Trip;

/**
 * Arrival order paints stays poppy, lagoon, berry, citrus, violet, apricot,
 * tide, blossom, fern, lilac, lemon, sea, then again.
 * Neighbors in that order do not share a color, including sea back to poppy.
 */
final class StayTone
{
    /** @var list<string> */
    public const SEQUENCE = [
        'poppy',
        'lagoon',
        'berry',
        'citrus',
        'violet',
        'apricot',
        'tide',
        'blossom',
        'fern',
        'lilac',
        'lemon',
        'sea',
    ];

    public static function name(int $index): string
    {
        $count = count(self::SEQUENCE);
        $slot = $index % $count;
        if ($slot < 0) {
            $slot += $count;
        }

        return self::SEQUENCE[$slot];
    }
}
