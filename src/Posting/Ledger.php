<?php

declare(strict_types=1);

namespace App\Posting;

final class Ledger
{
    public static function delta(string $side, string $direction, int $amount): int
    {
        if ($side === 'asset') {
            return $direction === 'out' ? -$amount : $amount;
        }

        return $direction === 'out' ? $amount : -$amount;
    }

    /** @return array{next:int,refused:?string,overLimit:bool} */
    public static function preview(int $balance, int $delta, ?int $limit): array
    {
        $next = $balance + $delta;
        if ($next < 0) {
            return [
                'next' => $balance,
                'refused' => 'That would take the balance below zero.',
                'overLimit' => false,
            ];
        }

        return [
            'next' => $next,
            'refused' => null,
            'overLimit' => $limit !== null && $next > $limit,
        ];
    }
}
