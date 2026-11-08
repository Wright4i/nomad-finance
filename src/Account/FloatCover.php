<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Uncovered float on a paid-in-full card is the balance minus the bills already in the buckets that are drawn from it.
 * Cover cannot exceed the balance, and the uncovered part cannot go below zero.
 */
final class FloatCover
{
    /** @return array{covered:int,uncovered:int} */
    public static function split(int $balance, int $inBuckets): array
    {
        $balance = max(0, $balance);
        $covered = min($balance, max(0, $inBuckets));

        return [
            'covered' => $covered,
            'uncovered' => $balance - $covered,
        ];
    }
}
