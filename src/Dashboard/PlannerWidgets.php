<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Support\Dates;

/**
 * Planner widgets. A label of what is already on the books.
 * Picking a stay, a payday, or a column does not move a balance.
 */
final class PlannerWidgets
{
    /** @var list<string> */
    public const ORDER = ['income', 'calendar', 'trips', 'accounts', 'envelopes', 'bills'];

    /**
     * Column spans on the six-column planner.
     * Income is half beside net worth. Calendar, Trips, and Accounts are single, three across.
     * Envelopes and Bills each take the row.
     *
     * @var array<string, int>
     */
    public const SPANS = [
        'income' => 3,
        'calendar' => 2,
        'trips' => 2,
        'accounts' => 2,
        'envelopes' => 6,
        'bills' => 6,
    ];

    /** @var list<int> */
    public const SPAN_VALUES = [1, 2, 3, 4, 5, 6];

    /** @var list<string> */
    public const TYPES = ['checking', 'savings', 'cash', 'credit', 'loan', 'fuel', 'other'];

    /**
     * A stored order is kept only when it names each widget once.
     * Anything unknown or partial returns the default, still with every widget.
     *
     * @return list<string>
     */
    public static function order(mixed $stored): array
    {
        $accepted = self::accept(self::decode($stored));

        return $accepted ?? self::ORDER;
    }

    /**
     * @return list<string>|null
     */
    public static function accept(mixed $input): ?array
    {
        if (!is_array($input) || count($input) !== count(self::ORDER)) {
            return null;
        }
        $seen = [];
        foreach ($input as $item) {
            if (!is_string($item) || !in_array($item, self::ORDER, true) || isset($seen[$item])) {
                return null;
            }
            $seen[$item] = true;
        }

        return array_values($input);
    }

    /** @param list<string> $order */
    public static function encode(array $order): string
    {
        $json = json_encode(array_values($order));

        return is_string($json) ? $json : '[]';
    }

    /**
     * Swap a widget with the one beside it.
     * A step of -1 moves it earlier, toward the start of the order.
     * A step of 1 moves it later. The first widget does not move earlier.
     * The last widget does not move later.
     *
     * @param list<string> $order
     * @return list<string>
     */
    public static function step(array $order, string $widget, int $by): array
    {
        $next = array_values($order);
        if ($by !== -1 && $by !== 1) {
            return $next;
        }
        $from = array_search($widget, $next, true);
        if (!is_int($from)) {
            return $next;
        }
        $to = $from + $by;
        if ($to < 0 || $to >= count($next)) {
            return $next;
        }
        $neighbor = $next[$to];
        $next[$to] = $next[$from];
        $next[$from] = $neighbor;

        return $next;
    }

    /**
     * A stored map is kept only when every widget has a span from 1 to 6.
     * Anything unknown or partial returns the default.
     *
     * @return array<string, int>
     */
    public static function spans(mixed $stored): array
    {
        $accepted = self::acceptSpans(self::decode($stored));

        return $accepted ?? self::SPANS;
    }

    /**
     * @return array<string, int>|null
     */
    public static function acceptSpans(mixed $input): ?array
    {
        if (!is_array($input) || array_is_list($input) || count($input) !== count(self::ORDER)) {
            return null;
        }
        $spans = [];
        foreach (self::ORDER as $widget) {
            if (!array_key_exists($widget, $input)) {
                return null;
            }
            $span = $input[$widget];
            if (!is_int($span) || !in_array($span, self::SPAN_VALUES, true)) {
                return null;
            }
            $spans[$widget] = $span;
        }

        return $spans;
    }

    /** @param array<string, int> $spans */
    public static function encodeSpans(array $spans): string
    {
        $ordered = [];
        foreach (self::ORDER as $widget) {
            $ordered[$widget] = $spans[$widget];
        }
        $json = json_encode($ordered);

        return is_string($json) ? $json : '{}';
    }

    /**
     * An arrival today or tomorrow leads, soonest first.
     * Otherwise the first stay in the list whose dates cover today.
     * The day after tomorrow does not lead.
     *
     * @param list<array<string, mixed>> $trips
     * @return array{id:int,placeName:string,city:?string,region:?string,arriveOn:string,departOn:string,latitude:?string,longitude:?string}|null
     */
    public static function stay(array $trips, string $today): ?array
    {
        $tomorrow = Dates::parse($today)->modify('+1 day')->format('Y-m-d');
        $arrivals = [];
        foreach ($trips as $trip) {
            $arrive = (string) ($trip['arriveOn'] ?? '');
            if ($arrive === $today || $arrive === $tomorrow) {
                $arrivals[] = $trip;
            }
        }
        if ($arrivals !== []) {
            usort($arrivals, static function (array $left, array $right): int {
                $date = strcmp((string) ($left['arriveOn'] ?? ''), (string) ($right['arriveOn'] ?? ''));
                if ($date !== 0) {
                    return $date;
                }

                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            });

            return self::presentStay($arrivals[0]);
        }
        foreach ($trips as $trip) {
            $arrive = (string) ($trip['arriveOn'] ?? '');
            $depart = (string) ($trip['departOn'] ?? '');
            if ($arrive !== '' && $depart !== '' && $arrive <= $today && $today <= $depart) {
                return self::presentStay($trip);
            }
        }

        return null;
    }

    /**
     * The soonest payday on or after today. The amount is the preview already on the row.
     *
     * @param list<array<string, mixed>> $timeline
     * @return array{payor:string,on:string,paycheckMinor:?int}|null
     */
    public static function payday(array $timeline, string $today): ?array
    {
        $soonest = null;
        foreach ($timeline as $row) {
            if (($row['kind'] ?? '') !== 'payday') {
                continue;
            }
            $on = (string) ($row['on'] ?? '');
            if ($on === '' || $on < $today) {
                continue;
            }
            if ($soonest !== null && $on >= $soonest['on']) {
                continue;
            }
            $amount = $row['paycheckMinor'] ?? null;
            $soonest = [
                'payor' => (string) ($row['payor'] ?? ''),
                'on' => $on,
                'paycheckMinor' => $amount === null || $amount === '' ? null : (int) $amount,
            ];
        }

        return $soonest;
    }

    /**
     * Paychecks in one month that have not been received.
     * A payday before today has already come in. Today and later in that month are still coming in.
     *
     * @param list<array<string, mixed>> $paychecks
     */
    public static function stillComing(array $paychecks, string $month, string $today): int
    {
        $sum = 0;
        foreach ($paychecks as $row) {
            $on = (string) ($row['on'] ?? '');
            if ($on === '' || !str_starts_with($on, $month) || $on < $today) {
                continue;
            }
            $sum += (int) ($row['paycheckMinor'] ?? 0);
        }

        return $sum;
    }

    /**
     * One total per account type and side that has an account. Empty types are skipped.
     * Color follows the account side: an asset is teal and a liability is coral.
     * Other is coral when that account is a liability. The order is most money first
     * and highest debt last, with liability balances treated as negative for the sort.
     *
     * @param list<array<string, mixed>> $accounts
     * @return list<array{type:string,balanceMinor:int,side:string}>
     */
    public static function accountTotals(array $accounts): array
    {
        /** @var array<string, array{type:string,balanceMinor:int,side:string}> $sums */
        $sums = [];
        foreach ($accounts as $account) {
            $type = (string) ($account['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            $side = (string) ($account['side'] ?? '');
            if ($side !== 'asset' && $side !== 'liability') {
                continue;
            }
            $key = $type . ':' . $side;
            if (!isset($sums[$key])) {
                $sums[$key] = ['type' => $type, 'balanceMinor' => 0, 'side' => $side];
            }
            $sums[$key]['balanceMinor'] += (int) ($account['balanceMinor'] ?? 0);
        }
        $rows = array_values($sums);
        usort($rows, static function (array $left, array $right): int {
            $signed = static function (array $row): int {
                $balance = (int) $row['balanceMinor'];

                return $row['side'] === 'liability' ? -$balance : $balance;
            };
            $money = $signed($right) <=> $signed($left);
            if ($money !== 0) {
                return $money;
            }
            $type = strcmp($left['type'], $right['type']);
            if ($type !== 0) {
                return $type;
            }

            return strcmp($left['side'], $right['side']);
        });

        return $rows;
    }

    private static function decode(mixed $stored): mixed
    {
        if (!is_string($stored)) {
            return $stored;
        }
        $trimmed = trim($stored);
        if ($trimmed === '') {
            return null;
        }

        return json_decode($trimmed, true);
    }

    /**
     * @param array<string, mixed> $trip
     * @return array{id:int,placeName:string,city:?string,region:?string,arriveOn:string,departOn:string,latitude:?string,longitude:?string}
     */
    private static function presentStay(array $trip): array
    {
        $city = $trip['city'] ?? null;
        $region = $trip['region'] ?? null;
        $latitude = $trip['latitude'] ?? null;
        $longitude = $trip['longitude'] ?? null;

        return [
            'id' => (int) ($trip['id'] ?? 0),
            'placeName' => (string) ($trip['placeName'] ?? ''),
            'city' => is_string($city) && trim($city) !== '' ? trim($city) : null,
            'region' => is_string($region) && trim($region) !== '' ? trim($region) : null,
            'arriveOn' => (string) ($trip['arriveOn'] ?? ''),
            'departOn' => (string) ($trip['departOn'] ?? ''),
            'latitude' => is_string($latitude) && trim($latitude) !== '' ? trim($latitude) : (is_numeric($latitude) ? (string) $latitude : null),
            'longitude' => is_string($longitude) && trim($longitude) !== '' ? trim($longitude) : (is_numeric($longitude) ? (string) $longitude : null),
        ];
    }
}
