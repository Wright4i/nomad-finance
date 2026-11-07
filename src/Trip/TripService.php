<?php

declare(strict_types=1);

namespace App\Trip;

use App\Auth\Actor;
use App\Category\SystemBuckets;
use App\Envelope\BillFill;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Posting\Stretch;
use App\Support\Dates;
use PDO;

final class TripService
{
    /** @var array<int, array{miles:?int,milesPerGallon:?string,costMinor:?int,priceMinor:?int}>|null */
    private ?array $fuelByTrip = null;

    /** @var array<int, array{moveEarlier:bool,moveLater:bool}>|null */
    private ?array $moveByTrip = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly CurrencyCatalog $currencies,
        private readonly ?Drive $drive = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $today, bool $withFuel = true): array
    {
        $today = $this->dayOrToday($today);
        $this->fuelByTrip = $withFuel ? null : [];
        $this->moveByTrip = null;
        $stmt = $this->pdo->prepare(
            'SELECT t.*, a.name AS account_name, a.currency, a.side, a.balance_minor, a.credit_limit_minor, a.type AS account_type
             FROM trips t
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE t.user_id = ?
             ORDER BY t.arrive_on, t.depart_on, t.place_name COLLATE NOCASE, t.id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        $listed = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $listed[] = $this->present($row, $today, count($listed));
            }
        }

        return $listed;
    }

    public function get(int $id, string $today): array
    {
        $this->fuelByTrip = null;
        $this->moveByTrip = null;

        return $this->present($this->row($id), $this->dayOrToday($today));
    }

    /**
     * Route miles between stays, in route order.
     * The same miles feed the fuel label.
     *
     * @return list<array{fromId:int,toId:int,miles:int,driving:bool,travel:string,coordinates:list<array{0:float,1:float}>}>
     */
    public function route(string $today): array
    {
        $this->dayOrToday($today);

        return $this->roads()->legs($this->stops());
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $data = $this->validate($input, null);
        $place = $this->locality($input, null, $data);
        $routeOrder = $this->routeSlot($data['arriveOn']);
        $today = $this->dayOrToday(is_string($input['today'] ?? null) ? $input['today'] : '');
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, note, arrive_on, depart_on, latitude, longitude,
                city, region, address, account_id, total_minor, amount_due_minor, balance_due_on,
                rating_half_stars, rating_comment, travel, route_order, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->actor->id(),
            $data['placeName'],
            $data['note'],
            $data['arriveOn'],
            $data['departOn'],
            $data['latitude'],
            $data['longitude'],
            $place['city'],
            $place['region'],
            $place['address'],
            $data['accountId'],
            $data['totalMinor'],
            $data['amountDueMinor'],
            $data['balanceDueOn'],
            $data['ratingHalfStars'],
            $data['ratingComment'],
            $data['travel'],
            $routeOrder,
            $now,
            $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->syncDueBill($id, $data, $today);

        return $this->get($id, $today);
    }

    public function rememberLocality(int $id, string $city, ?string $region, ?string $address, string $today): array
    {
        $this->row($id);
        $this->pdo->prepare(
            'UPDATE trips SET city = ?, region = ?, address = ?, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$city, $region, $address, gmdate('c'), $id, $this->actor->id()]);

        return $this->get($id, $this->dayOrToday($today));
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $existing = $this->row($id);
        $data = $this->validate($input, $existing);
        $place = $this->locality($input, $existing, $data);
        $today = $this->dayOrToday(is_string($input['today'] ?? null) ? $input['today'] : '');
        $now = gmdate('c');
        $this->pdo->prepare(
                'UPDATE trips SET
                    place_name = ?, note = ?, arrive_on = ?, depart_on = ?, latitude = ?, longitude = ?,
                    city = ?, region = ?, address = ?, account_id = ?, total_minor = ?, amount_due_minor = ?,
                    balance_due_on = ?, rating_half_stars = ?, rating_comment = ?, travel = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            )->execute([
                $data['placeName'],
                $data['note'],
                $data['arriveOn'],
                $data['departOn'],
                $data['latitude'],
                $data['longitude'],
                $place['city'],
                $place['region'],
                $place['address'],
                $data['accountId'],
                $data['totalMinor'],
                $data['amountDueMinor'],
                $data['balanceDueOn'],
                $data['ratingHalfStars'],
                $data['ratingComment'],
                $data['travel'],
                $now,
                $id,
                $this->actor->id(),
            ]);
        $this->syncDueBill($id, $data, $today);

        return $this->get($id, $today);
    }

    public function move(int $id, string $direction): void
    {
        if ($direction !== 'earlier' && $direction !== 'later') {
            throw new ApiException(422, 'Move the stay earlier or later on the route.');
        }
        $this->row($id);
        $this->moveByTrip = null;
        $stmt = $this->pdo->prepare(
            'SELECT id, route_order, arrive_on, depart_on FROM trips
             WHERE user_id = ?
             ORDER BY route_order, id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'routeOrder' => (int) $row['route_order'],
                'arriveOn' => (string) $row['arrive_on'],
                'departOn' => (string) $row['depart_on'],
            ];
        }
        $index = null;
        foreach ($rows as $at => $row) {
            if ($row['id'] === $id) {
                $index = $at;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $neighbor = $direction === 'earlier' ? $index - 1 : $index + 1;
        if ($neighbor < 0 || $neighbor >= count($rows)) {
            return;
        }
        $current = $rows[$index];
        $other = $rows[$neighbor];
        if (!StayRoute::orderable($current, $other)) {
            return;
        }
        $this->pdo->prepare('UPDATE trips SET route_order = ? WHERE id = ? AND user_id = ?')->execute([
            $other['routeOrder'],
            $current['id'],
            $this->actor->id(),
        ]);
        $this->pdo->prepare('UPDATE trips SET route_order = ? WHERE id = ? AND user_id = ?')->execute([
            $current['routeOrder'],
            $other['id'],
            $this->actor->id(),
        ]);
        $this->moveByTrip = null;
        $this->fuelByTrip = null;
    }

    public function delete(int $id, bool $confirm): void
    {
        $this->row($id);
        if (!$confirm) {
            throw new ApiException(409, 'Confirm before deleting this stay.');
        }
        $this->pdo->prepare('DELETE FROM bills WHERE trip_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM trips WHERE id = ? AND user_id = ?')->execute([$id, $this->actor->id()]);
        $this->refill(gmdate('Y-m-d'));
    }

    public static function status(string $arrive, string $depart, string $today): string
    {
        if ($today < $arrive) {
            return 'upcoming';
        }
        if ($today > $depart) {
            return 'done';
        }

        return 'underway';
    }

    /**
     * One lodging bill for a stay that still has an amount due and a balance-due date.
     * The same row is updated. Clearing either field removes it. The account balance stays put.
     *
     * @param array<string, mixed> $data
     */
    private function syncDueBill(int $tripId, array $data, string $today): void
    {
        $amount = (int) $data['amountDueMinor'];
        $dueOn = is_string($data['balanceDueOn']) ? $data['balanceDueOn'] : '';
        $accountId = $data['accountId'];
        $accountId = is_int($accountId) ? $accountId : null;
        $stmt = $this->pdo->prepare('SELECT id FROM bills WHERE trip_id = ?');
        $stmt->execute([$tripId]);
        $found = $stmt->fetchColumn();
        $billId = $found === false ? null : (int) $found;
        if ($amount < 1 || $dueOn === '' || $accountId === null) {
            if ($billId !== null) {
                $this->pdo->prepare('DELETE FROM bills WHERE id = ? AND trip_id = ?')->execute([$billId, $tripId]);
                $this->refill($today);
            }

            return;
        }
        $currencyStmt = $this->pdo->prepare('SELECT currency FROM accounts WHERE id = ? AND user_id = ?');
        $currencyStmt->execute([$accountId, $this->actor->id()]);
        $currency = $currencyStmt->fetchColumn();
        $currency = is_string($currency) && $currency !== '' ? $currency : 'USD';
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), 'lodging');
        $now = gmdate('c');
        $name = (string) $data['placeName'];
        if ($billId === null) {
            $this->pdo->prepare(
                'INSERT INTO bills (
                    name, type, account_id, category_id, amount_minor, currency,
                    recurrence, anchor_date, next_due_date, autopay, status, created_at, updated_at, trip_id
                 ) VALUES (?, \'lodging\', ?, ?, ?, ?, \'once\', ?, ?, 0, \'active\', ?, ?, ?)'
            )->execute([$name, $accountId, $categoryId, $amount, $currency, $dueOn, $dueOn, $now, $now, $tripId]);
        } else {
            $this->pdo->prepare(
                'UPDATE bills SET
                    name = ?, account_id = ?, category_id = ?, amount_minor = ?, currency = ?,
                    anchor_date = ?, next_due_date = ?, updated_at = ?
                 WHERE id = ? AND trip_id = ?'
            )->execute([$name, $accountId, $categoryId, $amount, $currency, $dueOn, $dueOn, $now, $billId, $tripId]);
        }
        $this->refill($today);
    }

    private function refill(string $today): void
    {
        (new BillFill($this->pdo, $this->currencies, $this->actor, $this->drive ?? new Drive(true)))->apply($today);
    }

    /** @param array<string, mixed> $input
     *  @param array<string, mixed>|null $existing
     *  @return array<string, mixed>
     */
    private function validate(array $input, ?array $existing = null): array
    {
        $errors = [];
        $place = trim(is_string($input['placeName'] ?? null) ? $input['placeName'] : '');
        if ($place === '' || mb_strlen($place) > 80) {
            $errors['placeName'] = 'Use a place name of 1 to 80 characters.';
        }
        $note = $this->optionalText($input['note'] ?? null, 2000);
        if ($note === false) {
            $errors['note'] = 'Keep the note to 2000 characters.';
            $note = null;
        }
        $arrive = $this->day(is_string($input['arriveOn'] ?? null) ? $input['arriveOn'] : '');
        $depart = $this->day(is_string($input['departOn'] ?? null) ? $input['departOn'] : '');
        if ($arrive === null) {
            $errors['arriveOn'] = 'Use a YYYY-MM-DD arrival.';
        }
        if ($depart === null) {
            $errors['departOn'] = 'Use a YYYY-MM-DD departure.';
        }
        if ($arrive !== null && $depart !== null && $depart < $arrive) {
            $errors['departOn'] = 'Departure is on or after arrival.';
        }
        $balanceDue = $arrive;
        if (array_key_exists('balanceDueOn', $input)) {
            if ($input['balanceDueOn'] === null || $input['balanceDueOn'] === '') {
                $balanceDue = null;
            } else {
                $balanceDue = $this->day(is_string($input['balanceDueOn']) ? $input['balanceDueOn'] : '');
                if ($balanceDue === null) {
                    $errors['balanceDueOn'] = 'Use a YYYY-MM-DD date.';
                }
            }
        }
        $latitude = $this->coordinate($input['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($input['longitude'] ?? null, -180, 180);
        if ($latitude === null) {
            $errors['latitude'] = 'Enter a latitude, or search for the place.';
        }
        if ($longitude === null) {
            $errors['longitude'] = 'Enter a longitude, or search for the place.';
        }
        $total = $this->asInt($input['totalMinor'] ?? null);
        $due = $this->asInt($input['amountDueMinor'] ?? null);
        if ($total === null || $total < 0) {
            $errors['totalMinor'] = 'Enter a total of zero or more.';
            $total = 0;
        }
        if ($due === null || $due < 0) {
            $errors['amountDueMinor'] = 'Enter an amount due of zero or more.';
            $due = 0;
        }
        if ($due > $total) {
            $errors['amountDueMinor'] = 'Amount due cannot be above the total.';
        }
        $accountId = null;
        if ($total > 0) {
            $accountId = $this->asInt($input['accountId'] ?? null);
            if ($accountId === null || !$this->openAccount($accountId)) {
                $errors['accountId'] = 'Choose an account that is still on the list.';
                $accountId = null;
            }
        }
        $rated = $this->rating($input, $existing, $errors);
        $travel = $this->travel($input, $existing, $errors);
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'placeName' => $place,
            'note' => is_string($note) ? $note : null,
            'arriveOn' => $arrive,
            'departOn' => $depart,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accountId' => $accountId,
            'totalMinor' => $total,
            'amountDueMinor' => $due,
            'balanceDueOn' => $balanceDue,
            'travel' => $travel,
            'ratingHalfStars' => $rated['ratingHalfStars'],
            'ratingComment' => $rated['ratingComment'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @param array<string, string> $errors
     */
    private function travel(array $input, ?array $existing, array &$errors): string
    {
        $travel = 'drive';
        if (is_array($existing) && ($existing['travel'] ?? '') === 'fly') {
            $travel = 'fly';
        }
        if (!array_key_exists('travel', $input)) {
            return $travel;
        }
        $raw = $input['travel'];
        if ($raw !== 'drive' && $raw !== 'fly') {
            $errors['travel'] = 'Choose drive or fly.';

            return 'drive';
        }

        return $raw;
    }

    /**
     * Half-stars are an integer 0 through 10. Null is unrated.
     * A comment is kept only with a rating.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @param array<string, string> $errors
     * @return array{ratingHalfStars: ?int, ratingComment: ?string}
     */
    private function rating(array $input, ?array $existing, array &$errors): array
    {
        $rating = null;
        $comment = null;
        if ($existing !== null) {
            $stored = $existing['rating_half_stars'] ?? null;
            $rating = $stored === null || $stored === '' ? null : (int) $stored;
            $storedComment = $existing['rating_comment'] ?? null;
            $comment = is_string($storedComment) && $storedComment !== '' ? $storedComment : null;
        }
        if (array_key_exists('ratingHalfStars', $input)) {
            $raw = $input['ratingHalfStars'];
            if ($raw === null || $raw === '') {
                $rating = null;
            } else {
                $parsed = $this->asInt($raw);
                if ($parsed === null || $parsed < 0 || $parsed > 10) {
                    $errors['ratingHalfStars'] = 'Choose a rating from 0 to 5 stars, in half stars.';
                } else {
                    $rating = $parsed;
                }
            }
        }
        if (array_key_exists('ratingComment', $input)) {
            $parsed = $this->optionalText($input['ratingComment'], 2000);
            if ($parsed === false) {
                $errors['ratingComment'] = 'Keep the comment to 2000 characters.';
            } else {
                $comment = $parsed;
            }
        }
        if (!isset($errors['ratingHalfStars']) && !isset($errors['ratingComment']) && $rating === null && is_string($comment) && $comment !== '') {
            $errors['ratingComment'] = 'Add a rating before a comment.';
        }
        if ($rating === null) {
            $comment = null;
        }

        return [
            'ratingHalfStars' => $rating,
            'ratingComment' => $comment,
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, a.name AS account_name, a.currency, a.side, a.balance_minor, a.credit_limit_minor, a.type AS account_type
             FROM trips t
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE t.id = ? AND t.user_id = ?'
        );
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Trip not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function present(array $row, string $today, ?int $index = null): array
    {
        $deposit = (int) $row['total_minor'] - (int) $row['amount_due_minor'];
        $currency = $row['currency'] === null ? null : 'USD';

        return [
            'id' => (int) $row['id'],
            'placeName' => (string) $row['place_name'],
            'note' => $row['note'],
            'ratingHalfStars' => ($row['rating_half_stars'] ?? null) === null || $row['rating_half_stars'] === ''
                ? null
                : (int) $row['rating_half_stars'],
            'ratingComment' => is_string($row['rating_comment'] ?? null) && $row['rating_comment'] !== ''
                ? $row['rating_comment']
                : null,
            'arriveOn' => (string) $row['arrive_on'],
            'departOn' => (string) $row['depart_on'],
            'balanceDueOn' => $row['balance_due_on'] === null || $row['balance_due_on'] === ''
                ? null
                : (string) $row['balance_due_on'],
            'latitude' => (string) $row['latitude'],
            'longitude' => (string) $row['longitude'],
            'city' => is_string($row['city'] ?? null) && $row['city'] !== '' ? $row['city'] : null,
            'region' => is_string($row['region'] ?? null) && $row['region'] !== '' ? $row['region'] : null,
            'address' => is_string($row['address'] ?? null) && $row['address'] !== '' ? $row['address'] : null,
            'status' => self::status((string) $row['arrive_on'], (string) $row['depart_on'], $today),
            'accountId' => $row['account_id'] === null ? null : (int) $row['account_id'],
            'accountName' => $row['account_name'],
            'currency' => $currency,
            'exponent' => 2,
            'totalMinor' => (int) $row['total_minor'],
            'amountDueMinor' => (int) $row['amount_due_minor'],
            'travel' => ($row['travel'] ?? 'drive') === 'fly' ? 'fly' : 'drive',
            'routeOrder' => (int) ($row['route_order'] ?? 0),
            'moveEarlier' => $this->movesFor((int) $row['id'])['moveEarlier'],
            'moveLater' => $this->movesFor((int) $row['id'])['moveLater'],
            'depositMinor' => $deposit,
            'depositCharged' => false,
            'dueCharged' => false,
            'otherSpentMinor' => 0,
            'fuel' => $this->fuelFor((int) $row['id']),
            'color' => StayTone::name($index ?? $this->colorIndex((int) $row['id'])),
        ];
    }

    private function colorIndex(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM trips
             WHERE user_id = ?
             ORDER BY arrive_on, depart_on, place_name COLLATE NOCASE, id'
        );
        $stmt->execute([$this->actor->id()]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach (is_array($ids) ? $ids : [] as $index => $tripId) {
            if ((int) $tripId === $id) {
                return (int) $index;
            }
        }

        return 0;
    }

    /**
     * Fuel estimates for stays that arrive in the accounting period, inclusive.
     * A flight, a first stay, and a drive with no miles or no price add nothing.
     *
     * @return list<array{id:int,name:string,costMinor:int}>
     */
    public function fuelEstimates(string $start, string $end, ?string $today = null): array
    {
        $this->fuelByTrip = null;
        $map = $this->fuelMap();
        $stmt = $this->pdo->prepare(
            'SELECT id, place_name, arrive_on, depart_on FROM trips
             WHERE user_id = ? AND arrive_on >= ? AND arrive_on <= ?
             ORDER BY arrive_on, depart_on, place_name COLLATE NOCASE, id'
        );
        $stmt->execute([$this->actor->id(), $start, $end]);
        $listed = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            if (
                $today !== null
                && self::status((string) $row['arrive_on'], (string) $row['depart_on'], $today) === 'done'
            ) {
                continue;
            }
            $cost = $map[(int) $row['id']]['costMinor'] ?? null;
            if (!is_int($cost) || $cost < 1) {
                continue;
            }
            $listed[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['place_name'],
                'costMinor' => $cost,
            ];
        }

        return $listed;
    }

    /** @return array{miles:?int,milesPerGallon:?string,costMinor:?int,priceMinor:?int} */
    private function fuelFor(int $id): array
    {
        $map = $this->fuelMap();

        return $map[$id] ?? ['miles' => null, 'milesPerGallon' => null, 'costMinor' => null, 'priceMinor' => null];
    }

    /** @return array<int, array{miles:?int,milesPerGallon:?string,costMinor:?int,priceMinor:?int}> */
    private function fuelMap(): array
    {
        if ($this->fuelByTrip !== null) {
            return $this->fuelByTrip;
        }
        $stops = $this->stops();
        $drive = $this->roads();
        $milesByTo = [];
        foreach ($drive->legs($stops) as $leg) {
            if (($leg['travel'] ?? 'drive') === 'fly') {
                continue;
            }
            if ($drive->usesRoads() && $leg['driving'] !== true) {
                continue;
            }
            $milesByTo[$leg['toId']] = $leg['miles'];
        }
        $mpgTenths = $this->starredMpg();
        $price = $this->fuelPrice();
        $map = [];
        foreach ($stops as $index => $stop) {
            $miles = $index === 0 ? null : ($milesByTo[$stop['id']] ?? null);
            $map[$stop['id']] = Fuel::estimate($miles, $mpgTenths, $price);
        }

        return $this->fuelByTrip = $map;
    }

    /** @return list<array{id:int,latitude:string,longitude:string,travel:string}> */
    private function stops(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, latitude, longitude, travel FROM trips
             WHERE user_id = ?
             ORDER BY route_order, id'
        );
        $stmt->execute([$this->actor->id()]);
        $stops = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $stops[] = [
                'id' => (int) $row['id'],
                'latitude' => (string) $row['latitude'],
                'longitude' => (string) $row['longitude'],
                'travel' => ($row['travel'] ?? 'drive') === 'fly' ? 'fly' : 'drive',
            ];
        }

        return $stops;
    }

    private function fuelPrice(): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, fuel_price_minor FROM vehicles WHERE user_id = ? AND starred = 1'
        );
        $stmt->execute([$this->actor->id()]);
        $vehicle = $stmt->fetch();
        if (!is_array($vehicle)) {
            return null;
        }
        $recent = $this->pdo->prepare(
            'SELECT b.price_per_gallon_minor
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.vehicle_id = ? AND b.type = \'fuel\'
               AND b.status IN (\'active\', \'paused\', \'paid\')
               AND b.price_per_gallon_minor IS NOT NULL
             ORDER BY b.anchor_date DESC, b.id DESC
             LIMIT 1'
        );
        $recent->execute([$this->actor->id(), (int) $vehicle['id']]);
        $logged = $recent->fetchColumn();
        if ($logged !== false && $logged !== null && (int) $logged > 0) {
            return (int) $logged;
        }
        $fallback = $vehicle['fuel_price_minor'];
        if ($fallback === null || $fallback === '') {
            return null;
        }
        $price = (int) $fallback;

        return $price > 0 ? $price : null;
    }

    private function roads(): Drive
    {
        return $this->drive ?? new Drive(true);
    }

    private function starredMpg(): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, mpg_min_tenths, mpg_max_tenths FROM vehicles WHERE user_id = ? AND starred = 1'
        );
        $stmt->execute([$this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $pooled = Fuel::pooled(
            $this->fuelFillUps((int) $row['id']),
            (int) $row['mpg_min_tenths'],
            (int) $row['mpg_max_tenths'],
        );

        return $pooled ?? Fuel::midpoint((int) $row['mpg_min_tenths'], (int) $row['mpg_max_tenths']);
    }

    /** @return list<array{miles:?int,gallons:?string,milesPerGallon:?string}> */
    private function fuelFillUps(int $vehicleId): array
    {
        $origin = $this->pdo->prepare('SELECT origin_odometer_miles FROM vehicles WHERE id = ? AND user_id = ?');
        $origin->execute([$vehicleId, $this->actor->id()]);
        $originMiles = $origin->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.amount_minor, b.odometer_miles, b.price_per_gallon_minor, b.vehicle_id
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.vehicle_id = ? AND b.type = \'fuel\' AND b.status IN (\'active\', \'paused\', \'paid\')
             ORDER BY b.anchor_date, b.id'
        );
        $stmt->execute([$this->actor->id(), $vehicleId]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'amountMinor' => (int) $row['amount_minor'],
                'odometerMiles' => $row['odometer_miles'] === null ? null : (int) $row['odometer_miles'],
                'pricePerGallonMinor' => $row['price_per_gallon_minor'] === null ? null : (int) $row['price_per_gallon_minor'],
                'vehicleId' => (int) $row['vehicle_id'],
            ];
        }

        return Stretch::fillUps($rows, $originMiles === false || $originMiles === null ? null : (int) $originMiles);
    }

    private function routeSlot(string $arriveOn): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT route_order, arrive_on FROM trips WHERE user_id = ? ORDER BY route_order, id'
        );
        $stmt->execute([$this->actor->id()]);
        $slot = 0;
        $insertAt = null;
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $order = (int) $row['route_order'];
            if ($insertAt === null && (string) $row['arrive_on'] > $arriveOn) {
                $insertAt = $order;
            }
            $slot = $order + 1;
        }
        if ($insertAt === null) {
            return $slot;
        }
        $this->pdo->prepare(
            'UPDATE trips SET route_order = route_order + 1 WHERE user_id = ? AND route_order >= ?'
        )->execute([$this->actor->id(), $insertAt]);

        return $insertAt;
    }

    /** @return array{moveEarlier:bool,moveLater:bool} */
    private function movesFor(int $id): array
    {
        if ($this->moveByTrip === null) {
            $this->moveByTrip = $this->loadMoves();
        }

        return $this->moveByTrip[$id] ?? ['moveEarlier' => false, 'moveLater' => false];
    }

    /** @return array<int, array{moveEarlier:bool,moveLater:bool}> */
    private function loadMoves(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, arrive_on, depart_on FROM trips WHERE user_id = ? ORDER BY route_order, id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'arriveOn' => (string) $row['arrive_on'],
                'departOn' => (string) $row['depart_on'],
            ];
        }
        $map = [];
        $count = count($rows);
        foreach ($rows as $index => $row) {
            $map[$row['id']] = [
                'moveEarlier' => $index > 0 && StayRoute::orderable($row, $rows[$index - 1]),
                'moveLater' => $index + 1 < $count && StayRoute::orderable($row, $rows[$index + 1]),
            ];
        }

        return $map;
    }

    private function openAccount(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM accounts WHERE id = ? AND user_id = ? AND archived_at IS NULL');
        $stmt->execute([$id, $this->actor->id()]);

        return $stmt->fetch() !== false;
    }

    private function day(string $value): ?string
    {
        try {
            return Dates::parse($value)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function dayOrToday(string $value): string
    {
        return $this->day($value) ?? gmdate('Y-m-d');
    }

    private function coordinate(mixed $value, float $min, float $max): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return null;
        }
        $number = (float) $text;
        if ($number < $min || $number > $max) {
            return null;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $data
     * @return array{city:?string,region:?string,address:?string}
     */
    private function locality(array $input, ?array $existing, array $data): array
    {
        $moved = $existing !== null && (
            (string) $existing['latitude'] !== $data['latitude']
            || (string) $existing['longitude'] !== $data['longitude']
        );
        $city = $this->keptPlace($input, 'city', 80, $existing, $moved);
        $region = $this->keptPlace($input, 'region', 8, $existing, $moved);
        $address = $this->keptPlace($input, 'address', 160, $existing, $moved);
        if ($city === false) {
            throw new ApiException(422, 'Check the highlighted fields.', ['city' => 'Keep the city to 80 characters.']);
        }
        if ($region === false) {
            throw new ApiException(422, 'Check the highlighted fields.', ['region' => 'Keep the state to 8 characters.']);
        }
        if ($address === false) {
            throw new ApiException(422, 'Check the highlighted fields.', ['address' => 'Keep the address to 160 characters.']);
        }
        if (!is_string($city)) {
            return ['city' => null, 'region' => null, 'address' => null];
        }

        return [
            'city' => $city,
            'region' => is_string($region) ? $region : null,
            'address' => is_string($address) ? $address : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function keptPlace(array $input, string $key, int $max, ?array $existing, bool $moved): string|null|false
    {
        if (array_key_exists($key, $input)) {
            return $this->optionalText($input[$key], $max);
        }
        if ($existing === null || $moved) {
            return null;
        }
        $stored = $existing[$key] ?? null;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    private function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private function optionalText(mixed $value, int $max): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            return false;
        }

        return $text;
    }
}
