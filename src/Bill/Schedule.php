<?php

declare(strict_types=1);

namespace App\Bill;

use App\Support\Dates;

final class Schedule
{
    public static function advance(string $recurrence, string $anchor, string $current, int $every = 1): string
    {
        $anchorDate = Dates::parse($anchor);
        $currentDate = Dates::parse($current);
        $every = max(1, $every);

        if ($recurrence === 'daily') {
            return $currentDate->modify(sprintf('+%d days', $every))->format('Y-m-d');
        }

        if ($recurrence === 'weekly') {
            return $currentDate->modify(sprintf('+%d days', 7 * $every))->format('Y-m-d');
        }

        if ($recurrence === 'monthly') {
            $cursor = $currentDate->modify(sprintf('first day of +%d months', $every));

            return self::clampDay($cursor, (int) $anchorDate->format('j'));
        }

        if ($recurrence === 'yearly') {
            $year = (int) $currentDate->format('Y') + $every;
            $month = (int) $anchorDate->format('n');
            $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

            return self::clampDay($cursor, (int) $anchorDate->format('j'));
        }

        throw new \InvalidArgumentException('This bill does not repeat.');
    }

    public static function rewind(string $recurrence, string $anchor, string $current, int $every = 1): string
    {
        $anchorDate = Dates::parse($anchor);
        $currentDate = Dates::parse($current);
        $every = max(1, $every);

        if ($recurrence === 'daily') {
            return $currentDate->modify(sprintf('-%d days', $every))->format('Y-m-d');
        }

        if ($recurrence === 'weekly') {
            return $currentDate->modify(sprintf('-%d days', 7 * $every))->format('Y-m-d');
        }

        if ($recurrence === 'monthly') {
            $cursor = $currentDate->modify(sprintf('first day of -%d months', $every));

            return self::clampDay($cursor, (int) $anchorDate->format('j'));
        }

        if ($recurrence === 'yearly') {
            $year = (int) $currentDate->format('Y') - $every;
            $month = (int) $anchorDate->format('n');
            $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

            return self::clampDay($cursor, (int) $anchorDate->format('j'));
        }

        throw new \InvalidArgumentException('This bill does not repeat.');
    }

    private static function clampDay(\DateTimeImmutable $monthStart, int $day): string
    {
        $last = (int) $monthStart->format('t');
        $use = min($day, $last);

        return $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            $use,
        )->format('Y-m-d');
    }
}
