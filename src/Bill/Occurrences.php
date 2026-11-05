<?php

declare(strict_types=1);

namespace App\Bill;

/**
 * Due dates a bill shows on one calendar month.
 * A repeating bill steps forward from its next unpaid due date.
 * The stored paid due date stays paid and is not also projected as unpaid.
 */
final class Occurrences
{
    /**
     * @param list<array<string, mixed>> $bills
     * @return list<array{id:int,name:string,type:string,on:string,amountMinor:int,currency:string,exponent:int,paid:bool,status:string}>
     */
    public static function inMonth(array $bills, string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new \InvalidArgumentException('Use a YYYY-MM month.');
        }
        $start = $month . '-01';
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $marks = [];
        foreach ($bills as $bill) {
            foreach (self::marks($bill, $start, $end) as $mark) {
                $marks[] = $mark;
            }
        }
        usort($marks, static function (array $left, array $right): int {
            return [$left['on'], $left['name'], $left['paid'] ? 1 : 0]
                <=> [$right['on'], $right['name'], $right['paid'] ? 1 : 0];
        });

        return $marks;
    }

    /**
     * @param array<string, mixed> $bill
     * @return list<array{id:int,name:string,type:string,on:string,amountMinor:int,currency:string,exponent:int,paid:bool,status:string}>
     */
    private static function marks(array $bill, string $start, string $end): array
    {
        $name = (string) $bill['name'];
        if (in_array($bill['type'] ?? '', ['other', 'misc'], true) && is_string($bill['otherLabel'] ?? null) && $bill['otherLabel'] !== '') {
            $name = $bill['otherLabel'];
        }
        $base = [
            'id' => (int) $bill['id'],
            'name' => $name,
            'type' => (string) $bill['type'],
            'amountMinor' => (int) $bill['amountMinor'],
            'currency' => (string) $bill['currency'],
            'exponent' => (int) $bill['exponent'],
            'status' => (string) $bill['status'],
        ];
        $paidOn = is_string($bill['lastPaidOn'] ?? null) ? $bill['lastPaidOn'] : null;
        $next = (string) $bill['nextDueDate'];
        $status = (string) $bill['status'];
        $recurrence = (string) $bill['recurrence'];
        $anchor = (string) $bill['anchorDate'];
        $marks = [];
        $paidDate = $recurrence === 'once'
            ? ($status === 'paid' ? $next : null)
            : $paidOn;

        if ($paidDate !== null && $paidDate >= $start && $paidDate <= $end) {
            $marks[] = $base + ['on' => $paidDate, 'paid' => true];
        }
        if ($recurrence === 'once' && $status === 'paid' && $paidOn !== null && $paidOn !== $next && $paidOn >= $start && $paidOn <= $end) {
            $marks[] = $base + ['on' => $paidOn, 'paid' => true];
        }
        if ($recurrence === 'once') {
            if ($status !== 'paid' && $next >= $start && $next <= $end) {
                $marks[] = $base + ['on' => $next, 'paid' => false];
            }

            return $marks;
        }

        $cursor = $next;
        for ($guard = 0; $guard < 600 && $cursor <= $end; $guard++) {
            if ($cursor >= $start && $cursor !== $paidDate) {
                $marks[] = $base + ['on' => $cursor, 'paid' => false];
            }
            $advanced = Schedule::advance($recurrence, $anchor, $cursor);
            if ($advanced <= $cursor) {
                break;
            }
            $cursor = $advanced;
        }

        return $marks;
    }
}
