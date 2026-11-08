<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Posting\Stretch;
use App\Trip\Fuel;
use PDO;

final class VehicleService
{
    private const KINDS = ['car', 'truck', 'motorcycle', 'rv', 'semi', 'utv', 'atv', 'golf cart', 'dirt bike'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM vehicles WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END, starred DESC, name COLLATE NOCASE, id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->actor->id()]);
        $prices = $this->fuelPriceIndex();
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = $this->present($row, false, $prices);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->present($this->row($id), true, $this->fuelPriceIndex());
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $data = $this->validate($input, null);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            if ($data['starred'] === 1) {
                $this->clearStars($now);
            }
            $this->pdo->prepare(
                'INSERT INTO vehicles (
                    user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred,
                    current_odometer_miles, fuel_price_minor, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->actor->id(),
                $data['name'],
                $data['kind'],
                $data['mpgMinTenths'],
                $data['mpgMaxTenths'],
                $data['starred'],
                $data['currentOdometerMiles'],
                $data['fuelPrice'],
                $now,
                $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $existing = $this->row($id);
        if ($existing['archived_at'] !== null && !empty($input['starred'])) {
            throw new ApiException(409, 'Unarchive this vehicle before starring it.');
        }
        $data = $this->validate($input, $id);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            if ($data['starred'] === 1) {
                $this->clearStars($now);
            }
            $this->pdo->prepare(
                'UPDATE vehicles SET
                    name = ?, kind = ?, mpg_min_tenths = ?, mpg_max_tenths = ?, starred = ?,
                    current_odometer_miles = ?, fuel_price_minor = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            )->execute([
                $data['name'],
                $data['kind'],
                $data['mpgMinTenths'],
                $data['mpgMaxTenths'],
                $data['starred'],
                $data['currentOdometerMiles'],
                $data['fuelPrice'],
                $now,
                $id,
                $this->actor->id(),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function archive(int $id): array
    {
        $this->row($id);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE vehicles SET archived_at = COALESCE(archived_at, ?), starred = 0, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $now, $id, $this->actor->id()]);

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function unarchive(int $id): array
    {
        $this->row($id);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE vehicles SET archived_at = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $id, $this->actor->id()]);

        return $this->get($id);
    }

    public function delete(int $id, bool $confirm): void
    {
        $existing = $this->row($id);
        if ($existing['archived_at'] === null) {
            throw new ApiException(409, 'Only an archived vehicle can be deleted.');
        }
        if (!$confirm) {
            throw new ApiException(409, 'Confirm before deleting this vehicle.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE bills SET vehicle_id = NULL, updated_at = ? WHERE vehicle_id = ?')
                ->execute([gmdate('c'), $id]);
            $this->pdo->prepare('DELETE FROM vehicles WHERE id = ? AND user_id = ?')
                ->execute([$id, $this->actor->id()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{id:int,mpgMinTenths:int,mpgMaxTenths:int}|null */
    public function starred(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, mpg_min_tenths, mpg_max_tenths FROM vehicles WHERE user_id = ? AND starred = 1 AND archived_at IS NULL'
        );
        $stmt->execute([$this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'mpgMinTenths' => (int) $row['mpg_min_tenths'],
            'mpgMaxTenths' => (int) $row['mpg_max_tenths'],
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array{name:string,kind:string,mpgMinTenths:int,mpgMaxTenths:int,starred:int,currentOdometerMiles:int,fuelPrice:?int}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Use a name of 1 to 80 characters.';
        } elseif ($this->nameTaken($name, $exceptId)) {
            $errors['name'] = 'That name is already on a vehicle.';
        }
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'Choose a kind.';
        }
        $min = $this->asInt($input['mpgMinTenths'] ?? null);
        $max = $this->asInt($input['mpgMaxTenths'] ?? null);
        if ($min === null || $min <= 0) {
            $errors['mpgMinTenths'] = 'Enter a minimum miles per gallon greater than zero.';
            $min = 0;
        }
        if ($max === null || $max <= 0) {
            $errors['mpgMaxTenths'] = 'Enter a maximum miles per gallon greater than zero.';
            $max = 0;
        }
        if ($min > 0 && $max > 0 && $min >= $max) {
            $errors['mpgMaxTenths'] = 'The maximum is above the minimum.';
        }
        $current = $this->asInt($input['currentOdometerMiles'] ?? null);
        if ($current === null || $current < 0) {
            $errors['currentOdometerMiles'] = 'Enter the current odometer. Zero is only the reading you type.';
            $current = 0;
        } elseif ($exceptId !== null) {
            $floor = $this->pdo->prepare(
                'SELECT MAX(odometer_miles) FROM bills WHERE vehicle_id = ? AND odometer_miles IS NOT NULL'
            );
            $floor->execute([$exceptId]);
            $highest = $floor->fetchColumn();
            if ($highest !== null && $highest !== false && $current < (int) $highest) {
                $errors['currentOdometerMiles'] = 'The current odometer cannot sit below a fuel reading.';
            }
        }
        $fuelPrice = $this->fuelPrice($input, $errors, $exceptId);
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'mpgMinTenths' => $min,
            'mpgMaxTenths' => $max,
            'starred' => !empty($input['starred']) ? 1 : 0,
            'currentOdometerMiles' => $current,
            'fuelPrice' => $fuelPrice,
        ];
    }

    /** @param array<string, mixed> $input
     *  @param array<string, string> $errors
     */
    private function fuelPrice(array $input, array &$errors, ?int $exceptId): ?int
    {
        if (!array_key_exists('fuelPriceMinor', $input)) {
            if ($exceptId === null) {
                return null;
            }
            $stmt = $this->pdo->prepare('SELECT fuel_price_minor FROM vehicles WHERE id = ? AND user_id = ?');
            $stmt->execute([$exceptId, $this->actor->id()]);
            $raw = $stmt->fetchColumn();

            return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
        }
        $value = $input['fuelPriceMinor'];
        if ($value === null || $value === '') {
            return null;
        }
        $price = $this->asInt($value);
        if ($price === null || $price < 1) {
            $errors['fuelPriceMinor'] = 'Enter a price per gallon greater than zero, or leave it empty.';

            return null;
        }

        return $price;
    }

    /**
     * Newest priced fill-up per vehicle, by fill-up date then log id.
     * The opening price is that figure, else that vehicle's fallback, else blank.
     *
     * @return array<int, int>
     */
    private function fuelPriceIndex(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.vehicle_id, b.price_per_gallon_minor
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.type = \'fuel\' AND b.status IN (\'active\', \'paused\', \'paid\')
               AND b.vehicle_id IS NOT NULL
               AND b.price_per_gallon_minor IS NOT NULL
             ORDER BY b.anchor_date DESC, b.id DESC'
        );
        $stmt->execute([$this->actor->id()]);
        $byVehicle = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $price = (int) $row['price_per_gallon_minor'];
            if ($price < 1) {
                continue;
            }
            $vehicleId = (int) $row['vehicle_id'];
            if (!array_key_exists($vehicleId, $byVehicle)) {
                $byVehicle[$vehicleId] = $price;
            }
        }

        return $byVehicle;
    }

    /** @param array<int, int> $prices */
    private function openingPrice(array $prices, int $vehicleId, ?int $fallback): ?int
    {
        if (array_key_exists($vehicleId, $prices)) {
            return $prices[$vehicleId];
        }
        if ($fallback !== null && $fallback > 0) {
            return $fallback;
        }

        return null;
    }

    /** @param array<string, mixed> $row
     *  @param array<int, int> $prices
     *  @return array<string, mixed>
     */
    private function present(array $row, bool $withReadings, array $prices): array
    {
        $min = (int) $row['mpg_min_tenths'];
        $max = (int) $row['mpg_max_tenths'];
        $fallback = $row['fuel_price_minor'] === null ? null : (int) $row['fuel_price_minor'];
        if ($fallback !== null && $fallback < 1) {
            $fallback = null;
        }
        $origin = $row['origin_odometer_miles'] === null ? null : (int) $row['origin_odometer_miles'];
        $fuelRows = $this->fuelRows((int) $row['id']);
        $fills = Stretch::fillUps($this->stretchRows($fuelRows), $origin);
        $readings = $withReadings ? $this->readings($fuelRows, $fills) : [];
        $pooled = Fuel::pooled($fills, $min, $max);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'mpgMinTenths' => $min,
            'mpgMaxTenths' => $max,
            'starred' => (int) $row['starred'] === 1,
            'currentOdometerMiles' => (int) $row['current_odometer_miles'],
            'fuelPriceMinor' => $fallback,
            'suggestedPricePerGallonMinor' => $this->openingPrice($prices, (int) $row['id'], $fallback),
            'milesPerGallon' => Fuel::label($pooled),
            'archivedAt' => $row['archived_at'],
            'readings' => $readings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fuelRows(int $vehicleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.anchor_date, b.odometer_miles, b.price_per_gallon_minor, b.amount_minor,
                    b.account_id, b.category_id, b.name, b.notes,
                    a.name AS account_name
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.vehicle_id = ? AND b.type = \'fuel\' AND b.status IN (\'active\', \'paused\', \'paid\')
               AND (b.odometer_miles IS NOT NULL OR b.price_per_gallon_minor IS NOT NULL)
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
                'vehicleId' => $vehicleId,
                'postedOn' => (string) $row['anchor_date'],
                'accountId' => (int) $row['account_id'],
                'accountName' => (string) $row['account_name'],
                'currency' => 'USD',
                'categoryId' => (int) $row['category_id'],
                'direction' => 'out',
                'payee' => $row['name'],
                'memo' => $row['notes'],
                'tripId' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int,amountMinor:int,odometerMiles:?int,pricePerGallonMinor:?int,vehicleId:?int}>
     */
    private function stretchRows(array $rows): array
    {
        $forStretch = [];
        foreach ($rows as $row) {
            $forStretch[] = [
                'id' => (int) $row['id'],
                'amountMinor' => (int) $row['amountMinor'],
                'odometerMiles' => $row['odometerMiles'],
                'pricePerGallonMinor' => $row['pricePerGallonMinor'],
                'vehicleId' => $row['vehicleId'],
            ];
        }

        return $forStretch;
    }

    /**
     * One row per fuel bill. Newest date first, then id descending.
     * Stretch miles and mpg belong to the charge that opens that odometer.
     * A later charge at the same reading has nothing new to measure, so those cells stay empty.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{odometerMiles:int,miles:?int,gallons:?string,milesPerGallon:?string}> $fills
     * @return list<array<string, mixed>>
     */
    private function readings(array $rows, array $fills): array
    {
        $byOdometer = [];
        foreach ($fills as $fill) {
            $byOdometer[$fill['odometerMiles']] = $fill;
        }
        $opened = [];
        $listed = [];
        foreach ($rows as $row) {
            $odometer = $row['odometerMiles'];
            $fill = is_int($odometer) && isset($byOdometer[$odometer]) ? $byOdometer[$odometer] : null;
            $measures = $fill !== null && !isset($opened[(string) $odometer]);
            if ($odometer !== null) {
                $opened[(string) $odometer] = true;
            }
            $price = $row['pricePerGallonMinor'] === null ? null : (int) $row['pricePerGallonMinor'];
            $listed[] = [
                'id' => (int) $row['id'],
                'postedOn' => (string) $row['postedOn'],
                'odometerMiles' => $odometer,
                'pricePerGallonMinor' => $price,
                'amountMinor' => (int) $row['amountMinor'],
                'accountId' => (int) $row['accountId'],
                'accountName' => (string) $row['accountName'],
                'currency' => (string) $row['currency'],
                'categoryId' => (int) $row['categoryId'],
                'direction' => 'out',
                'payee' => 'Fuel',
                'memo' => null,
                'tripId' => null,
                'miles' => $measures ? $fill['miles'] : null,
                'gallons' => Stretch::gallons((int) $row['amountMinor'], $price),
                'milesPerGallon' => $measures ? $fill['milesPerGallon'] : null,
                'note' => null,
            ];
        }

        usort($listed, static function (array $left, array $right): int {
            $byDate = strcmp((string) $right['postedOn'], (string) $left['postedOn']);
            if ($byDate !== 0) {
                return $byDate;
            }

            return $right['id'] <=> $left['id'];
        });

        return $listed;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vehicles WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Vehicle not found.');
        }

        return $row;
    }

    private function clearStars(string $now): void
    {
        $this->pdo->prepare(
            'UPDATE vehicles SET starred = 0, updated_at = ? WHERE user_id = ? AND starred = 1'
        )->execute([$now, $this->actor->id()]);
    }

    private function nameTaken(string $name, ?int $exceptId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM vehicles WHERE user_id = ? AND name = ? COLLATE NOCASE'
        );
        $stmt->execute([$this->actor->id(), $name]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }

        return $exceptId === null || (int) $id !== $exceptId;
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
}
