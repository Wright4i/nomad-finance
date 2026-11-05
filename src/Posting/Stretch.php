<?php

declare(strict_types=1);

namespace App\Posting;

final class Stretch
{
    /**
     * @param list<array{id:int,amountMinor:int,odometerMiles:?int,pricePerGallonMinor:?int,vehicleId?:?int}> $rows
     *     Chronological order. Readings chain per vehicle when vehicleId is present.
     * @return array<int, array{miles:?int,gallons:?string,milesPerGallon:?string,note:?string}>
     */
    public static function decorate(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[self::chainKey($row)][] = $row;
        }
        $out = [];
        foreach ($groups as $group) {
            $out += self::chain($group);
        }

        return $out;
    }

    /** @param array{id:int,vehicleId?:?int} $row */
    private static function chainKey(array $row): string
    {
        if (!array_key_exists('vehicleId', $row)) {
            return 'shared';
        }
        if ($row['vehicleId'] === null) {
            return 'none-' . $row['id'];
        }

        return 'v' . $row['vehicleId'];
    }

    /**
     * @param list<array{id:int,amountMinor:int,odometerMiles:?int,pricePerGallonMinor:?int}> $rows
     * @return array<int, array{miles:?int,gallons:?string,milesPerGallon:?string,note:?string}>
     */
    private static function chain(array $rows): array
    {
        $out = [];
        $previous = null;
        foreach ($rows as $row) {
            $odometer = $row['odometerMiles'];
            $gallons = self::gallons($row['amountMinor'], $row['pricePerGallonMinor']);
            if ($odometer === null) {
                $out[$row['id']] = [
                    'miles' => null,
                    'gallons' => $gallons,
                    'milesPerGallon' => null,
                    'note' => null,
                ];
                continue;
            }
            if ($previous === null) {
                $out[$row['id']] = [
                    'miles' => null,
                    'gallons' => $gallons,
                    'milesPerGallon' => null,
                    'note' => null,
                ];
                $previous = $odometer;
                continue;
            }
            $miles = $odometer - $previous;
            $previous = $odometer;
            if ($miles <= 0) {
                $out[$row['id']] = [
                    'miles' => null,
                    'gallons' => $gallons,
                    'milesPerGallon' => null,
                    'note' => 'The reading did not advance.',
                ];
                continue;
            }
            $out[$row['id']] = [
                'miles' => $miles,
                'gallons' => $gallons,
                'milesPerGallon' => self::mpg($miles, $row['amountMinor'], $row['pricePerGallonMinor']),
                'note' => null,
            ];
        }

        return $out;
    }

    public static function gallons(int $amount, ?int $price): ?string
    {
        if ($price === null || $price <= 0) {
            return null;
        }
        $milli = intdiv($amount * 1000 + intdiv($price, 2), $price);
        $whole = intdiv($milli, 1000);
        $frac = $milli % 1000;

        return $whole . '.' . str_pad((string) $frac, 3, '0', STR_PAD_LEFT);
    }

    private static function mpg(int $miles, int $amount, ?int $price): ?string
    {
        if ($price === null || $price <= 0 || $amount <= 0) {
            return null;
        }
        $milli = intdiv($amount * 1000 + intdiv($price, 2), $price);
        if ($milli <= 0) {
            return null;
        }
        $deci = intdiv($miles * 10000 + intdiv($milli, 2), $milli);
        $whole = intdiv($deci, 10);
        $frac = $deci % 10;

        return $whole . '.' . $frac;
    }
}
