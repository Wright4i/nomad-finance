<?php

declare(strict_types=1);

namespace App\Bill;

final class DuePie
{
    /** @var list<string> */
    private const TYPES = [
        'lodging', 'rent', 'electricity', 'water', 'internet', 'cellular', 'gas',
        'groceries', 'restaurants', 'vehicle', 'debt', 'healthcare', 'entertainment',
        'subscriptions', 'shopping', 'travel', 'other',
    ];

    /**
     * One pie per currency. A slice is the due amount for one bill type: active bills
     * whose next due date falls in that month. Paid and paused bills are left out.
     * A type with no due amount is omitted. Currencies are not added together.
     *
     * @param list<array{type:string,status:string,nextDueDate:string,amountMinor:int,currency:string,exponent:int}> $bills
     * @return list<array{currency:string,exponent:int,slices:list<array{type:string,amountMinor:int}>}>
     */
    public static function month(array $bills, string $month): array
    {
        /** @var array<string, array<string, int>> $sums */
        $sums = [];
        /** @var array<string, int> $exponents */
        $exponents = [];
        foreach ($bills as $bill) {
            if ($bill['status'] !== 'active' || $bill['amountMinor'] <= 0) {
                continue;
            }
            if (!self::inMonth($bill['nextDueDate'], $month)) {
                continue;
            }
            $currency = $bill['currency'];
            $type = $bill['type'];
            $sums[$currency][$type] = ($sums[$currency][$type] ?? 0) + $bill['amountMinor'];
            $exponents[$currency] = $bill['exponent'];
        }

        ksort($sums);
        $pies = [];
        foreach ($sums as $currency => $types) {
            $slices = [];
            foreach (self::TYPES as $type) {
                $amount = $types[$type] ?? 0;
                if ($amount <= 0) {
                    continue;
                }
                $slices[] = ['type' => $type, 'amountMinor' => $amount];
                unset($types[$type]);
            }
            foreach ($types as $type => $amount) {
                if ($amount > 0) {
                    $slices[] = ['type' => (string) $type, 'amountMinor' => $amount];
                }
            }
            if ($slices === []) {
                continue;
            }
            $pies[] = [
                'currency' => $currency,
                'exponent' => $exponents[$currency],
                'slices' => $slices,
            ];
        }

        return $pies;
    }

    private static function inMonth(string $date, string $month): bool
    {
        return strlen($month) === 7 && str_starts_with($date, $month . '-');
    }
}
