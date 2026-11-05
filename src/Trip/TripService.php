<?php

declare(strict_types=1);

namespace App\Trip;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Posting\Ledger;
use App\Posting\PostingService;
use App\Support\Dates;
use PDO;
use Throwable;

final class TripService
{
    /** @var array<int, array{miles:?int,milesPerGallon:?string,costMinor:?int,priceMinor:?int}>|null */
    private ?array $fuelByTrip = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly CurrencyCatalog $currencies,
        private readonly PostingService $postings,
        private readonly ?Drive $drive = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $today, bool $withFuel = true): array
    {
        $today = $this->dayOrToday($today);
        $this->fuelByTrip = $withFuel ? null : [];
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

        return $this->present($this->row($id), $this->dayOrToday($today));
    }

    /**
     * Route miles between stays, in arrival order.
     * The same miles feed the fuel label.
     *
     * @return list<array{fromId:int,toId:int,miles:int,driving:bool,coordinates:list<array{0:float,1:float}>}>
     */
    public function route(string $today): array
    {
        $this->dayOrToday($today);

        return $this->roads()->legs($this->stops());
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $data = $this->validate($input, null, null);
        $place = $this->locality($input, null, $data);
        $today = $this->dayOrToday(is_string($input['today'] ?? null) ? $input['today'] : '');
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, note, arrive_on, depart_on, latitude, longitude,
                city, region, address, account_id, total_minor, amount_due_minor, balance_due_on,
                rating_half_stars, rating_comment, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $now,
            $now,
        ]);

        return $this->get((int) $this->pdo->lastInsertId(), $today);
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
        $data = $this->validate($input, $id, $existing);
        $place = $this->locality($input, $existing, $data);
        $today = $this->dayOrToday(is_string($input['today'] ?? null) ? $input['today'] : '');
        $apply = ($input['applyCharge'] ?? false) === true || ($input['applyCharge'] ?? null) === 1 || ($input['applyCharge'] ?? null) === '1';
        $shift = $this->shift($existing, $data);
        if ($shift !== null && !$apply) {
            throw new ApiException(409, 'Confirm the new balance before changing this charge.', $this->quoteFields($shift));
        }
        $this->pdo->beginTransaction();
        try {
            if ($shift !== null) {
                $this->runShift($existing, $data, $shift, $today);
            }
            $now = gmdate('c');
            $this->pdo->prepare(
                'UPDATE trips SET
                    place_name = ?, note = ?, arrive_on = ?, depart_on = ?, latitude = ?, longitude = ?,
                    city = ?, region = ?, address = ?, account_id = ?, total_minor = ?, amount_due_minor = ?,
                    balance_due_on = ?, rating_half_stars = ?, rating_comment = ?, updated_at = ?
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
                $now,
                $id,
                $this->actor->id(),
            ]);
            $this->pdo->prepare(
                'UPDATE postings SET payee = ?, updated_at = ? WHERE user_id = ? AND trip_id = ? AND lodging_part IS NOT NULL'
            )->execute([$data['placeName'], $now, $this->actor->id(), $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id, $today);
    }

    /** @param array<string, mixed> $input */
    public function quote(int $id, array $input): array
    {
        $trip = $this->row($id);
        $part = is_string($input['part'] ?? null) ? $input['part'] : '';
        if (!in_array($part, ['deposit', 'due'], true)) {
            throw new ApiException(422, 'Choose the deposit or the amount due.', ['part' => 'Choose the deposit or the amount due.']);
        }
        $amount = $this->partAmount($trip, $part);
        if ($amount <= 0) {
            throw new ApiException(422, 'There is nothing to charge.');
        }
        if ($this->partPosting($id, $part) !== null) {
            throw new ApiException(409, 'That part is already charged.');
        }
        if ($trip['account_id'] === null) {
            throw new ApiException(422, 'Choose an account.');
        }

        return $this->postings->quoteOut((int) $trip['account_id'], $amount);
    }

    /** @param array<string, mixed> $input */
    public function apply(int $id, array $input): array
    {
        $trip = $this->row($id);
        $part = is_string($input['part'] ?? null) ? $input['part'] : '';
        if (!in_array($part, ['deposit', 'due'], true)) {
            throw new ApiException(422, 'Choose the deposit or the amount due.', ['part' => 'Choose the deposit or the amount due.']);
        }
        $categoryId = $this->asInt($input['categoryId'] ?? null) ?? 0;
        $today = $this->dayOrToday(is_string($input['today'] ?? null) ? $input['today'] : '');
        $amount = $this->partAmount($trip, $part);
        if ($amount <= 0 || $trip['account_id'] === null) {
            throw new ApiException(422, 'There is nothing to charge.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->postings->chargeLodging(
                $id,
                $part,
                (int) $trip['account_id'],
                $categoryId,
                $amount,
                (string) $trip['place_name'],
                $today,
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id, $today);
    }

    public function delete(int $id, bool $confirm): void
    {
        $this->row($id);
        if (!$confirm) {
            throw new ApiException(409, 'Confirm before deleting this stay.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->postings->reverseLodging($id, 'deposit');
            $this->postings->reverseLodging($id, 'due');
            $this->postings->detachStay($id);
            $this->pdo->prepare('DELETE FROM trips WHERE id = ? AND user_id = ?')->execute([$id, $this->actor->id()]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

    /** @param array<string, mixed> $input
     *  @param array<string, mixed>|null $existing
     *  @return array<string, mixed>
     */
    private function validate(array $input, ?int $exceptId, ?array $existing = null): array
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
        if ($arrive !== null && $depart !== null && $depart >= $arrive && !isset($errors['arriveOn']) && !isset($errors['departOn'])) {
            $other = $this->overlappingStay($arrive, $depart, $exceptId);
            if ($other !== null) {
                $errors['arriveOn'] = $other . ' already covers a night in these dates.';
            }
        }
        $balanceDue = $arrive;
        if (array_key_exists('balanceDueOn', $input) && $input['balanceDueOn'] !== null && $input['balanceDueOn'] !== '') {
            $balanceDue = $this->day(is_string($input['balanceDueOn']) ? $input['balanceDueOn'] : '');
            if ($balanceDue === null) {
                $errors['balanceDueOn'] = 'Use a YYYY-MM-DD date.';
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
            'ratingHalfStars' => $rated['ratingHalfStars'],
            'ratingComment' => $rated['ratingComment'],
        ];
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

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function shift(array $existing, array $data): ?array
    {
        $parts = [];
        foreach (['deposit', 'due'] as $part) {
            $posting = $this->partPosting((int) $existing['id'], $part);
            if ($posting === null) {
                continue;
            }
            $nextAmount = $part === 'deposit'
                ? (int) $data['totalMinor'] - (int) $data['amountDueMinor']
                : (int) $data['amountDueMinor'];
            $accountChanged = (int) $posting['account_id'] !== (int) ($data['accountId'] ?? 0);
            if ($nextAmount !== (int) $posting['amount_minor'] || $accountChanged) {
                $parts[$part] = [
                    'posting' => $posting,
                    'nextAmount' => $nextAmount,
                    'accountId' => $data['accountId'],
                ];
            }
        }

        return $parts === [] ? null : $parts;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     * @param array<string, mixed> $shift
     */
    private function runShift(array $existing, array $data, array $shift, string $today): void
    {
        $categoryId = 0;
        foreach ($shift as $part => $change) {
            $posting = $change['posting'];
            $categoryId = (int) $posting['category_id'];
            $this->postings->reverseLodging((int) $existing['id'], (string) $part);
            $amount = (int) $change['nextAmount'];
            if ($amount > 0 && $data['accountId'] !== null) {
                $this->postings->chargeLodging(
                    (int) $existing['id'],
                    (string) $part,
                    (int) $data['accountId'],
                    $categoryId,
                    $amount,
                    (string) $data['placeName'],
                    $today,
                );
            }
        }
    }

    /** @param array<string, mixed> $shift
     *  @return array<string, string>
     */
    private function quoteFields(array $shift): array
    {
        $fields = [];
        $index = 0;
        foreach ($shift as $part => $change) {
            $posting = $change['posting'];
            $accountId = (int) ($change['accountId'] ?? $posting['account_id']);
            $next = (int) $change['nextAmount'];
            $old = (int) $posting['amount_minor'];
            $stmt = $this->pdo->prepare('SELECT name, side, balance_minor, currency FROM accounts WHERE id = ? AND user_id = ?');
            $stmt->execute([$accountId, $this->actor->id()]);
            $account = $stmt->fetch();
            if (!is_array($account)) {
                continue;
            }
            $delta = 0;
            if ((int) $posting['account_id'] === $accountId) {
                $delta = Ledger::delta((string) $account['side'], 'out', $next) - Ledger::delta((string) $account['side'], 'out', $old);
            } else {
                $delta = $next > 0 ? Ledger::delta((string) $account['side'], 'out', $next) : 0;
            }
            $preview = Ledger::preview((int) $account['balance_minor'], $delta, null);
            $prefix = $index === 0 ? '' : 'next';
            $fields[$prefix . 'accountName'] = (string) $account['name'];
            $fields[$prefix . 'oldBalanceMinor'] = (string) $account['balance_minor'];
            $fields[$prefix . 'newBalanceMinor'] = (string) $preview['next'];
            $fields[$prefix . 'part'] = (string) $part;
            if ($preview['refused'] !== null) {
                $fields['refused'] = $preview['refused'];
            }
            $index++;
        }

        return $fields;
    }

    /** @param array<string, mixed> $trip */
    private function partAmount(array $trip, string $part): int
    {
        if ($part === 'deposit') {
            return (int) $trip['total_minor'] - (int) $trip['amount_due_minor'];
        }

        return (int) $trip['amount_due_minor'];
    }

    /** @return array<string, mixed>|null */
    private function partPosting(int $tripId, string $part): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM postings WHERE user_id = ? AND trip_id = ? AND lodging_part = ?'
        );
        $stmt->execute([$this->actor->id(), $tripId, $part]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
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
        $depositPosting = $this->partPosting((int) $row['id'], 'deposit');
        $duePosting = $this->partPosting((int) $row['id'], 'due');
        $currency = $row['currency'] === null ? null : (string) $row['currency'];
        $spent = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM postings
             WHERE user_id = ? AND trip_id = ? AND lodging_part IS NULL AND direction = \'out\''
        );
        $spent->execute([$this->actor->id(), $row['id']]);

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
            'exponent' => $currency === null ? 2 : $this->currencies->exponent($currency),
            'totalMinor' => (int) $row['total_minor'],
            'amountDueMinor' => (int) $row['amount_due_minor'],
            'depositMinor' => $deposit,
            'depositCharged' => $depositPosting !== null,
            'dueCharged' => $duePosting !== null,
            'otherSpentMinor' => (int) $spent->fetchColumn(),
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

    /** @return list<array{id:int,latitude:string,longitude:string}> */
    private function stops(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, latitude, longitude FROM trips
             WHERE user_id = ?
             ORDER BY arrive_on, depart_on, place_name COLLATE NOCASE, id'
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
            ];
        }

        return $stops;
    }

    private function fuelPrice(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT fuel_price_minor FROM user WHERE id = ?');
        $stmt->execute([$this->actor->id()]);
        $value = $stmt->fetchColumn();
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        $price = (int) $value;

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
            $this->postings->fuelStretches((int) $row['id']),
            (int) $row['mpg_min_tenths'],
            (int) $row['mpg_max_tenths'],
        );

        return $pooled ?? Fuel::midpoint((int) $row['mpg_min_tenths'], (int) $row['mpg_max_tenths']);
    }

    private function overlappingStay(string $arrive, string $depart, ?int $exceptId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, place_name FROM trips
             WHERE user_id = ? AND arrive_on < ? AND depart_on > ?
             ORDER BY arrive_on, id'
        );
        $stmt->execute([$this->actor->id(), $depart, $arrive]);
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            if ($exceptId !== null && (int) $row['id'] === $exceptId) {
                continue;
            }

            return (string) $row['place_name'];
        }

        return null;
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
