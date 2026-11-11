<?php

declare(strict_types=1);

namespace App\Homepage;

/**
 * City and state for a stay, matching the calendar day chip caption.
 *
 * @param array{placeName?:string,city?:?string,region?:?string}|null $stay
 */
final class StayCaption
{
    public static function label(?array $stay): string
    {
        if ($stay === null) {
            return '';
        }
        $city = trim((string) ($stay['city'] ?? ''));
        $region = trim((string) ($stay['region'] ?? ''));
        if ($city !== '' && $region !== '') {
            return $city . ', ' . $region;
        }
        if ($city !== '') {
            return $city;
        }
        if ($region !== '') {
            return $region;
        }

        return trim((string) ($stay['placeName'] ?? ''));
    }
}
