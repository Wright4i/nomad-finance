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
    private const KINDS = ['car', 'truck', 'motorcycle', 'rv', 'semi'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vehicles WHERE user_id = ? ORDER BY starred DESC, name COLLATE NOCASE, id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = $this->present($row, false);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->present($this->row($id), true);
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
                    user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->actor->id(),
                $data['name'],
                $data['kind'],
                $data['mpgMinTenths'],
                $data['mpgMaxTenths'],
                $data['starred'],
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
        $this->row($id);
        $data = $this->validate($input, $id);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            if ($data['starred'] === 1) {
                $this->clearStars($now);
            }
            $this->pdo->prepare(
                'UPDATE vehicles SET
                    name = ?, kind = ?, mpg_min_tenths = ?, mpg_max_tenths = ?, starred = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            )->execute([
                $data['name'],
                $data['kind'],
                $data['mpgMinTenths'],
                $data['mpgMaxTenths'],
                $data['starred'],
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

    /** @return array{id:int,mpgMinTenths:int,mpgMaxTenths:int}|null */
    public function starred(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, mpg_min_tenths, mpg_max_tenths FROM vehicles WHERE user_id = ? AND starred = 1'
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
     *  @return array{name:string,kind:string,mpgMinTenths:int,mpgMaxTenths:int,starred:int}
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
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'mpgMinTenths' => $min,
            'mpgMaxTenths' => $max,
            'starred' => !empty($input['starred']) ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function present(array $row, bool $withReadings): array
    {
        $min = (int) $row['mpg_min_tenths'];
        $max = (int) $row['mpg_max_tenths'];
        $readings = $withReadings ? $this->readings((int) $row['id']) : [];
        $pooled = $withReadings
            ? Fuel::pooled(array_map(static function (array $reading): array {
                return [
                    'miles' => $reading['miles'],
                    'gallons' => $reading['gallons'],
                    'milesPerGallon' => $reading['milesPerGallon'],
                ];
            }, $readings), $min, $max)
            : null;

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'mpgMinTenths' => $min,
            'mpgMaxTenths' => $max,
            'starred' => (int) $row['starred'] === 1,
            'milesPerGallon' => Fuel::label($pooled),
            'readings' => $readings,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function readings(int $vehicleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.posted_on, p.odometer_miles, p.price_per_gallon_minor, p.amount_minor,
                    COALESCE((
                        SELECT SUM(l.amount_minor) FROM posting_lines l
                        JOIN categories fc ON fc.id = l.category_id
                        WHERE l.posting_id = p.id AND fc.role = \'fuel\'
                    ), p.amount_minor) AS fuel_amount,
                    p.account_id, p.category_id, p.direction, p.payee, p.memo, p.trip_id,
                    a.name AS account_name, a.currency
             FROM postings p
             JOIN accounts a ON a.id = p.account_id
             WHERE p.user_id = ? AND p.vehicle_id = ?
               AND (p.odometer_miles IS NOT NULL OR p.price_per_gallon_minor IS NOT NULL)
             ORDER BY p.posted_on, p.id'
        );
        $stmt->execute([$this->actor->id(), $vehicleId]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'amountMinor' => (int) $row['fuel_amount'],
                'postingAmount' => (int) $row['amount_minor'],
                'odometerMiles' => $row['odometer_miles'] === null ? null : (int) $row['odometer_miles'],
                'pricePerGallonMinor' => $row['price_per_gallon_minor'] === null ? null : (int) $row['price_per_gallon_minor'],
                'vehicleId' => $vehicleId,
                'postedOn' => (string) $row['posted_on'],
                'accountId' => (int) $row['account_id'],
                'accountName' => (string) $row['account_name'],
                'currency' => (string) $row['currency'],
                'categoryId' => (int) $row['category_id'],
                'direction' => (string) $row['direction'],
                'payee' => $row['payee'],
                'memo' => $row['memo'],
                'tripId' => $row['trip_id'] === null ? null : (int) $row['trip_id'],
            ];
        }
        $stretches = Stretch::decorate($rows);
        $listed = [];
        foreach ($rows as $row) {
            $stretch = $stretches[$row['id']] ?? null;
            $listed[] = [
                'id' => $row['id'],
                'postedOn' => $row['postedOn'],
                'odometerMiles' => $row['odometerMiles'],
                'pricePerGallonMinor' => $row['pricePerGallonMinor'],
                'amountMinor' => $row['postingAmount'],
                'accountId' => $row['accountId'],
                'accountName' => $row['accountName'],
                'currency' => $row['currency'],
                'categoryId' => $row['categoryId'],
                'direction' => $row['direction'],
                'payee' => $row['payee'],
                'memo' => $row['memo'],
                'tripId' => $row['tripId'],
                'miles' => is_array($stretch) ? $stretch['miles'] : null,
                'gallons' => is_array($stretch) ? $stretch['gallons'] : null,
                'milesPerGallon' => is_array($stretch) ? $stretch['milesPerGallon'] : null,
                'note' => is_array($stretch) ? $stretch['note'] : null,
            ];
        }

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
