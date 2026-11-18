<?php

declare(strict_types=1);

namespace App\Trip;

final class Fuel
{
    /** @return array{miles:?int,milesPerGallon:?string,costMinor:?int,priceMinor:?int} */
    public static function estimate(?int $miles, ?int $mpgTenths, ?int $priceMinor): array
    {
        if ($miles === null || $miles < 0) {
            return ['miles' => null, 'milesPerGallon' => null, 'costMinor' => null, 'priceMinor' => null];
        }
        $mpg = self::label($mpgTenths);
        if ($mpgTenths === null || $mpgTenths <= 0 || $priceMinor === null || $priceMinor < 1) {
            return ['miles' => $miles, 'milesPerGallon' => $mpg, 'costMinor' => null, 'priceMinor' => null];
        }

        return [
            'miles' => $miles,
            'milesPerGallon' => $mpg,
            'costMinor' => intdiv($miles * $priceMinor * 10 + intdiv($mpgTenths, 2), $mpgTenths),
            'priceMinor' => $priceMinor,
        ];
    }

    /** @param list<array{miles:?int,gallons:?string,milesPerGallon:?string}> $stretches */
    public static function pooled(array $stretches, int $minTenths, int $maxTenths): ?int
    {
        $miles = 0;
        $milli = 0;
        foreach ($stretches as $stretch) {
            $sample = $stretch['milesPerGallon'] ?? null;
            $legMiles = $stretch['miles'] ?? null;
            $gallons = $stretch['gallons'] ?? null;
            if (!is_string($sample) || !is_int($legMiles) || $legMiles <= 0 || !is_string($gallons)) {
                continue;
            }
            $tenths = self::tenths($sample);
            $gallonMilli = self::milli($gallons);
            if ($tenths === null || $gallonMilli === null || $gallonMilli <= 0) {
                continue;
            }
            if ($tenths < $minTenths || $tenths > $maxTenths) {
                continue;
            }
            $miles += $legMiles;
            $milli += $gallonMilli;
        }
        if ($miles <= 0 || $milli <= 0) {
            return null;
        }

        return intdiv($miles * 10000 + intdiv($milli, 2), $milli);
    }

    public static function midpoint(int $minTenths, int $maxTenths): int
    {
        return intdiv($minTenths + $maxTenths + 1, 2);
    }

    public static function label(?int $mpgTenths): ?string
    {
        if ($mpgTenths === null || $mpgTenths <= 0) {
            return null;
        }

        return intdiv($mpgTenths, 10) . '.' . ($mpgTenths % 10);
    }

    public static function tenths(string $value): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 10 + (int) ($matches[2] ?? 0);
    }

    private static function milli(string $gallons): ?int
    {
        if (preg_match('/^(\d+)\.(\d{3})$/', $gallons, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 1000 + (int) $matches[2];
    }
}
