<?php

declare(strict_types=1);

namespace App\Money;

final class Converter
{
    public static function assertRate(string $rate): string
    {
        $rate = trim($rate);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $rate) || bccomp($rate, '0', 8) !== 1) {
            throw new \InvalidArgumentException('Enter a rate greater than zero, with up to 8 decimal places.');
        }

        return $rate;
    }

    public static function convert(int $minor, int $fromExponent, int $toExponent, string $rate): int
    {
        if ($minor < 0) {
            throw new \InvalidArgumentException('Amount must be zero or greater.');
        }

        $scale = 12;
        $value = bcmul((string) $minor, $rate, $scale);
        $delta = $toExponent - $fromExponent;
        if ($delta > 0) {
            $value = bcmul($value, bcpow('10', (string) $delta, 0), $scale);
        } elseif ($delta < 0) {
            $value = bcdiv($value, bcpow('10', (string) (-$delta), 0), $scale);
        }

        return self::roundHalfUp($value);
    }

    private static function roundHalfUp(string $value): int
    {
        if (!str_contains($value, '.')) {
            return (int) $value;
        }

        [$whole, $frac] = explode('.', $value, 2);
        if ($whole === '') {
            $whole = '0';
        }
        if ($frac !== '' && $frac[0] >= '5') {
            $whole = bcadd($whole, '1', 0);
        }

        return (int) $whole;
    }
}
