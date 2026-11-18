<?php

declare(strict_types=1);

namespace App\Account;

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
