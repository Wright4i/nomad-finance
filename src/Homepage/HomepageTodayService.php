<?php

declare(strict_types=1);

namespace App\Homepage;

use App\Dashboard\PlannerWidgets;
use App\Support\Dates;
use PDO;

final class HomepageTodayService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $timezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(string $on = ''): array
    {
        $today = $on !== '' ? Dates::parse($on)->format('Y-m-d') : $this->today();
        $todayLabel = $this->todayLabel($today);
        $ownerId = $this->ownerId();
        if ($ownerId === null) {
            return [
                'ready' => false,
                'today' => $today,
                'todayLabel' => $todayLabel,
                'stay' => '',
                'entryCount' => 0,
                'entries' => [],
                'line1' => '',
                'line2' => '',
                'line3' => '',
                'summary' => 'Setup not finished',
                'itemCount' => 0,
                'preview' => '',
            ];
        }

        $entries = $this->entriesOn($ownerId, $today);
        $stay = PlannerWidgets::stay($this->tripsFor($ownerId), $today);
        $stayLabel = StayCaption::label($stay);
        $lines = [];
        foreach ($entries as $entry) {
            $hint = EntryHint::forDay($entry['startOn'], $entry['endOn'], $today);
            $lines[] = $hint === '' ? $entry['text'] : $entry['text'] . ' (' . $hint . ')';
        }
        $shown = array_slice($lines, 0, 3);
        $extra = max(0, count($lines) - 3);

        return [
            'ready' => true,
            'today' => $today,
            'todayLabel' => $todayLabel,
            'stay' => $stayLabel,
            'entryCount' => count($entries),
            'entries' => array_map(
                static function (array $entry) use ($today): array {
                    return [
                        'id' => $entry['id'],
                        'text' => $entry['text'],
                        'startOn' => $entry['startOn'],
                        'endOn' => $entry['endOn'],
                        'hint' => EntryHint::forDay($entry['startOn'], $entry['endOn'], $today),
                    ];
                },
                $entries,
            ),
            'line1' => $shown[0] ?? ($entries === [] ? 'Nothing scheduled' : ''),
            'line2' => $shown[1] ?? '',
            'line3' => $shown[2] ?? '',
            'summary' => $this->summary(count($entries), $stayLabel, $extra),
            'itemCount' => ($stayLabel !== '' ? 1 : 0) + count($entries),
            'preview' => $this->preview($stay, $entries),
        ];
    }

    private function today(): string
    {
        try {
            $zone = new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            $zone = new \DateTimeZone('America/Los_Angeles');
        }

        return (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    private function todayLabel(string $today): string
    {
        try {
            $parsed = Dates::parse($today);

            return $parsed->format('D M j');
        } catch (\InvalidArgumentException) {
            return $today;
        }
    }

    private function ownerId(): ?int
    {
        $id = $this->pdo->query("SELECT id FROM user WHERE role = 'owner'")->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    /** @return list<array{id:int,text:string,startOn:string,endOn:string}> */
    private function entriesOn(int $ownerId, string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, text, start_on, end_on
             FROM calendar_entries
             WHERE user_id = ? AND start_on <= ? AND end_on >= ?
             ORDER BY start_on, end_on, id'
        );
        $stmt->execute([$ownerId, $today, $today]);
        $listed = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $listed[] = [
                'id' => (int) $row['id'],
                'text' => (string) $row['text'],
                'startOn' => (string) $row['start_on'],
                'endOn' => (string) $row['end_on'],
            ];
        }

        return $listed;
    }

    /** @return list<array<string, mixed>> */
    private function tripsFor(int $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, place_name, city, region, arrive_on, depart_on, latitude, longitude
             FROM trips
             WHERE user_id = ?
             ORDER BY arrive_on, depart_on, place_name COLLATE NOCASE, id'
        );
        $stmt->execute([$ownerId]);
        $listed = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $listed[] = [
                'id' => (int) $row['id'],
                'placeName' => (string) $row['place_name'],
                'city' => $row['city'],
                'region' => $row['region'],
                'arriveOn' => (string) $row['arrive_on'],
                'departOn' => (string) $row['depart_on'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
            ];
        }

        return $listed;
    }

    private function summary(int $entryCount, string $stay, int $extra): string
    {
        $parts = [];
        if ($entryCount === 0) {
            $parts[] = 'No notes';
        } elseif ($entryCount === 1) {
            $parts[] = '1 note';
        } else {
            $parts[] = $entryCount . ' notes';
        }
        if ($stay !== '') {
            $parts[] = 'stay ' . $stay;
        }
        if ($extra > 0) {
            $parts[] = '+' . $extra . ' more';
        }

        return implode(' · ', $parts);
    }

    /** @param list<array{text:string}> $entries */
    private function preview(?array $stay, array $entries): string
    {
        $parts = [];
        if ($stay !== null) {
            $name = trim((string) ($stay['placeName'] ?? ''));
            if ($name !== '') {
                $parts[] = $name;
            }
        }
        foreach ($entries as $entry) {
            $parts[] = $entry['text'];
            if (count($parts) >= 4) {
                break;
            }
        }
        $joined = implode(' · ', $parts);
        if (mb_strlen($joined) <= 96) {
            return $joined;
        }

        return rtrim(mb_substr($joined, 0, 93)) . '...';
    }
}
