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

    /**
     * The paycheck for one period, in minor units.
     * Salary on a regular length is the annual rate split across that many paydays.
     * An irregular salary is not split. Fixed pay is the rate. Hourly pay is the rate times the hours.
     */
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

    /**
     * Hours for a monthly weekday.
     * Monday through Friday in the calendar month before the payday, times the hours per weekday, times the hourly rate.
     * One paycheck, half up. It is not split across two days. It does not assign money or post it.
     *
     * @return array{weekdays:int,month:string,hoursHundredths:int,paycheckMinor:int}|null
     */
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

    /**
     * Weekday hours for one semimonthly pair.
     * Count is Mon–Fri in the calendar month before the payday's month, times 8, times the hourly rate.
     * The gross is split across the two paydays. Half up, and the odd minor unit stays on the earlier day.
     * This is a preview. It does not assign money or post it.
     *
     * @return array{
     *   weekdays:int,hours:int,grossMinor:int,
     *   earlierDay:int,earlierMinor:int,laterDay:int,laterMinor:int
     * }|null
     */
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

    /**
     * Split a gross in minor units across two days of the month.
     * Half up. The odd minor unit goes to the earlier day.
     *
     * @return array{earlierDay:int,earlierMinor:int,laterDay:int,laterMinor:int}
     */
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
