<?php

declare(strict_types=1);

namespace App\Bill;

use App\Money\StoredRates;

final class DuePie
{
    /**
     * One pie in the base currency. A slice is the due amount for one bill type:
     * active bills whose next due date falls in that month, converted at the stored rate.
     * Paid and paused bills are left out. A bill with no rate is named and left out.
     *
     * @param list<array{type:string,status:string,nextDueDate:string,amountMinor:int,currency:string,exponent:int,name?:string}> $bills
     * @param array<string, string> $rates
     * @return list<array{currency:string,exponent:int,slices:list<array{type:string,amountMinor:int}>,leftOut:list<string>}>
     */
    public static function month(array $bills, string $month, string $base, int $baseExponent, array $rates): array
    {
        /** @var array<string, int> $sums */
        $sums = [];
        $leftOut = [];
        foreach ($bills as $bill) {
            if ($bill['status'] !== 'active' || $bill['amountMinor'] <= 0) {
                continue;
            }
            if (!self::inMonth($bill['nextDueDate'], $month)) {
                continue;
            }
            $converted = StoredRates::toBase(
                $bill['amountMinor'],
                $bill['currency'],
                $bill['exponent'],
                $base,
                $baseExponent,
                $rates,
            );
            if ($converted === null) {
                $leftOut[] = $bill['name'] ?? $bill['type'];
                continue;
            }
            $sums[$bill['type']] = ($sums[$bill['type']] ?? 0) + $converted;
        }
        $slices = [];
        foreach (BillTypes::all() as $type) {
            $amount = $sums[$type] ?? 0;
            if ($amount <= 0) {
                continue;
            }
            $slices[] = ['type' => $type, 'amountMinor' => $amount];
            unset($sums[$type]);
        }
        foreach ($sums as $type => $amount) {
            if ($amount > 0) {
                $slices[] = ['type' => (string) $type, 'amountMinor' => $amount];
            }
        }
        sort($leftOut);
        if ($slices === [] && $leftOut === []) {
            return [];
        }

        return [[
            'currency' => $base,
            'exponent' => $baseExponent,
            'slices' => $slices,
            'leftOut' => $leftOut,
        ]];
    }

    private static function inMonth(string $date, string $month): bool
    {
        return strlen($month) === 7 && str_starts_with($date, $month . '-');
    }
}
