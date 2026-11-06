<?php

declare(strict_types=1);

namespace App\Bill;

use App\Support\Dates;

/**
 * A bill section opens on about seven days and grows when the person scrolls.
 * Paid opens on the most recent dates and grows toward older ones.
 * Other sections open on the earliest dates and grow toward later ones.
 * What is shown stays oldest to newest, and the first window includes a second bill when one exists.
 */
final class BillWindow
{
    public const DAYS = 7;

    /**
     * The date a bill shows on in one section. Null when the bill is not in that section.
     *
     * @param array<string, mixed> $bill
     */
    public static function shownOn(array $bill, string $section): ?string
    {
        $status = (string) ($bill['status'] ?? '');
        $due = (string) ($bill['nextDueDate'] ?? '');
        $paidOn = $bill['lastPaidOn'] ?? null;
        $paidOn = is_string($paidOn) && $paidOn !== '' ? $paidOn : null;
        if ($section === 'paid') {
            $repeating = $status !== 'paid' && ($bill['recurrence'] ?? '') !== 'once' && $paidOn !== null;
            if ($status !== 'paid' && !$repeating) {
                return null;
            }

            return $paidOn ?? ($due !== '' ? $due : null);
        }
        if (!in_array($section, ['active', 'paused', 'archived'], true) || $status !== $section || $due === '') {
            return null;
        }

        return $due;
    }

    /**
     * Oldest date first. On one date the largest amount comes first, then the name, then the id.
     *
     * @param list<array{id:int,name:string,shownOn:string,amountMinor:int}> $rows
     * @return list<array{id:int,name:string,shownOn:string,amountMinor:int}>
     */
    public static function order(array $rows): array
    {
        $copy = array_values($rows);
        usort($copy, static function (array $left, array $right): int {
            return [$left['shownOn'], -$left['amountMinor'], $left['name'], $left['id']]
                <=> [$right['shownOn'], -$right['amountMinor'], $right['name'], $right['id']];
        });

        return $copy;
    }

    /**
     * @param list<array{id:int,name:string,shownOn:string,amountMinor:int}> $rows
     * @return array{rows:list<array{id:int,name:string,shownOn:string,amountMinor:int}>,hasMore:bool}
     */
    public static function window(array $rows, int $chunks, bool $fromRecent = false): array
    {
        $chunks = max(1, $chunks);
        $ordered = self::order($rows);
        $dates = [];
        foreach ($ordered as $row) {
            $dates[$row['shownOn']] = true;
        }
        $dates = array_keys($dates);
        if ($dates === []) {
            return ['rows' => [], 'hasMore' => false];
        }
        if ($fromRecent) {
            $end = $dates[array_key_last($dates)];
            $start = self::recentStart($dates, $ordered, $chunks);
            $visible = [];
            $hasMore = false;
            foreach ($ordered as $row) {
                if ($row['shownOn'] < $start) {
                    $hasMore = true;
                    continue;
                }
                if ($row['shownOn'] <= $end) {
                    $visible[] = $row;
                }
            }

            return ['rows' => $visible, 'hasMore' => $hasMore];
        }
        $end = self::forwardEnd($dates, $ordered, $chunks);
        $visible = [];
        $hasMore = false;
        foreach ($ordered as $row) {
            if ($row['shownOn'] <= $end) {
                $visible[] = $row;
            } else {
                $hasMore = true;
            }
        }

        return ['rows' => $visible, 'hasMore' => $hasMore];
    }

    /** @param list<string> $dates Oldest first, one entry per date. */
    public static function end(array $dates, int $chunks): ?string
    {
        if ($dates === [] || $chunks < 1) {
            return null;
        }
        $end = self::addDays($dates[0], self::DAYS - 1);
        for ($step = 1; $step < $chunks; $step++) {
            $later = null;
            foreach ($dates as $date) {
                if ($date > $end) {
                    $later = $date;
                    break;
                }
            }
            if ($later === null) {
                return $end;
            }
            $stepped = self::addDays($end, self::DAYS);
            $end = $later > $stepped ? self::addDays($later, self::DAYS - 1) : $stepped;
        }

        return $end;
    }

    /**
     * Earliest dates first. The opening week widens to the next bill when it would otherwise show one.
     *
     * @param list<string> $dates
     * @param list<array{shownOn:string}> $rows
     */
    private static function forwardEnd(array $dates, array $rows, int $chunks): string
    {
        $end = self::addDays($dates[0], self::DAYS - 1);
        $end = self::widenEnd($rows, $dates, $dates[0], $end);
        for ($step = 1; $step < $chunks; $step++) {
            $later = null;
            foreach ($dates as $date) {
                if ($date > $end) {
                    $later = $date;
                    break;
                }
            }
            if ($later === null) {
                return $end;
            }
            $stepped = self::addDays($end, self::DAYS);
            $end = $later > $stepped ? self::addDays($later, self::DAYS - 1) : $stepped;
        }

        return $end;
    }

    /**
     * Recent dates first. The opening week reaches the next older bill when it would otherwise show one.
     *
     * @param list<string> $dates
     * @param list<array{shownOn:string}> $rows
     */
    private static function recentStart(array $dates, array $rows, int $chunks): string
    {
        $end = $dates[array_key_last($dates)];
        $start = self::addDays($end, -(self::DAYS - 1));
        $start = self::widenStart($rows, $dates, $start, $end);
        for ($step = 1; $step < $chunks; $step++) {
            $older = null;
            foreach ($dates as $date) {
                if ($date < $start) {
                    $older = $date;
                }
            }
            if ($older === null) {
                return $start;
            }
            $stepped = self::addDays($start, -self::DAYS);
            $start = $older < $stepped ? self::addDays($older, -(self::DAYS - 1)) : $stepped;
        }

        return $start;
    }

    /**
     * @param list<array{shownOn:string}> $rows
     * @param list<string> $dates
     */
    private static function widenEnd(array $rows, array $dates, string $start, string $end): string
    {
        if (self::countBetween($rows, $start, $end) >= 2) {
            return $end;
        }
        foreach ($dates as $date) {
            if ($date > $end) {
                return $date;
            }
        }

        return $end;
    }

    /**
     * @param list<array{shownOn:string}> $rows
     * @param list<string> $dates
     */
    private static function widenStart(array $rows, array $dates, string $start, string $end): string
    {
        if (self::countBetween($rows, $start, $end) >= 2) {
            return $start;
        }
        $older = null;
        foreach ($dates as $date) {
            if ($date < $start) {
                $older = $date;
            }
        }

        return $older ?? $start;
    }

    /** @param list<array{shownOn:string}> $rows */
    private static function countBetween(array $rows, string $start, string $end): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ($row['shownOn'] >= $start && $row['shownOn'] <= $end) {
                $count++;
            }
        }

        return $count;
    }

    public static function addDays(string $iso, int $days): string
    {
        return Dates::parse($iso)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
