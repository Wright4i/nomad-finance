<?php

declare(strict_types=1);

namespace App\Trip;

final class Drive
{
    public function __construct(private readonly bool $roads = true)
    {
    }

    public function usesRoads(): bool
    {
        return $this->roads;
    }

    public static function miles(int $meters): int
    {
        if ($meters <= 0) {
            return 0;
        }

        return (int) floor(($meters / 1609.344) + 0.5);
    }

    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earth = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lon2 - $lon1);
        $a = sin($deltaPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round($earth * $c);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{meters:float,coordinates:list<array{0:float,1:float}>}|null
     */
    public static function geometry(array $decoded): ?array
    {
        if (($decoded['code'] ?? '') !== 'Ok' || !isset($decoded['routes'][0]) || !is_array($decoded['routes'][0])) {
            return null;
        }
        $route = $decoded['routes'][0];
        $meters = $route['distance'] ?? null;
        $coords = $route['geometry']['coordinates'] ?? null;
        if (!is_numeric($meters) || !is_array($coords)) {
            return null;
        }
        $line = [];
        foreach ($coords as $point) {
            if (!is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            $line[] = [(float) $point[0], (float) $point[1]];
        }
        if (count($line) < 2) {
            return null;
        }

        return ['meters' => (float) $meters, 'coordinates' => $line];
    }

    /**
     * @param list<array{
     * @return list<array{fromId:int,toId:int,miles:int,driving:bool,travel:string,coordinates:list<array{0:float,1:float}>}>
     */
    public function legs(array $stops): array
    {
        $legs = [];
        /** @var list<array{fromId:int,toId:int,miles:int,driving:bool,travel:string,coordinates:list<array{0:float,1:float}>}> $afterReturn */
        $afterReturn = [];
        $count = count($stops);
        for ($i = 1; $i < $count; $i++) {
            $from = $stops[$i - 1];
            $to = $stops[$i];
            $travel = ($to['travel'] ?? 'drive') === 'fly' ? 'fly' : 'drive';
            if ($travel === 'fly') {
                $legs[] = [
                    'fromId' => $from['id'],
                    'toId' => $to['id'],
                    'miles' => 0,
                    'driving' => false,
                    'travel' => 'fly',
                    'coordinates' => [],
                ];
                continue;
            }
            if (($from['travel'] ?? 'drive') === 'fly') {
                $base = $this->ongoingAtDepart($stops, $i - 1);
                if ($base !== null && !$this->relocationFromFlight($from, $to, $base)) {
                    if ($this->driveFromBaseAfterReturn($to, $base)) {
                        $afterReturn[] = $this->connect($base, $to);
                    }
                    continue;
                }
            }
            $legs[] = $this->connect($from, $to);
        }

        for ($i = 0; $i < $count; $i++) {
            $flight = $stops[$i];
            if (($flight['travel'] ?? 'drive') !== 'fly') {
                continue;
            }
            $next = $stops[$i + 1] ?? null;
            $base = $this->ongoingAtDepart($stops, $i);
            if ($base === null || ($next !== null && $this->relocationFromFlight($flight, $next, $base))) {
                continue;
            }
            $legs[] = [
                'fromId' => $flight['id'],
                'toId' => $base['id'],
                'miles' => 0,
                'driving' => false,
                'travel' => 'fly',
                'coordinates' => [],
            ];
        }

        foreach ($afterReturn as $leg) {
            $legs[] = $leg;
        }

        return $legs;
    }

    /**
     * @param array{id:int,latitude:string,longitude:string} $from
     * @param array{id:int,latitude:string,longitude:string} $to
     * @return array{fromId:int,toId:int,miles:int,driving:bool,travel:string,coordinates:list<array{0:float,1:float}>}
     */
    private function connect(array $from, array $to): array
    {
        $driven = $this->road($from, $to);
        if ($driven !== null) {
            return [
                'fromId' => $from['id'],
                'toId' => $to['id'],
                'miles' => self::miles((int) round($driven['meters'])),
                'driving' => true,
                'travel' => 'drive',
                'coordinates' => $driven['coordinates'],
            ];
        }
        if ($this->roads) {
            return [
                'fromId' => $from['id'],
                'toId' => $to['id'],
                'miles' => 0,
                'driving' => false,
                'travel' => 'drive',
                'coordinates' => [],
            ];
        }
        $meters = self::haversineMeters(
            (float) $from['latitude'],
            (float) $from['longitude'],
            (float) $to['latitude'],
            (float) $to['longitude'],
        );

        return [
            'fromId' => $from['id'],
            'toId' => $to['id'],
            'miles' => self::miles($meters),
            'driving' => false,
            'travel' => 'drive',
            'coordinates' => [],
        ];
    }

    /**
     * @param array{arrive_on?:string,depart_on?:string} $inner
     * @param array{arrive_on?:string,depart_on?:string} $outer
     */
    private function nestedInside(array $inner, array $outer): bool
    {
        $innerArrive = $inner['arrive_on'] ?? '';
        $innerDepart = $inner['depart_on'] ?? '';
        $outerArrive = $outer['arrive_on'] ?? '';
        $outerDepart = $outer['depart_on'] ?? '';
        if ($innerArrive === '' || $innerDepart === '' || $outerArrive === '' || $outerDepart === '') {
            return false;
        }

        return strcmp($innerArrive, $outerArrive) >= 0 && strcmp($innerDepart, $outerDepart) <= 0;
    }

    /**
     * @param array{travel?:string,depart_on?:string} $flight
     * @param array{travel?:string,arrive_on?:string,depart_on?:string} $next
     * @param array{arrive_on?:string,depart_on?:string} $base
     */
    private function relocationFromFlight(array $flight, array $next, array $base): bool
    {
        if (($next['travel'] ?? 'drive') === 'fly') {
            return false;
        }
        if ($this->nestedInside($next, $base)) {
            return false;
        }
        $baseDepart = $base['depart_on'] ?? '';
        $nextArrive = $next['arrive_on'] ?? '';
        $flightDepart = $flight['depart_on'] ?? '';
        if ($baseDepart === '' || $nextArrive === '' || $flightDepart === '') {
            return false;
        }
        if (strcmp($nextArrive, $baseDepart) >= 0) {
            return false;
        }

        return strcmp($nextArrive, $flightDepart) >= 0;
    }

    /** @param array{arrive_on?:string,depart_on?:string} $next */
    private function driveFromBaseAfterReturn(array $next, array $base): bool
    {
        if ($this->nestedInside($next, $base)) {
            return true;
        }
        $baseDepart = $base['depart_on'] ?? '';
        $nextArrive = $next['arrive_on'] ?? '';

        return $baseDepart !== '' && $nextArrive !== '' && strcmp($nextArrive, $baseDepart) >= 0;
    }

    /**
     * @param list<array{
     * @return array{id:int,latitude:string,longitude:string}|null
     */
    private function ongoingAtDepart(array $stops, int $flightIndex): ?array
    {
        $flight = $stops[$flightIndex];
        $depart = $flight['depart_on'] ?? '';
        $arrive = $flight['arrive_on'] ?? '';
        if ($depart === '' || $arrive === '') {
            return null;
        }
        for ($j = $flightIndex - 1; $j >= 0; $j--) {
            $stay = $stops[$j];
            $baseArrive = $stay['arrive_on'] ?? '';
            $baseDepart = $stay['depart_on'] ?? '';
            if ($baseArrive === '' || $baseDepart === '') {
                continue;
            }
            if (strcmp($arrive, $baseArrive) < 0) {
                continue;
            }
            if (strcmp($depart, $baseArrive) >= 0 && strcmp($depart, $baseDepart) <= 0) {
                return $stay;
            }
        }

        return null;
    }

    /**
     * @param array{id:int,latitude:string,longitude:string} $from
     * @param array{id:int,latitude:string,longitude:string} $to
     * @return array{meters:float,coordinates:list<array{0:float,1:float}>}|null
     */
    private function road(array $from, array $to): ?array
    {
        if (!$this->roads) {
            return null;
        }
        $url = 'https://router.project-osrm.org/route/v1/driving/'
            . rawurlencode($from['longitude']) . ',' . rawurlencode($from['latitude']) . ';'
            . rawurlencode($to['longitude']) . ',' . rawurlencode($to['latitude'])
            . '?overview=simplified&geometries=geojson';
        $first = $this->fetchRoad($url);
        if ($first !== null) {
            return $first;
        }
        if ($this->missIsFresh($url)) {
            return null;
        }
        $second = $this->fetchRoad($url);
        if ($second === null) {
            $this->noteMiss($url);
        }

        return $second;
    }

    /** @return array{meters:float,coordinates:list<array{0:float,1:float}>}|null */
    private function fetchRoad(string $url): ?array
    {
        $remembered = $this->rememberedRoad($url);
        if ($remembered !== null) {
            return $remembered;
        }
        if ($this->missIsFresh($url)) {
            return null;
        }
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
        $road = is_array($decoded) ? self::geometry($decoded) : null;
        if ($road !== null) {
            $this->rememberRoad($url, $road);
        }

        return $road;
    }

    /** @return array{meters:float,coordinates:list<array{0:float,1:float}>}|null */
    private function rememberedRoad(string $url): ?array
    {
        $path = self::roadCachePath($url);
        if (!is_file($path) || filemtime($path) < time() - 86400) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        return self::geometry([
            'code' => 'Ok',
            'routes' => [[
                'distance' => $decoded['meters'] ?? null,
                'geometry' => ['coordinates' => $decoded['coordinates'] ?? null],
            ]],
        ]);
    }

    /** @param array{meters:float,coordinates:list<array{0:float,1:float}>} $road */
    private function rememberRoad(string $url, array $road): void
    {
        $path = self::roadCachePath($url);
        $encoded = json_encode($road);
        if (!is_string($encoded)) {
            return;
        }
        $temporary = $path . '.tmp';
        if (@file_put_contents($temporary, $encoded) === false) {
            return;
        }
        @rename($temporary, $path);
    }

    private static function roadCachePath(string $url): string
    {
        return sys_get_temp_dir() . '/nomad-road-' . hash('sha256', $url) . '.json';
    }

    private function missIsFresh(string $url): bool
    {
        $path = self::roadCachePath($url) . '.miss';

        return is_file($path) && filemtime($path) >= time() - 900;
    }

    private function noteMiss(string $url): void
    {
        $path = self::roadCachePath($url) . '.miss';
        @file_put_contents($path, '1');
    }
}
