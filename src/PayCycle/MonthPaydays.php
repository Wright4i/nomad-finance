<?php

declare(strict_types=1);

namespace App\PayCycle;

/**
 * Payday dates that fall in one calendar month.
 * A preview. It does not assign money or post it.
 */
final class MonthPaydays
{
    /** @return list<string> */
    public static function dates(
        string $payday,
        string $length,
        ?int $secondDay,
        ?string $nextPayday,
        string $month,
    ): array {
        $start = $month . '-01';
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        if (!$end instanceof \DateTimeImmutable) {
            return [];
        }
        $end = $end->format('Y-m-t');
        $found = [];
        $probe = $start;
        for ($i = 0; $i < 40; $i++) {
            try {
                $open = Period::open($payday, $length, $secondDay, $nextPayday, $probe);
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
