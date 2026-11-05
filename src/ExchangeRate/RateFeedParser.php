<?php

declare(strict_types=1);

namespace App\ExchangeRate;

final class RateFeedParser
{
    /**
     * The open feed quotes how many units of each currency equal 1 unit of the base.
     * The books store the inverse: base units per 1 unit of the other currency.
     *
     * @return array<string, string>
     */
    public static function quotes(string $body, string $base): array
    {
        $base = strtoupper($base);
        if (!preg_match('/"result"\s*:\s*"success"/', $body)) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }
        if (!preg_match('/"rates"\s*:\s*\{([^}]*)\}/', $body, $match)) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        preg_match_all('/"([A-Z]{3})"\s*:\s*(\d+(?:\.\d+)?)/', $match[1], $pairs, PREG_SET_ORDER);
        $quotes = [];
        foreach ($pairs as $pair) {
            if ($pair[1] === $base) {
                continue;
            }
            $quotes[$pair[1]] = self::invert($pair[2]);
        }
        if ($quotes === []) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        return $quotes;
    }

    private static function invert(string $foreignPerBase): string
    {
        if (bccomp($foreignPerBase, '0', 12) !== 1) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        $wide = bcdiv('1', $foreignPerBase, 12);
        if (!str_contains($wide, '.')) {
            return $wide;
        }

        [$whole, $frac] = explode('.', $wide, 2);
        $frac = str_pad($frac, 12, '0');
        $keep = $whole . '.' . substr($frac, 0, 8);
        if ((int) $frac[8] >= 5) {
            $keep = bcadd($keep, '0.00000001', 8);
        }
        $keep = rtrim(rtrim($keep, '0'), '.');
        if ($keep === '' || bccomp($keep, '0', 8) !== 1) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        return $keep;
    }
}
