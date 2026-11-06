<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * Monthly interest from the annual rate on an account.
 * That rate is interest in basis points: 500 is 5 percent a year.
 *
 * Interest lost is credit and loan. Each balance times the rate divided by 12, half up to the cent.
 * Interest gained is savings. The monthly rate is (1 + rate) to the power of 1/12, minus 1,
 * applied to the balance, half up to the cent.
 * A blank rate, a zero rate, or a balance that is not positive contributes nothing.
 * Fuel, checking, cash, and other stay out.
 */
final class MonthlyInterest
{
    /**
     * @param list<array<string, mixed>> $accounts
     * @return array{interestLostMinor:int,interestGainedMinor:int}
     */
    public static function summarize(array $accounts): array
    {
        $lost = 0;
        $gained = 0;
        foreach ($accounts as $account) {
            $type = is_string($account['type'] ?? null) ? $account['type'] : '';
            $balance = (int) ($account['balanceMinor'] ?? 0);
            $stored = $account['interestBps'] ?? null;
            $bps = is_int($stored) || (is_string($stored) && is_numeric($stored)) ? (int) $stored : 0;
            if ($type === 'credit' || $type === 'loan') {
                $lost += self::aprMonth($balance, $bps);
            } elseif ($type === 'savings') {
                $gained += self::apyMonth($balance, $bps);
            }
        }

        return [
            'interestLostMinor' => $lost,
            'interestGainedMinor' => $gained,
        ];
    }

    private static function aprMonth(int $balance, int $bps): int
    {
        if ($balance <= 0 || $bps <= 0) {
            return 0;
        }
        $numerator = bcmul((string) $balance, (string) $bps, 0);

        return (int) bcdiv(bcadd($numerator, '60000', 0), '120000', 0);
    }

    private static function apyMonth(int $balance, int $bps): int
    {
        if ($balance <= 0 || $bps <= 0) {
            return 0;
        }
        $scale = 28;
        $onePlus = bcdiv(bcadd('10000', (string) $bps, 0), '10000', $scale);
        $monthly = bcsub(self::twelfthRoot($onePlus, $scale), '1', $scale);

        return self::roundHalfUp(bcmul((string) $balance, $monthly, $scale));
    }

    private static function twelfthRoot(string $base, int $scale): string
    {
        $work = $scale + 8;
        $guess = '1';
        for ($i = 0; $i < 60; $i++) {
            $power = bcpow($guess, '11', $work);
            $next = bcdiv(
                bcadd(bcmul('11', $guess, $work), bcdiv($base, $power, $work), $work),
                '12',
                $work,
            );
            if (bccomp($next, $guess, $scale) === 0) {
                return bcadd($next, '0', $scale);
            }
            $guess = $next;
        }

        return bcadd($guess, '0', $scale);
    }

    private static function roundHalfUp(string $value): int
    {
        if (str_starts_with($value, '-')) {
            return 0;
        }
        if (!str_contains($value, '.')) {
            return (int) $value;
        }
        [$whole, $fraction] = explode('.', $value, 2);
        if ($whole === '') {
            $whole = '0';
        }
        if ($fraction !== '' && $fraction[0] >= '5') {
            $whole = bcadd($whole, '1', 0);
        }

        return (int) $whole;
    }
}
