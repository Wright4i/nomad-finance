<?php

declare(strict_types=1);

namespace App\PayCycle;

use App\Support\Dates;

final class Period
{
    /**
     * The open span starts on a payday and ends the day before the next one.
     * `next` is null when an irregular cycle has no next payday.
     * `rolled` means an irregular next payday was reached and consumed.
     *
     * @return array{start:string, next:?string, rolled:bool}
     */
    public static function open(
        string $payday,
        string $length,
        ?int $secondDay,
        ?string $nextPayday,
        string $today,
    ): array {
        $start = Dates::parse($payday);
        $todayDate = Dates::parse($today);

        if ($length === 'irregular') {
            if ($nextPayday === null || $nextPayday === '') {
                return ['start' => $start->format('Y-m-d'), 'next' => null, 'rolled' => false];
            }
            $next = Dates::parse($nextPayday);
            if ($todayDate >= $next) {
                return ['start' => $next->format('Y-m-d'), 'next' => null, 'rolled' => true];
            }

            return ['start' => $start->format('Y-m-d'), 'next' => $next->format('Y-m-d'), 'rolled' => false];
        }

        if ($length === 'weekly' || $length === 'biweekly') {
            return self::step($start, $todayDate, $length === 'weekly' ? 7 : 14);
        }

        if ($length === 'monthly') {
            return self::monthly($start, $todayDate);
        }

        if ($length === 'semimonthly') {
            if ($secondDay === null) {
                throw new \InvalidArgumentException('A semimonthly cycle needs a second day.');
            }

            return self::semimonthly($start, $secondDay, $todayDate);
        }

        throw new \InvalidArgumentException('Choose a pay cycle length.');
    }

    /**
     * The pay period whose start falls inside the window.
     * When today sits in one of those periods, that period is the one shown.
     * Otherwise it is the earliest start in the window.
     *
     * @return ?array{start:string,end:?string}
     */
    public static function startingIn(
        string $payday,
        string $length,
        ?int $secondDay,
        ?string $nextPayday,
        string $windowStart,
        string $windowEnd,
        string $today,
    ): ?array {
        $cursor = $windowStart;
        $earliest = null;
        for ($guard = 0; $guard < 500; $guard++) {
            $open = self::open($payday, $length, $secondDay, $nextPayday, $cursor);
            $start = $open['start'];
            if ($start > $windowEnd) {
                break;
            }
            $end = $open['next'] === null
                ? null
                : Dates::parse($open['next'])->modify('-1 day')->format('Y-m-d');
            if ($start >= $windowStart) {
                $span = ['start' => $start, 'end' => $end];
                if ($today >= $start && ($end === null || $today <= $end)) {
                    return $span;
                }
                $earliest ??= $span;
            }
            if ($open['next'] === null || $open['next'] <= $cursor) {
                break;
            }
            $cursor = $open['next'];
        }

        return $earliest;
    }

    /** @return array{start:string, next:?string, rolled:bool} */
    private static function step(\DateTimeImmutable $payday, \DateTimeImmutable $today, int $days): array
    {
        $cursor = $payday;
        if ($today >= $payday) {
            $elapsed = (int) $payday->diff($today)->days;
            $cursor = $payday->modify('+' . (intdiv($elapsed, $days) * $days) . ' days');
        }
        $next = $cursor->modify('+' . $days . ' days');

        return ['start' => $cursor->format('Y-m-d'), 'next' => $next->format('Y-m-d'), 'rolled' => false];
    }

    /** @return array{start:string, next:?string, rolled:bool} */
    private static function monthly(\DateTimeImmutable $payday, \DateTimeImmutable $today): array
    {
        $anchor = (int) $payday->format('j');
        $cursor = $payday;
        if ($today < $payday) {
            return self::span($cursor, self::monthAfter($cursor, $anchor));
        }

        for ($i = 0; $i < 2400; $i++) {
            $next = self::monthAfter($cursor, $anchor);
            if ($today < $next) {
                return self::span($cursor, $next);
            }
            $cursor = $next;
        }

        throw new \RuntimeException('Could not place the pay period.');
    }

    /** @return array{start:string, next:?string, rolled:bool} */
    private static function semimonthly(\DateTimeImmutable $payday, int $secondDay, \DateTimeImmutable $today): array
    {
        $firstDay = (int) $payday->format('j');
        $cursor = $payday;
        if ($today < $payday) {
            return self::span($cursor, self::nextHalf($cursor, $firstDay, $secondDay));
        }

        for ($i = 0; $i < 2400; $i++) {
            $next = self::nextHalf($cursor, $firstDay, $secondDay);
            if ($today < $next) {
                return self::span($cursor, $next);
            }
            $cursor = $next;
        }

        throw new \RuntimeException('Could not place the pay period.');
    }

    private static function monthAfter(\DateTimeImmutable $cursor, int $anchor): \DateTimeImmutable
    {
        $month = $cursor->modify('first day of next month');

        return self::clamp((int) $month->format('Y'), (int) $month->format('n'), $anchor);
    }

    private static function nextHalf(\DateTimeImmutable $current, int $firstDay, int $secondDay): \DateTimeImmutable
    {
        $best = null;
        for ($i = 0; $i <= 2; $i++) {
            $month = $current->modify('first day of this month')->modify('+' . $i . ' months');
            foreach ([$firstDay, $secondDay] as $day) {
                $candidate = self::clamp((int) $month->format('Y'), (int) $month->format('n'), $day);
                if ($candidate > $current && ($best === null || $candidate < $best)) {
                    $best = $candidate;
                }
            }
        }
        if (!$best instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Could not place the pay period.');
        }

        return $best;
    }

    /** @return array{start:string, next:?string, rolled:bool} */
    private static function span(\DateTimeImmutable $start, \DateTimeImmutable $next): array
    {
        return ['start' => $start->format('Y-m-d'), 'next' => $next->format('Y-m-d'), 'rolled' => false];
    }

    private static function clamp(int $year, int $month, int $day): \DateTimeImmutable
    {
        $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $use = min($day, (int) $cursor->format('t'));

        return $cursor->setDate($year, $month, $use);
    }
}
