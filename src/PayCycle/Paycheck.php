<?php

declare(strict_types=1);

namespace App\PayCycle;

use App\Support\Dates;

final class Paycheck
{
    /** @var array<string, int> */
    private const PER_YEAR = [
        'weekly' => 52,
        'biweekly' => 26,
        'semimonthly' => 24,
        'monthly' => 12,
    ];

    public static function amount(string $kind, string $length, int $rateMinor, ?int $hoursHundredths): ?int
    {
        if ($rateMinor < 1) {
            return null;
        }
        if ($kind === 'fixed') {
            return $rateMinor;
        }
        if ($kind === 'hourly') {
            if ($hoursHundredths === null || $hoursHundredths < 1) {
                return null;
            }

            return intdiv($rateMinor * $hoursHundredths + 50, 100);
        }
        if ($kind !== 'salary' || !isset(self::PER_YEAR[$length])) {
            return null;
        }

        $periods = self::PER_YEAR[$length];

        return intdiv($rateMinor + intdiv($periods, 2), $periods);
    }

    public static function monthlyAmount(?int $paycheckMinor, string $length): ?int
    {
        if ($paycheckMinor === null || $paycheckMinor < 1) {
            return null;
        }
        $perYear = self::PER_YEAR[$length] ?? null;
        if ($perYear === null) {
            return null;
        }

        return intdiv($paycheckMinor * $perYear + 6, 12);
    }

    /** @return array{weekdays:int,month:string,hoursHundredths:int,paycheckMinor:int}|null */
    public static function monthlyWeekday(string $payday, int $rateMinor, int $hoursPerDayHundredths): ?array
    {
        if ($rateMinor < 1 || $hoursPerDayHundredths < 1 || $hoursPerDayHundredths > 100000) {
            return null;
        }
        try {
            $date = Dates::parse($payday);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $prior = $date->modify('first day of last month');
        $weekdays = self::weekdaysIn($prior);
        $hoursHundredths = $weekdays * $hoursPerDayHundredths;
        $paycheck = self::amount('hourly', 'monthly', $rateMinor, $hoursHundredths);
        if ($paycheck === null) {
            return null;
        }

        return [
            'weekdays' => $weekdays,
            'month' => $prior->format('Y-m'),
            'hoursHundredths' => $hoursHundredths,
            'paycheckMinor' => $paycheck,
        ];
    }

    public static function hoursHundredths(string $value): ?int
    {
        $trimmed = trim($value);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $trimmed) !== 1) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $trimmed, 2), 2, '');
        $hundredths = ((int) $whole) * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
        if ($hundredths < 1 || $hundredths > 100000) {
            return null;
        }

        return $hundredths;
    }

    /** @return array{ */
    public static function weekdaySplit(string $payday, int $secondDay, int $rateMinor): ?array
    {
        if ($rateMinor < 1 || $secondDay < 1 || $secondDay > 31) {
            return null;
        }
        try {
            $date = Dates::parse($payday);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $paydayDay = (int) $date->format('j');
        if ($paydayDay === $secondDay) {
            return null;
        }
        $weekdays = self::weekdaysIn($date->modify('first day of last month'));
        $gross = $rateMinor * $weekdays * 8;
        $shares = self::shares($gross, $paydayDay, $secondDay);

        return [
            'weekdays' => $weekdays,
            'hours' => $weekdays * 8,
            'grossMinor' => $gross,
            'earlierDay' => $shares['earlierDay'],
            'earlierMinor' => $shares['earlierMinor'],
            'laterDay' => $shares['laterDay'],
            'laterMinor' => $shares['laterMinor'],
        ];
    }

    /** @return array{earlierDay:int,earlierMinor:int,laterDay:int,laterMinor:int} */
    public static function shares(int $grossMinor, int $dayA, int $dayB): array
    {
        $earlierMinor = intdiv($grossMinor + 1, 2);

        return [
            'earlierDay' => min($dayA, $dayB),
            'earlierMinor' => $earlierMinor,
            'laterDay' => max($dayA, $dayB),
            'laterMinor' => $grossMinor - $earlierMinor,
        ];
    }

    private static function weekdaysIn(\DateTimeImmutable $firstOfMonth): int
    {
        $count = 0;
        $cursor = $firstOfMonth;
        $month = $firstOfMonth->format('m');
        while ($cursor->format('m') === $month) {
            if ((int) $cursor->format('N') <= 5) {
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }
}
