<?php

declare(strict_types=1);

namespace App\Span;

use App\Support\Dates;

/**
 * The one window for Planner, Calendar, Trips, Bills, and Budget.
 * A week is 7 days. A month runs through the day before the same day N months later.
 * When that day does not exist, the anniversary clamps to the last day, then steps back one.
 */
final class Span
{
    public const LENGTHS = ['1 week', '2 weeks', '4 weeks', '1 month', '2 months', '3 months'];

    public static function valid(string $length): bool
    {
        return in_array($length, self::LENGTHS, true);
    }

    public static function defaultStart(string $today): string
    {
        return Dates::parse($today)->format('Y-m-01');
    }

    /** Inclusive last day of the span that starts on $start. */
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
            throw new \InvalidArgumentException('Choose a span length.');
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

    /** current when today is inside, closed when the span has ended, future when it has not started. */
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
     * @return array{start:string,end:string,length:string,phase:string,editable:bool,upcoming:bool}
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
        ];
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
}
