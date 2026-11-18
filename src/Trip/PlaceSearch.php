<?php

declare(strict_types=1);

namespace App\Trip;

final class PlaceSearch
{
    /** @return list<array{name:string,latitude:string,longitude:string,city:?string,region:?string,address:?string}> */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=5&accept-language=en&q=' . rawurlencode($query);
        $decoded = $this->fetch($url);
        if ($decoded === null) {
            return [];
        }
        $places = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['lat'], $row['lon'], $row['display_name'])) {
                continue;
            }
            $locality = Locality::fromAddress(is_array($row['address'] ?? null) ? $row['address'] : []);
            $places[] = [
                'name' => (string) $row['display_name'],
                'latitude' => (string) $row['lat'],
                'longitude' => (string) $row['lon'],
                'city' => $locality === null ? null : $locality['city'],
                'region' => $locality === null ? null : $locality['region'],
                'address' => $locality === null ? null : $locality['address'],
            ];
        }

        return $places;
    }

    /** @return array{city:string,region:?string,address:string}|null */
    public function reverse(string $latitude, string $longitude): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat='
            . rawurlencode($latitude) . '&lon=' . rawurlencode($longitude);
        $decoded = $this->fetch($url);
        if (!is_array($decoded) || !is_array($decoded['address'] ?? null)) {
            return null;
        }

        return Locality::fromAddress($decoded['address']);
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'header' => "User-Agent: NomadFinance/1.0 (personal finance register)\r\nAccept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
