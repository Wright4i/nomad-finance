<?php

declare(strict_types=1);

namespace App\Bill;

/** Which bills a status tab keeps when a person narrows the list. */
final class BillListFilter
{
    /**
     * @param array<string, mixed> $bill
     * @param array<string, mixed> $filter
     */
    public static function matches(array $bill, array $filter): bool
    {
        $name = trim((string) ($filter['name'] ?? ''));
        if ($name !== '' && mb_stripos((string) ($bill['name'] ?? ''), $name) === false) {
            return false;
        }

        $accountId = (int) ($filter['accountId'] ?? 0);
        if ($accountId > 0 && (int) ($bill['accountId'] ?? 0) !== $accountId) {
            return false;
        }

        $type = trim((string) ($filter['type'] ?? ''));
        if ($type !== '' && (string) ($bill['type'] ?? '') !== $type) {
            return false;
        }

        $amount = trim((string) ($filter['amount'] ?? ''));
        if ($amount !== '' && !self::amountMatches((int) ($bill['amountMinor'] ?? 0), $amount)) {
            return false;
        }

        return true;
    }

    /** A typed amount matches the dollar figure from the left, so 12 matches 12.00 and 120.00. */
    public static function amountMatches(int $minor, string $query): bool
    {
        $query = str_replace(['$', ',', ' '], '', trim($query));
        if ($query === '') {
            return true;
        }
        if (preg_match('/^\d*\.?\d*$/', $query) !== 1 || $query === '.') {
            return false;
        }

        $dollars = number_format($minor / 100, 2, '.', '');

        return str_starts_with($dollars, $query);
    }
}
