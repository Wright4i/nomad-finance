<?php

declare(strict_types=1);

namespace App\PayCycle;

use App\Support\Dates;

final class Payroll
{
    /**
     * The paycheck on one date.
     * A keyed amount wins. Otherwise, with average on and at least one earlier key,
     * the average of those earlier amounts, half up. Otherwise the base paycheck.
     *
     * @param list<int> $earlierKeyed
     */
    public static function amount(?int $keyedMinor, bool $averageOn, array $earlierKeyed, ?int $baseMinor): ?int
    {
        if ($keyedMinor !== null) {
            return $keyedMinor;
        }
        if ($averageOn && $earlierKeyed !== []) {
            $count = count($earlierKeyed);
            $sum = array_sum($earlierKeyed);

            return intdiv($sum + intdiv($count, 2), $count);
        }

        return $baseMinor;
    }

    /**
     * Pay dates from the start of last month through the end of next month.
     *
     * @return list<string>
     */
    public static function window(string $payday, string $length, ?int $secondDay, string $today): array
    {
        $todayDate = Dates::parse($today);
        $start = $todayDate->modify('first day of last month')->format('Y-m-d');
        $end = $todayDate->modify('first day of next month')->format('Y-m-t');

        return self::between($payday, $length, $secondDay, $start, $end);
    }

    /** @return list<string> */
    public static function between(
        string $payday,
        string $length,
        ?int $secondDay,
        string $start,
        string $end,
    ): array {
        $found = [];
        $probe = $start;
        for ($i = 0; $i < 80; $i++) {
            try {
                $open = Period::open($payday, $length, $secondDay, null, $probe);
            } catch (\InvalidArgumentException) {
                break;
            }
            foreach ([$open['start'], $open['next']] as $candidate) {
                if (is_string($candidate) && $candidate >= $start && $candidate <= $end) {
                    $found[$candidate] = $candidate;
                }
            }
            $next = $open['next'];
            if ($next === null || $next <= $probe || $next > $end) {
                break;
            }
            $probe = $next;
        }
        $listed = array_values($found);
        sort($listed);

        return $listed;
    }
}
