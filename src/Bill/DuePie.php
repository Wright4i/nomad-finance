<?php

declare(strict_types=1);

namespace App\Bill;

final class DuePie
{
    /**
     * One pie. A slice sums every unpaid occurrence of an active bill in that month.
     * The step is the same one the calendar uses. Paid dates are not charted. Paused bills stay out.
     *
     * @param list<array{type:string,status:string,nextDueDate:string,amountMinor:int,name?:string,recurrence?:string,anchorDate?:string,lastPaidOn?:?string,id?:int,currency?:string,exponent?:int}> $bills
     * @return list<array{currency:string,exponent:int,slices:list<array{type:string,amountMinor:int}>,leftOut:list<string>}>
     */
    public static function month(array $bills, string $month, string $base, int $baseExponent): array
    {
        /** @var array<string, int> $sums */
        $sums = [];
        $prepared = [];
        foreach ($bills as $index => $bill) {
            if (($bill['status'] ?? '') !== 'active' || ($bill['amountMinor'] ?? 0) <= 0) {
                continue;
            }
            $prepared[] = [
                'id' => (int) ($bill['id'] ?? $index),
                'name' => (string) ($bill['name'] ?? $bill['type']),
                'type' => (string) $bill['type'],
                'otherLabel' => $bill['otherLabel'] ?? null,
                'amountMinor' => (int) $bill['amountMinor'],
                'currency' => (string) ($bill['currency'] ?? $base),
                'exponent' => (int) ($bill['exponent'] ?? $baseExponent),
                'status' => 'active',
                'recurrence' => (string) ($bill['recurrence'] ?? 'once'),
                'every' => max(1, (int) ($bill['every'] ?? 1)),
                'anchorDate' => (string) ($bill['anchorDate'] ?? $bill['nextDueDate']),
                'nextDueDate' => (string) $bill['nextDueDate'],
                'lastPaidOn' => $bill['lastPaidOn'] ?? null,
            ];
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            foreach (Occurrences::inMonth($prepared, $month) as $mark) {
                if ($mark['paid'] || $mark['amountMinor'] <= 0) {
                    continue;
                }
                $sums[$mark['type']] = ($sums[$mark['type']] ?? 0) + $mark['amountMinor'];
            }
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
        if ($slices === []) {
            return [];
        }

        return [[
            'currency' => $base,
            'exponent' => $baseExponent,
            'slices' => $slices,
            'leftOut' => [],
        ]];
    }
}
