<?php

declare(strict_types=1);

namespace App\AcctPeriod;

use App\Support\Dates;

/**
 * The accounting period: the one window for Planner, Calendar, Trips, Bills, and Budget.
 * A week is 7 days. A month runs through the day before the same day N months later.
 * When that day does not exist, the anniversary clamps to the last day, then steps back one.
 * Named apart from a pay period.
 */
final class AcctPeriod
{
    public const LENGTHS = ['1 week', '2 weeks', '4 weeks', '1 month', '2 months', '3 months'];

    /** Previous and Next both stop this many periods from the one that contains today. */
    public const REACH = 11;

    public static function valid(string $length): bool
    {
        return in_array($length, self::LENGTHS, true);
    }

    public static function defaultStart(string $today): string
    {
        return Dates::parse($today)->format('Y-m-01');
    }

    /** Inclusive last day of the accounting period that starts on $start. */
    public static function end(string $start, string $length): string
    {
        return Dates::parse(self::shift($start, $length, 1))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Move the start by the length. Positive steps go forward and keep the same stop.
     */
    public static function shift(string $start, string $length, int $steps): string
    {
        if (!self::valid($length)) {
            throw new \InvalidArgumentException('Choose an accounting period length.');
        }
        $start = Dates::parse($start)->format('Y-m-d');
        if ($steps === 0) {
            return $start;
        }
        $weeks = self::weeks($length);
        if ($weeks !== null) {
            $days = $weeks * 7 * $steps;

            return Dates::parse($start)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        }

        return self::addMonths($start, (int) self::months($length) * $steps);
    }

    /**
     * Steps from $start to the period of $length that contains $today.
     * Positive when $start is earlier than that period.
     */
    public static function stepsFromToday(string $start, string $length, string $today): int
    {
        if (!self::valid($length)) {
            throw new \InvalidArgumentException('Choose an accounting period length.');
        }
        $start = Dates::parse($start)->format('Y-m-d');
        $today = Dates::parse($today)->format('Y-m-d');
        $weeks = self::weeks($length);
        if ($weeks !== null) {
            return self::floorDiv(self::daysBetween($start, $today), $weeks * 7);
        }
        if (self::holds($start, $length, $today)) {
            return 0;
        }

        $step = 0;
        if ($today > $start) {
            do {
                $step++;
                if ($step > 24000) {
                    return $step;
                }
            } while ($today > self::boundary($start, $length, $step));

            return $step;
        }

        do {
            $step--;
            if ($step < -24000) {
                return $step;
            }
        } while (self::shift($start, $length, $step) > $today);

        return $step;
    }

    public static function withinReach(string $start, string $length, string $today): bool
    {
        $steps = self::stepsFromToday($start, $length, $today);

        return $steps >= -self::REACH && $steps <= self::REACH;
    }

    /** current when today is inside, closed when the accounting period has ended, future when it has not started. */
    public static function phase(string $start, string $end, string $today): string
    {
        if ($end < $today) {
            return 'closed';
        }
        if ($start > $today) {
            return 'future';
        }

        return 'current';
    }

    /**
     * @return array{start:string,end:string,length:string,phase:string,editable:bool,upcoming:bool,label:string}
     */
    public static function describe(string $start, string $length, string $today): array
    {
        $start = Dates::parse($start)->format('Y-m-d');
        $length = self::valid($length) ? $length : '1 month';
        $end = self::end($start, $length);
        $phase = self::phase($start, $end, $today);

        return [
            'start' => $start,
            'end' => $end,
            'length' => $length,
            'phase' => $phase,
            'editable' => $phase !== 'closed',
            'upcoming' => $phase === 'future',
            'label' => self::label($start, $end),
        ];
    }

    /** The pager's dates, such as Oct 16 – Nov 15. */
    public static function label(string $start, string $end): string
    {
        return self::chip($start) . ' – ' . self::chip($end);
    }

    private static function chip(string $iso): string
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $date = Dates::parse($iso);

        return $months[(int) $date->format('n') - 1] . ' ' . (int) $date->format('j');
    }

    private static function weeks(string $length): ?int
    {
        return match ($length) {
            '1 week' => 1,
            '2 weeks' => 2,
            '4 weeks' => 4,
            default => null,
        };
    }

    private static function months(string $length): ?int
    {
        return match ($length) {
            '1 month' => 1,
            '2 months' => 2,
            '3 months' => 3,
            default => null,
        };
    }

    /** Same day N months later, clamped to the last day when that day is missing. */
    private static function addMonths(string $start, int $months): string
    {
        $date = Dates::parse($start);
        $day = (int) $date->format('j');
        $cursor = $date->modify('first day of this month');
        if ($months !== 0) {
            $cursor = $cursor->modify(($months > 0 ? '+' : '') . $months . ' months');
        }
        $last = (int) $cursor->format('t');

        return $cursor->setDate(
            (int) $cursor->format('Y'),
            (int) $cursor->format('n'),
            min($day, $last),
        )->format('Y-m-d');
    }

    private static function holds(string $start, string $length, string $today): bool
    {
        return $start <= $today && $today <= self::end($start, $length);
    }

    /** Last day of the period `step` lengths from $start, counted on that start's own grid. */
    private static function boundary(string $start, string $length, int $step): string
    {
        return Dates::parse(self::shift($start, $length, $step + 1))->modify('-1 day')->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        $start = new \DateTimeImmutable($from . ' UTC');
        $end = new \DateTimeImmutable($to . ' UTC');

        return (int) $start->diff($end)->format('%r%a');
    }

    private static function floorDiv(int $numerator, int $denominator): int
    {
        $quote = intdiv($numerator, $denominator);
        if ($numerator < 0 && $numerator % $denominator !== 0) {
            $quote--;
        }

        return $quote;
    }
}
