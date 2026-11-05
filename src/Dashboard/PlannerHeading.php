<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * The Planner heading. A label only: it does not move a balance or pick a stay.
 */
final class PlannerHeading
{
    /** @var list<string> */
    public const PHRASES = [
        'Welcome to %s',
        'Hello from %s',
        'Settled in %s',
        'Good to be in %s',
    ];

    /**
     * Stays are in arrival order, the same order as the trips list.
     * The open stay is the first one whose dates cover today.
     * A handoff day keeps that earlier stay.
     *
     * @param list<array<string, mixed>> $trips
     * @return array{text:string,italic:bool}
     */
    public static function label(array $trips, string $today): array
    {
        foreach ($trips as $trip) {
            $arrive = (string) ($trip['arriveOn'] ?? '');
            $depart = (string) ($trip['departOn'] ?? '');
            if ($arrive === '' || $depart === '' || $today < $arrive || $today > $depart) {
                continue;
            }
            $city = $trip['city'] ?? null;
            if (!is_string($city) || trim($city) === '') {
                return self::planner();
            }

            return [
                'text' => self::phrase((int) ($trip['id'] ?? 0), trim($city)),
                'italic' => true,
            ];
        }

        return self::planner();
    }

    public static function phrase(int $stayId, string $city): string
    {
        $count = count(self::PHRASES);
        $index = $stayId % $count;
        if ($index < 0) {
            $index += $count;
        }

        return sprintf(self::PHRASES[$index], $city);
    }

    /** @return array{text:string,italic:bool} */
    private static function planner(): array
    {
        return ['text' => 'Planner', 'italic' => false];
    }
}
