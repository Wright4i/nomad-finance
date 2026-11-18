<?php

declare(strict_types=1);

namespace App\Homepage;

use App\Support\Dates;

final class EntryHint
{
    public static function forDay(string $startOn, string $endOn, string $today): string
    {
        if ($startOn === $endOn) {
            return '';
        }
        if ($startOn === $today) {
            return 'through ' . self::short($endOn);
        }
        if ($endOn === $today) {
            return 'last day';
        }

        return 'through ' . self::short($endOn);
    }

    private static function short(string $iso): string
    {
        return Dates::parse($iso)->format('M j');
    }
}
