<?php

declare(strict_types=1);

namespace App\Posting;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Support\Dates;
use PDO;
use Throwable;

final class PostingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly CurrencyCatalog $currencies,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, a.name AS account_name, a.currency, a.side, a.balance_minor, a.credit_limit_minor,
                    c.name AS category_name, (c.role = \'fuel\') AS fuel, t.place_name
             FROM postings p
             JOIN accounts a ON a.id = p.account_id
             JOIN categories c ON c.id = p.category_id
             LEFT JOIN trips t ON t.id = p.trip_id
             WHERE p.user_id = ?
             ORDER BY p.posted_on DESC, p.id DESC'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        $rows = is_array($rows) ? $rows : [];
        $stretches = Stretch::decorate($this->fuelRows());
        $lines = $this->linesByPosting();
        $listed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $listed[] = $this->present($row, $stretches[(int) $row['id']] ?? null, $lines[(int) $row['id']] ?? []);
        }

        return $listed;
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $data = $this->validate($input, null);
        $preview = $this->quoteAccount($data['accountId'], Ledger::delta($data['side'], $data['direction'], $data['amountMinor']));
        if ($preview['refused'] !== null) {
            throw new ApiException(422, $preview['refused']);
        }
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO postings (
                    user_id, account_id, category_id, trip_id, amount_minor, direction, payee, memo,
                    posted_on, lodging_part, odometer_miles, price_per_gallon_minor, pay_cycle_id,
                    vehicle_id, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->actor->id(),
                $data['accountId'],
                $data['categoryId'],
                $data['tripId'],
                $data['amountMinor'],
                $data['direction'],
                $data['payee'],
                $data['memo'],
                $data['postedOn'],
                $data['odometerMiles'],
                $data['pricePerGallonMinor'],
                $data['payCycleId'],
                $data['vehicleId'],
                $now,
                $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->writeLines($id, $data['lines']);
            $this->setBalance($data['accountId'], $preview['next']);
            $this->pdo->commit();
        } catch (Throwable $e) {
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
        if ($existing['lodging_part'] !== null) {
            throw new ApiException(409, 'Change a lodging charge from the stay.');
        }
        $data = $this->validate($input, $existing);
        $this->pdo->beginTransaction();
        try {
            $this->rewind($existing);
            $preview = $this->quoteAccount($data['accountId'], Ledger::delta($data['side'], $data['direction'], $data['amountMinor']));
            if ($preview['refused'] !== null) {
                throw new ApiException(422, $preview['refused']);
            }
            $now = gmdate('c');
            $this->pdo->prepare(
                'UPDATE postings SET
                    account_id = ?, category_id = ?, trip_id = ?, amount_minor = ?, direction = ?,
                    payee = ?, memo = ?, posted_on = ?, odometer_miles = ?, price_per_gallon_minor = ?,
                    pay_cycle_id = ?, vehicle_id = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            )->execute([
                $data['accountId'],
                $data['categoryId'],
                $data['tripId'],
                $data['amountMinor'],
                $data['direction'],
                $data['payee'],
                $data['memo'],
                $data['postedOn'],
                $data['odometerMiles'],
                $data['pricePerGallonMinor'],
                $data['payCycleId'],
                $data['vehicleId'],
                $now,
                $id,
                $this->actor->id(),
            ]);
            $this->writeLines($id, $data['lines']);
            $this->setBalance($data['accountId'], $preview['next']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $existing = $this->row($id);
        if ($existing['lodging_part'] !== null) {
            throw new ApiException(409, 'Delete the stay to reverse a lodging charge.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->rewind($existing);
            $this->pdo->prepare('DELETE FROM posting_lines WHERE posting_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM postings WHERE id = ? AND user_id = ?')->execute([$id, $this->actor->id()]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function quote(array $input): array
    {
        $data = $this->validate($input, null);
        $account = $this->account($data['accountId']);
        $preview = $this->quoteAccount(
            $data['accountId'],
            Ledger::delta($data['side'], $data['direction'], $data['amountMinor']),
        );
        $remaining = $this->remaining($data['categoryId'], $data['postedOn']);
        $lines = [];
        foreach ($data['lines'] as $line) {
            $lineRemaining = $this->remaining($line['categoryId'], $data['postedOn']);
            $lines[] = [
                'categoryId' => $line['categoryId'],
                'categoryName' => $line['categoryName'],
                'amountMinor' => $line['amountMinor'],
                'remainingMinor' => $lineRemaining['remainingMinor'],
            ];
        }

        return [
            'accountId' => $data['accountId'],
            'accountName' => (string) $account['name'],
            'currency' => (string) $account['currency'],
            'exponent' => $this->currencies->exponent((string) $account['currency']),
            'oldBalanceMinor' => (int) $account['balance_minor'],
            'newBalanceMinor' => $preview['next'],
            'refused' => $preview['refused'],
            'overLimit' => $preview['overLimit'],
            'amountMinor' => $data['amountMinor'],
            'remainingMinor' => $remaining['remainingMinor'],
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    public function quoteOut(int $accountId, int $amount): array
    {
        $account = $this->account($accountId);
        $preview = $this->quoteAccount($accountId, Ledger::delta((string) $account['side'], 'out', $amount));

        return [
            'accountId' => $accountId,
            'accountName' => (string) $account['name'],
            'currency' => (string) $account['currency'],
            'exponent' => $this->currencies->exponent((string) $account['currency']),
            'oldBalanceMinor' => (int) $account['balance_minor'],
            'newBalanceMinor' => $preview['next'],
            'refused' => $preview['refused'],
            'overLimit' => $preview['overLimit'],
            'amountMinor' => $amount,
        ];
    }

    public function chargeLodging(
        int $tripId,
        string $part,
        int $accountId,
        int $categoryId,
        int $amount,
        string $payee,
        string $today,
    ): void {
        if ($this->lodgingId($tripId, $part) !== null) {
            throw new ApiException(409, 'That part is already charged.');
        }
        $preview = $this->quoteOut($accountId, $amount);
        if ($preview['refused'] !== null) {
            throw new ApiException(422, (string) $preview['refused']);
        }
        $category = $this->category($categoryId);
        if ($category['archived_at'] !== null) {
            throw new ApiException(422, 'Choose a bucket that is still on the list.', ['categoryId' => 'Choose a bucket that is still on the list.']);
        }
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO postings (
                user_id, account_id, category_id, trip_id, amount_minor, direction, payee, memo,
                posted_on, lodging_part, odometer_miles, price_per_gallon_minor, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, \'out\', ?, NULL, ?, ?, NULL, NULL, ?, ?)'
        )->execute([
            $this->actor->id(),
            $accountId,
            $categoryId,
            $tripId,
            $amount,
            $payee,
            $today,
            $part,
            $now,
            $now,
        ]);
        $this->setBalance($accountId, (int) $preview['newBalanceMinor']);
    }

    public function reverseLodging(int $tripId, string $part): void
    {
        $id = $this->lodgingId($tripId, $part);
        if ($id === null) {
            return;
        }
        $this->rewind($this->row($id));
        $this->pdo->prepare('DELETE FROM postings WHERE id = ? AND user_id = ?')->execute([$id, $this->actor->id()]);
    }

    public function detachStay(int $tripId): void
    {
        $this->pdo->prepare(
            'UPDATE postings SET trip_id = NULL, updated_at = ? WHERE user_id = ? AND trip_id = ? AND lodging_part IS NULL'
        )->execute([gmdate('c'), $this->actor->id(), $tripId]);
    }

    /** @return array{assignedMinor:int,remainingMinor:int,start:?string,end:?string} */
    public function remaining(int $categoryId, string $today): array
    {
        $span = $this->openSpan($today);
        $assigned = 0;
        if ($span['start'] !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(a.amount_minor), 0)
                 FROM assignments a
                 JOIN pay_periods p ON p.id = a.pay_period_id
                 WHERE p.user_id = ? AND a.category_id = ?
                   AND p.period_start <= ? AND (p.period_end IS NULL OR p.period_end >= ?)'
            );
            $stmt->execute([$this->actor->id(), $categoryId, $today, $today]);
            $assigned = (int) $stmt->fetchColumn();
        }

        return [
            'assignedMinor' => $assigned,
            'remainingMinor' => Remaining::one($this->pdo, $this->actor->id(), $categoryId, $assigned, $span['start'], $span['end']),
            'start' => $span['start'],
            'end' => $span['end'],
        ];
    }

    public function onlyTripOn(string $day): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, arrive_on FROM trips
             WHERE user_id = ? AND arrive_on <= ? AND depart_on >= ?
             ORDER BY arrive_on, id'
        );
        $stmt->execute([$this->actor->id(), $day, $day]);
        $ids = [];
        $arriving = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $ids[] = (int) $row['id'];
            if ((string) $row['arrive_on'] === $day) {
                $arriving[] = (int) $row['id'];
            }
        }
        if (count($arriving) === 1) {
            return $arriving[0];
        }
        if (count($ids) === 1) {
            return $ids[0];
        }

        return null;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function quoteEdit(int $id, array $input): array
    {
        $existing = $this->row($id);
        $data = $this->validate($input, $existing);
        $oldAccount = $this->account((int) $existing['account_id']);
        $oldDelta = Ledger::delta((string) $oldAccount['side'], (string) $existing['direction'], (int) $existing['amount_minor']);
        if ((int) $data['accountId'] === (int) $existing['account_id']) {
            $newDelta = Ledger::delta($data['side'], $data['direction'], $data['amountMinor']);
            $preview = Ledger::preview((int) $oldAccount['balance_minor'], $newDelta - $oldDelta, $this->limit($oldAccount));
            $account = $oldAccount;
        } else {
            $account = $this->account($data['accountId']);
            $preview = Ledger::preview(
                (int) $account['balance_minor'],
                Ledger::delta((string) $account['side'], $data['direction'], $data['amountMinor']),
                $this->limit($account),
            );
        }

        return $this->quoteBody($account, $preview, $data['amountMinor']);
    }

    /** @return array<string, mixed> */
    public function quoteDelete(int $id): array
    {
        $existing = $this->row($id);
        $account = $this->account((int) $existing['account_id']);
        $delta = Ledger::delta((string) $account['side'], (string) $existing['direction'], (int) $existing['amount_minor']);
        $preview = Ledger::preview((int) $account['balance_minor'], -$delta, $this->limit($account));

        return $this->quoteBody($account, $preview, (int) $existing['amount_minor']);
    }

    /** @return array<string, mixed> */
    private function get(int $id): array
    {
        $row = $this->row($id);
        $stretches = Stretch::decorate($this->fuelRows());
        $lines = $this->linesByPosting();

        return $this->present($row, $stretches[$id] ?? null, $lines[$id] ?? []);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, a.name AS account_name, a.currency, a.side, a.balance_minor, a.credit_limit_minor,
                    c.name AS category_name, (c.role = \'fuel\') AS fuel, t.place_name
             FROM postings p
             JOIN accounts a ON a.id = p.account_id
             JOIN categories c ON c.id = p.category_id
             LEFT JOIN trips t ON t.id = p.trip_id
             WHERE p.id = ? AND p.user_id = ?'
        );
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Posting not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validate(array $input, ?array $existing): array
    {
        $errors = [];
        $account = $this->account($this->asInt($input['accountId'] ?? null) ?? 0);
        if ($account['archived_at'] !== null) {
            $errors['accountId'] = 'Choose an account that is still on the list.';
        }
        $category = $this->category($this->asInt($input['categoryId'] ?? null) ?? 0);
        if ($category['archived_at'] !== null) {
            $errors['categoryId'] = 'Choose a bucket that is still on the list.';
        }
        $amount = $this->asInt($input['amountMinor'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors['amountMinor'] = 'Enter an amount greater than zero.';
            $amount = 0;
        }
        $direction = is_string($input['direction'] ?? null) ? $input['direction'] : 'out';
        if (!in_array($direction, ['in', 'out'], true)) {
            $errors['direction'] = 'Choose money in or money out.';
            $direction = 'out';
        }
        $postedOn = $this->day(is_string($input['postedOn'] ?? null) ? $input['postedOn'] : '');
        if ($postedOn === null) {
            $errors['postedOn'] = 'Use a YYYY-MM-DD date.';
            $postedOn = gmdate('Y-m-d');
        } else {
            $today = $this->day(is_string($input['today'] ?? null) ? $input['today'] : '');
            if ($today === null) {
                $today = gmdate('Y-m-d');
            }
            if ($postedOn > $today) {
                $errors['postedOn'] = 'Use today or an earlier date.';
            }
        }
        $payCycleId = null;
        if (array_key_exists('payCycleId', $input) && $input['payCycleId'] !== null && $input['payCycleId'] !== '') {
            $payCycleId = $this->asInt($input['payCycleId']);
            if ($payCycleId === null || !$this->ownsCycle($payCycleId)) {
                $errors['payCycleId'] = 'Choose a pay cycle.';
                $payCycleId = null;
            } elseif ($direction === 'out') {
                $errors['payCycleId'] = 'An outflow cannot name a pay cycle.';
            }
        }
        $payee = $this->optionalText($input['payee'] ?? null, 80);
        if ($payee === false) {
            $errors['payee'] = 'Keep the payee to 80 characters.';
            $payee = null;
        }
        $memo = $this->optionalText($input['memo'] ?? null, 2000);
        if ($memo === false) {
            $errors['memo'] = 'Keep the memo to 2000 characters.';
            $memo = null;
        }
        $tripId = null;
        if (array_key_exists('tripId', $input) && $input['tripId'] !== null && $input['tripId'] !== '') {
            $tripId = $this->asInt($input['tripId']);
            if ($tripId === null || !$this->ownsTrip($tripId)) {
                $errors['tripId'] = 'Choose a stay.';
                $tripId = null;
            }
        } elseif (!array_key_exists('tripId', $input)) {
            $tripId = $this->onlyTripOn($postedOn);
        }
        $storedLines = [];
        $parsed = $this->parseLines($input, $amount, $errors);
        if ($parsed !== null && $parsed !== [] && !isset($errors['lines'])) {
            $category = $this->category((int) $parsed[0]['categoryId']);
            $storedLines = $parsed;
        }
        $fuel = (string) ($category['role'] ?? '') === 'fuel';
        foreach ($storedLines as $line) {
            if ($line['fuel']) {
                $fuel = true;
            }
        }
        $odometer = null;
        $price = null;
        if ($fuel) {
            if (array_key_exists('odometerMiles', $input) && $input['odometerMiles'] !== null && $input['odometerMiles'] !== '') {
                $odometer = $this->asInt($input['odometerMiles']);
                if ($odometer === null || $odometer < 0) {
                    $errors['odometerMiles'] = 'Use whole miles, or leave the odometer blank.';
                    $odometer = null;
                }
            }
            if (array_key_exists('pricePerGallonMinor', $input) && $input['pricePerGallonMinor'] !== null && $input['pricePerGallonMinor'] !== '') {
                $price = $this->asInt($input['pricePerGallonMinor']);
                if ($price === null || $price <= 0) {
                    $errors['pricePerGallonMinor'] = 'Enter a price per gallon greater than zero, or leave it blank.';
                    $price = null;
                }
            }
        } elseif (
            (isset($input['odometerMiles']) && $input['odometerMiles'] !== '' && $input['odometerMiles'] !== null)
            || (isset($input['pricePerGallonMinor']) && $input['pricePerGallonMinor'] !== '' && $input['pricePerGallonMinor'] !== null)
        ) {
            $errors['odometerMiles'] = 'Odometer and price per gallon belong on a fuel bucket.';
        }
        $vehicleId = null;
        if ($odometer !== null || $price !== null) {
            $vehicleId = $this->asInt($input['vehicleId'] ?? null);
            if ($vehicleId === null || !$this->ownsVehicle($vehicleId)) {
                $errors['vehicleId'] = 'Choose a vehicle.';
                $vehicleId = null;
            }
        }
        if ($odometer !== null && $vehicleId !== null && !isset($errors['odometerMiles']) && $existing === null) {
            $floor = $this->highestOdometer($vehicleId);
            if ($floor !== null && $odometer < $floor) {
                $errors['odometerMiles'] = 'Enter an odometer at or above ' . $floor . '.';
            }
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'accountId' => (int) $account['id'],
            'side' => (string) $account['side'],
            'categoryId' => (int) $category['id'],
            'lines' => $storedLines,
            'amountMinor' => $amount,
            'direction' => $direction,
            'postedOn' => $postedOn,
            'payCycleId' => $payCycleId,
            'payee' => is_string($payee) ? $payee : null,
            'memo' => is_string($memo) ? $memo : null,
            'tripId' => $tripId,
            'odometerMiles' => $odometer,
            'pricePerGallonMinor' => $price,
            'vehicleId' => $vehicleId,
            'existing' => $existing,
        ];
    }

    /** @param array<string, mixed> $row */
    private function rewind(array $row): void
    {
        $account = $this->account((int) $row['account_id']);
        $delta = Ledger::delta((string) $account['side'], (string) $row['direction'], (int) $row['amount_minor']);
        $preview = Ledger::preview((int) $account['balance_minor'], -$delta, $this->limit($account));
        if ($preview['refused'] !== null) {
            throw new ApiException(422, 'Reversing this posting would take the balance below zero.');
        }
        $this->setBalance((int) $account['id'], $preview['next']);
    }

    /** @return array{next:int,refused:?string,overLimit:bool} */
    private function quoteAccount(int $accountId, int $delta): array
    {
        $account = $this->account($accountId);

        return Ledger::preview((int) $account['balance_minor'], $delta, $this->limit($account));
    }

    /** @param array<string, mixed> $account */
    private function limit(array $account): ?int
    {
        if (($account['type'] ?? '') !== 'credit' || $account['credit_limit_minor'] === null) {
            return null;
        }

        return (int) $account['credit_limit_minor'];
    }

    private function setBalance(int $accountId, int $balance): void
    {
        $this->pdo->prepare(
            'UPDATE accounts SET balance_minor = ?, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$balance, gmdate('c'), $accountId, $this->actor->id()]);
    }

    /** @return array<string, mixed> */
    private function account(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(422, 'Check the highlighted fields.', ['accountId' => 'Choose an account.']);
        }

        return $row;
    }

    /** @param list<array{categoryId:int,amountMinor:int}> $lines */
    private function writeLines(int $postingId, array $lines): void
    {
        $this->pdo->prepare('DELETE FROM posting_lines WHERE posting_id = ?')->execute([$postingId]);
        if (count($lines) < 2) {
            return;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO posting_lines (posting_id, category_id, amount_minor) VALUES (?, ?, ?)'
        );
        foreach ($lines as $line) {
            $insert->execute([$postingId, $line['categoryId'], $line['amountMinor']]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return list<array{categoryId:int,categoryName:string,amountMinor:int,fuel:bool}>|null
     */
    private function parseLines(array $input, int $amount, array &$errors): ?array
    {
        if (!array_key_exists('lines', $input) || $input['lines'] === null) {
            return null;
        }
        if (!is_array($input['lines'])) {
            $errors['lines'] = 'Add a bucket and an amount on each line.';

            return [];
        }
        $lines = [];
        $seen = [];
        $sum = 0;
        foreach ($input['lines'] as $line) {
            if (!is_array($line)) {
                $errors['lines'] = 'Add a bucket and an amount on each line.';
                continue;
            }
            $id = $this->asInt($line['categoryId'] ?? null) ?? 0;
            $lineAmount = $this->asInt($line['amountMinor'] ?? null);
            $category = $this->categoryRow($id);
            if ($category === null || $category['archived_at'] !== null) {
                $errors['lines'] = 'Choose a bucket that is still on the list.';
                continue;
            }
            if ($lineAmount === null || $lineAmount <= 0) {
                $errors['lines'] = 'Each line is an amount greater than zero.';
                continue;
            }
            if (isset($seen[$id])) {
                $errors['lines'] = 'A bucket is on one line.';
                continue;
            }
            $seen[$id] = true;
            $sum += $lineAmount;
            $lines[] = [
                'categoryId' => $id,
                'categoryName' => (string) $category['name'],
                'amountMinor' => $lineAmount,
                'fuel' => (string) ($category['role'] ?? '') === 'fuel',
            ];
        }
        if (isset($errors['lines'])) {
            return [];
        }
        if (count($lines) < 2) {
            return null;
        }
        if ($sum !== $amount) {
            $errors['lines'] = 'The lines have to sum to the amount.';

            return [];
        }

        return $lines;
    }

    /** @return array<int, list<array{categoryId:int,categoryName:string,amountMinor:int,fuel:bool}>> */
    private function linesByPosting(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.posting_id, l.category_id, l.amount_minor, c.name, (c.role = \'fuel\') AS fuel
             FROM posting_lines l
             JOIN postings p ON p.id = l.posting_id
             JOIN categories c ON c.id = l.category_id
             WHERE p.user_id = ?
             ORDER BY l.id'
        );
        $stmt->execute([$this->actor->id()]);
        $grouped = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $grouped[(int) $row['posting_id']][] = [
                'categoryId' => (int) $row['category_id'],
                'categoryName' => (string) $row['name'],
                'amountMinor' => (int) $row['amount_minor'],
                'fuel' => (int) $row['fuel'] === 1,
            ];
        }

        return $grouped;
    }

    /** @return array<string, mixed>|null */
    private function categoryRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function category(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(422, 'Check the highlighted fields.', ['categoryId' => 'Choose a bucket.']);
        }

        return $row;
    }

    private function ownsCycle(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM pay_cycles WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);

        return $stmt->fetch() !== false;
    }

    private function ownsTrip(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM trips WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);

        return $stmt->fetch() !== false;
    }

    private function ownsVehicle(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM vehicles WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);

        return $stmt->fetch() !== false;
    }

    private function highestOdometer(int $vehicleId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(odometer_miles) FROM postings
             WHERE user_id = ? AND vehicle_id = ? AND odometer_miles IS NOT NULL'
        );
        $stmt->execute([$this->actor->id(), $vehicleId]);
        $value = $stmt->fetchColumn();
        if ($value === null || $value === false) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $account
     * @param array{next:int,refused:?string,overLimit:bool} $preview
     * @return array<string, mixed>
     */
    private function quoteBody(array $account, array $preview, int $amount): array
    {
        return [
            'accountId' => (int) $account['id'],
            'accountName' => (string) $account['name'],
            'currency' => (string) $account['currency'],
            'exponent' => $this->currencies->exponent((string) $account['currency']),
            'oldBalanceMinor' => (int) $account['balance_minor'],
            'newBalanceMinor' => $preview['next'],
            'refused' => $preview['refused'],
            'overLimit' => $preview['overLimit'],
            'amountMinor' => $amount,
        ];
    }

    private function lodgingId(int $tripId, string $part): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM postings WHERE user_id = ? AND trip_id = ? AND lodging_part = ?'
        );
        $stmt->execute([$this->actor->id(), $tripId, $part]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array{start:?string,end:?string} */
    private function openSpan(string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(period_start) AS start_on, MAX(period_end) AS end_on
             FROM pay_periods
             WHERE user_id = ? AND period_start <= ? AND (period_end IS NULL OR period_end >= ?)'
        );
        $stmt->execute([$this->actor->id(), $today, $today]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['start_on'] === null) {
            return ['start' => $today, 'end' => $today];
        }

        return [
            'start' => (string) $row['start_on'],
            'end' => $row['end_on'] === null ? null : (string) $row['end_on'],
        ];
    }

    /**
     * @return list<array{id:int,amountMinor:int,odometerMiles:?int,pricePerGallonMinor:?int}>
     */
    private function fuelRows(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.vehicle_id, p.odometer_miles, p.price_per_gallon_minor,
                    COALESCE((
                        SELECT SUM(l.amount_minor) FROM posting_lines l
                        JOIN categories fc ON fc.id = l.category_id
                        WHERE l.posting_id = p.id AND fc.role = \'fuel\'
                    ), p.amount_minor) AS amount_minor
             FROM postings p
             WHERE p.user_id = ? AND p.direction = \'out\'
               AND (
                    EXISTS (SELECT 1 FROM categories c WHERE c.id = p.category_id AND c.role = \'fuel\')
                    OR EXISTS (
                        SELECT 1 FROM posting_lines l
                        JOIN categories c ON c.id = l.category_id
                        WHERE l.posting_id = p.id AND c.role = \'fuel\'
                    )
               )
             ORDER BY p.posted_on, p.id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'vehicleId' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
                'amountMinor' => (int) $row['amount_minor'],
                'odometerMiles' => $row['odometer_miles'] === null ? null : (int) $row['odometer_miles'],
                'pricePerGallonMinor' => $row['price_per_gallon_minor'] === null ? null : (int) $row['price_per_gallon_minor'],
            ];
        }

        return $rows;
    }

    /**
     * Stretches for one vehicle, oldest first. The fuel label pools the ones inside its range.
     *
     * @return list<array{miles:?int,gallons:?string,milesPerGallon:?string,note:?string}>
     */
    public function fuelStretches(int $vehicleId): array
    {
        $rows = array_values(array_filter(
            $this->fuelRows(),
            static fn (array $row): bool => ($row['vehicleId'] ?? null) === $vehicleId,
        ));
        $stretches = Stretch::decorate($rows);
        $samples = [];
        foreach ($rows as $row) {
            $stretch = $stretches[$row['id']] ?? null;
            if (is_array($stretch)) {
                $samples[] = $stretch;
            }
        }

        return $samples;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{miles:?int,gallons:?string,milesPerGallon:?string,note:?string}|null $stretch
     * @param list<array{categoryId:int,categoryName:string,amountMinor:int,fuel:bool}> $stored
     * @return array<string, mixed>
     */
    private function present(array $row, ?array $stretch, array $stored): array
    {
        $today = (string) $row['posted_on'];
        $remaining = $this->remaining((int) $row['category_id'], $today);
        $fuel = (int) ($row['fuel'] ?? 0) === 1;
        foreach ($stored as $line) {
            if ($line['fuel']) {
                $fuel = true;
            }
        }
        $lines = $stored === []
            ? [[
                'categoryId' => (int) $row['category_id'],
                'categoryName' => (string) $row['category_name'],
                'amountMinor' => (int) $row['amount_minor'],
                'remainingMinor' => $remaining['remainingMinor'],
            ]]
            : array_map(function (array $line) use ($today): array {
                $lineRemaining = $this->remaining($line['categoryId'], $today);

                return [
                    'categoryId' => $line['categoryId'],
                    'categoryName' => $line['categoryName'],
                    'amountMinor' => $line['amountMinor'],
                    'remainingMinor' => $lineRemaining['remainingMinor'],
                ];
            }, $stored);

        return [
            'id' => (int) $row['id'],
            'accountId' => (int) $row['account_id'],
            'accountName' => (string) $row['account_name'],
            'currency' => (string) $row['currency'],
            'exponent' => $this->currencies->exponent((string) $row['currency']),
            'balanceMinor' => (int) $row['balance_minor'],
            'categoryId' => (int) $row['category_id'],
            'categoryName' => (string) $row['category_name'],
            'lines' => $lines,
            'fuel' => $fuel,
            'tripId' => $row['trip_id'] === null ? null : (int) $row['trip_id'],
            'placeName' => $row['place_name'] === null ? null : (string) $row['place_name'],
            'amountMinor' => (int) $row['amount_minor'],
            'direction' => (string) $row['direction'],
            'payCycleId' => $row['pay_cycle_id'] === null ? null : (int) $row['pay_cycle_id'],
            'payee' => $row['payee'],
            'memo' => $row['memo'],
            'postedOn' => (string) $row['posted_on'],
            'lodgingPart' => $row['lodging_part'],
            'odometerMiles' => $row['odometer_miles'] === null ? null : (int) $row['odometer_miles'],
            'pricePerGallonMinor' => $row['price_per_gallon_minor'] === null ? null : (int) $row['price_per_gallon_minor'],
            'vehicleId' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'stretch' => $stretch,
            'remainingMinor' => $remaining['remainingMinor'],
            'assignedMinor' => $remaining['assignedMinor'],
        ];
    }

    private function day(string $value): ?string
    {
        try {
            return Dates::parse($value)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return null;
        }
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
