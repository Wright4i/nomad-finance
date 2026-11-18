<?php

declare(strict_types=1);

namespace App\Dashboard;

final class PlannerHeading
{
    /** @var list<string> */
    public const PHRASES = [
        'Welcome to %s',
        'Hello from %s',
        'Settled in %s',
        'Good to be in %s',
        'Viva %s',
        'Good evening from %s',
        'Good morning from %s',
        'Happy days in %s',
        'Enjoying the sights in %s',
        'Living it up in %s',
        'Greetings from %s',
        'A fine day in %s',
        'At home in %s',
        'Checking in from %s',
        'A warm hello from %s',
        'Glad to be in %s',
        'Out and about in %s',
        'A good day in %s',
        'Notes from %s',
        'A postcard from %s',
        'The view from %s',
        'Life in %s',
        'These days in %s',
        'Today in %s',
        'This week in %s',
        'Quiet time in %s',
        'A quiet day in %s',
        'Sunshine in %s',
        'Fresh air in %s',
        'Slow days in %s',
        'Bright days in %s',
        'Easy days in %s',
        'A soft landing in %s',
        'Arrived in %s',
        'Here in %s',
        'Still here in %s',
        'Happy to be in %s',
        'Nice to be in %s',
        'Right at home in %s',
        'Making a home in %s',
        'Good news from %s',
        'Hello again from %s',
        'Back in %s',
        'Safe and well in %s',
        'Another day in %s',
        'A full day in %s',
        'Good afternoon from %s',
        'At ease in %s',
        'Staying a while in %s',
        'All is well in %s',
    ];

    /**
     * @param list<array<string, mixed>> $trips
     * @return array{text:string,italic:bool}
     */
    public static function label(array $trips, string $today, ?int $index = null): array
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
                'text' => self::phrase(trim($city), $index ?? random_int(0, count(self::PHRASES) - 1)),
                'italic' => true,
            ];
        }

        return self::planner();
    }

    public static function phrase(string $place, int $index): string
    {
        $place = trim($place);
        if ($place === '') {
            return 'Planner';
        }

        $count = count(self::PHRASES);
        $index %= $count;
        if ($index < 0) {
            $index += $count;
        }

        return sprintf(self::PHRASES[$index], $place);
    }

    /** @return array{text:string,italic:bool} */
    private static function planner(): array
    {
        return ['text' => 'Planner', 'italic' => false];
    }
}
