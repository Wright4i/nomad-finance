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

    /**
     * One fill-up per vehicle and odometer. Miles are this reading minus the previous
     * different reading. Gallons add each charge. A missing price hides miles per gallon.
     *
     * @param list<array{id:int,amountMinor:int,odometerMiles:?int,pricePerGallonMinor:?int,vehicleId?:?int}> $rows
     * @return list<array{odometerMiles:int,miles:?int,gallons:?string,milesPerGallon:?string}>
     */
    public static function fillUps(array $rows, ?int $originMiles = null): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[self::chainKey($row)][] = $row;
        }
        $out = [];
        foreach ($groups as $group) {
            foreach (self::fillChain($group, $originMiles) as $fill) {
                $out[] = $fill;
            }
        }

        return $out;
    }

    /**
     * @param list<array{id:int,amountMinor:int,odometerMiles:?int,pricePerGallonMinor:?int}> $rows
     * @return list<array{odometerMiles:int,miles:?int,gallons:?string,milesPerGallon:?string}>
     */
    private static function fillChain(array $rows, ?int $originMiles): array
    {
        $order = [];
        $buckets = [];
        foreach ($rows as $row) {
            if ($row['odometerMiles'] === null) {
                continue;
            }
            $key = (string) $row['odometerMiles'];
            if (!isset($buckets[$key])) {
                $order[] = $key;
                $buckets[$key] = [];
            }
            $buckets[$key][] = $row;
        }
        usort($order, static fn (string $left, string $right): int => (int) $left <=> (int) $right);

        $out = [];
        $previous = null;
        foreach ($order as $key) {
            $odometer = (int) $key;
            $missingPrice = false;
            $gallonMilli = 0;
            foreach ($buckets[$key] as $charge) {
                $price = $charge['pricePerGallonMinor'];
                if ($price === null || $price <= 0) {
                    $missingPrice = true;
                    continue;
                }
                $gallonMilli += intdiv($charge['amountMinor'] * 1000 + intdiv($price, 2), $price);
            }
            $miles = null;
            if ($previous === null && $originMiles !== null && $odometer >= $originMiles) {
                $miles = $odometer - $originMiles;
            } elseif ($previous !== null) {
                $delta = $odometer - $previous;
                if ($delta > 0) {
                    $miles = $delta;
                }
            }
            $previous = $odometer;
            $gallons = null;
            $mpg = null;
            if (!$missingPrice && $gallonMilli > 0) {
                $whole = intdiv($gallonMilli, 1000);
                $frac = $gallonMilli % 1000;
                $gallons = $whole . '.' . str_pad((string) $frac, 3, '0', STR_PAD_LEFT);
                if ($miles !== null) {
                    $deci = intdiv($miles * 10000 + intdiv($gallonMilli, 2), $gallonMilli);
                    $mpg = intdiv($deci, 10) . '.' . ($deci % 10);
                }
            }
            $out[] = [
                'odometerMiles' => $odometer,
                'miles' => $miles,
                'gallons' => $gallons,
                'milesPerGallon' => $mpg,
            ];
        }

        return $out;
    }
}
