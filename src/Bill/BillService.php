<?php

declare(strict_types=1);

namespace App\Bill;

use App\Auth\Actor;
use App\Category\SystemBuckets;
use App\Envelope\BillFill;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Support\Dates;
use PDO;

final class BillService
{
    private const RECURRENCE = ['once', 'daily', 'weekly', 'monthly', 'yearly'];

    private BillFill $fill;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
        private readonly Actor $actor,
        ?BillFill $fill = null,
    ) {
        $this->fill = $fill ?? new BillFill($pdo, $currencies, $actor);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.*, a.name AS account_name, c.name AS category_name
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             LEFT JOIN categories c ON c.id = b.category_id
             WHERE a.user_id = ? AND b.status != 'hidden'
             ORDER BY CASE b.status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'archived' THEN 2 ELSE 3 END,
                      b.next_due_date, b.id"
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();

        return array_map($this->present(...), is_array($rows) ? $rows : []);
    }

    /**
     * One status section, oldest dates first, a few days at a time.
     * Active shows at least ten bills when they exist, and never less than seven days.
     * Paid opens on the recent dates and older chunks follow. Other sections open early and later chunks follow.
     * The full list stays on list().
     *
     * @param array<string, mixed> $filter
     * @return array{bills:list<array<string, mixed>>,hasMore:bool}
     */
    public function section(string $section, int $chunks, array $filter = [], ?string $start = null, ?string $end = null, ?string $today = null): array
    {
        if (!in_array($section, ['active', 'paused', 'paid', 'archived'], true)) {
            throw new ApiException(422, 'Choose a bill section.');
        }
        $rows = [];
        foreach ($this->list() as $bill) {
            if (!BillListFilter::matches($bill, $filter)) {
                continue;
            }
            $shown = BillWindow::shownOn($bill, $section);
            if ($shown === null) {
                continue;
            }
            $rows[] = [
                'id' => (int) $bill['id'],
                'name' => (string) $bill['name'],
                'shownOn' => $shown,
                'amountMinor' => (int) $bill['amountMinor'],
                'bill' => $bill,
            ];
        }
        // The period is the screen's window elsewhere. This list still opens on a few days
        // and grows when the person scrolls, including dates outside that period.
        unset($start, $end, $today);
        $minimum = $section === 'active' ? BillWindow::ACTIVE_MINIMUM : 2;
        $window = BillWindow::window($rows, $chunks, $section === 'paid', $minimum);
        return ['bills' => $this->billsFrom($window['rows']), 'hasMore' => $window['hasMore']];
    }

    /**
     * @param list<array{bill?:mixed}> $rows
     * @return list<array<string, mixed>>
     */
    private function billsFrom(array $rows): array
    {
        $bills = [];
        foreach ($rows as $row) {
            if (is_array($row['bill'] ?? null)) {
                $bills[] = $row['bill'];
            }
        }

        return $bills;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, a.name AS account_name, c.name AS category_name
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             LEFT JOIN categories c ON c.id = b.category_id
             WHERE b.id = ? AND a.user_id = ?'
        );
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Bill not found.');
        }

        return $this->present($row);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $data = $this->validate($input, null);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, other_label, account_id, category_id, amount_minor, currency,
                recurrence, every_count, anchor_date, next_due_date, autopay, notes, status, created_at, updated_at,
                vehicle_id, odometer_miles, price_per_gallon_minor
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, ?, ?)'
        )->execute([
            $data['name'], $data['type'], $data['otherLabel'], $data['accountId'], $data['categoryId'], $data['amountMinor'],
            $data['currency'], $data['recurrence'], $data['every'],
            $data['nextDueDate'], $data['nextDueDate'], $data['autopay'], $data['notes'], $now, $now,
            $data['vehicleId'], $data['odometerMiles'], $data['pricePerGallonMinor'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->fill->apply($this->today($input));

        return $this->get($id);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $existing = $this->row($id);
        if ($existing['source_account_id'] !== null) {
            throw new ApiException(409, 'This bill is kept by the account.');
        }
        if (in_array($existing['status'], ['archived', 'hidden'], true)) {
            throw new ApiException(409, 'This bill is closed.');
        }
        $paidLog = $existing['status'] === 'paid';
        if ($paidLog && !$this->editableLog($existing)) {
            throw new ApiException(409, 'This bill is closed.');
        }
        $data = $this->validate($input, $existing);
        if ($paidLog) {
            $this->guardLogEdit($existing, $data);
            if ($data['nextDueDate'] > $this->today($input)) {
                throw new ApiException(422, 'Check the highlighted fields.', [
                    'nextDueDate' => 'The date cannot be after today.',
                ]);
            }
            $data['categoryId'] = SystemBuckets::idForType($this->pdo, $this->actor->id(), $data['type']);
        }
        $anchor = $data['nextDueDate'] !== $existing['next_due_date']
            ? $data['nextDueDate']
            : (string) $existing['anchor_date'];
        $refill = $data['type'] !== (string) $existing['type']
            || $data['categoryId'] !== (int) $existing['category_id']
            || $data['amountMinor'] !== (int) $existing['amount_minor']
            || $data['recurrence'] !== (string) $existing['recurrence']
            || $data['every'] !== (int) ($existing['every_count'] ?? 1)
            || $data['nextDueDate'] !== (string) $existing['next_due_date']
            || $anchor !== (string) $existing['anchor_date'];
        $now = gmdate('c');
        $paidOn = $paidLog ? $data['nextDueDate'] : $existing['last_paid_on'];
        $this->pdo->prepare(
            'UPDATE bills SET
                name = ?, type = ?, other_label = ?, account_id = ?, category_id = ?, amount_minor = ?, currency = ?,
                recurrence = ?, every_count = ?, anchor_date = ?, next_due_date = ?, last_paid_on = ?,
                autopay = ?, notes = ?, updated_at = ?,
                vehicle_id = ?, odometer_miles = ?, price_per_gallon_minor = ?
             WHERE id = ?'
        )->execute([
            $data['name'], $data['type'], $data['otherLabel'], $data['accountId'], $data['categoryId'], $data['amountMinor'],
            $data['currency'], $data['recurrence'], $data['every'],
            $anchor, $data['nextDueDate'], $paidOn, $data['autopay'], $data['notes'], $now,
            $data['vehicleId'], $data['odometerMiles'], $data['pricePerGallonMinor'], $id,
        ]);
        if ($refill && !$paidLog) {
            $this->fill->apply($this->today($input));
        }

        return $this->get($id);
    }

    /**
     * Correct one fuel bill's odometer, amount, and price per gallon.
     * A past reading may sit below the current odometer. A higher reading
     * raises the current odometer so it never sits below a fuel reading.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateFuel(int $id, array $input): array
    {
        $existing = $this->row($id);
        $this->assertFuelEntry($existing);
        $errors = [];
        $amount = $this->asInt($input['amountMinor'] ?? null);
        if ($amount === null || $amount < 1) {
            $errors['amountMinor'] = 'Enter an amount greater than zero.';
            $amount = 0;
        }
        $odometer = $this->asInt($input['odometerMiles'] ?? null);
        if ($odometer === null || $odometer < 0) {
            $errors['odometerMiles'] = 'Enter the odometer.';
            $odometer = 0;
        }
        $price = null;
        if (array_key_exists('pricePerGallonMinor', $input) && $input['pricePerGallonMinor'] !== null && $input['pricePerGallonMinor'] !== '') {
            $price = $this->asInt($input['pricePerGallonMinor']);
            if ($price === null || $price < 1) {
                $errors['pricePerGallonMinor'] = 'Enter a price per gallon greater than zero, or leave it blank.';
                $price = null;
            }
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE bills SET amount_minor = ?, odometer_miles = ?, price_per_gallon_minor = ?, updated_at = ? WHERE id = ?'
            )->execute([$amount, $odometer, $price, $now, $id]);
            $this->holdOdometer((int) $existing['vehicle_id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ((string) $existing['status'] !== 'paid' && $amount !== (int) $existing['amount_minor']) {
            $this->fill->apply($this->today($input));
        }

        return $this->get($id);
    }

    /**
     * Remove the fuel bill. The fuel row is that bill, so nothing is left behind.
     *
     * @param array<string, mixed> $input
     */
    public function deleteFuel(int $id, bool $confirm, array $input = []): void
    {
        $existing = $this->row($id);
        $this->assertFuelEntry($existing);
        if (!$confirm) {
            throw new ApiException(409, 'Confirm before deleting this fuel entry.');
        }
        $this->pdo->prepare('DELETE FROM bills WHERE id = ?')->execute([$id]);
        if ((string) $existing['status'] !== 'paid') {
            $this->fill->apply($this->today($input));
        }
    }

    /** @param array<string, mixed> $existing */
    private function assertFuelEntry(array $existing): void
    {
        if ($existing['source_account_id'] !== null) {
            throw new ApiException(409, 'This bill is kept by the account.');
        }
        if ((string) $existing['type'] !== 'fuel' || $existing['vehicle_id'] === null) {
            throw new ApiException(409, 'This bill is not a fuel entry.');
        }
        if (in_array($existing['status'], ['archived', 'hidden'], true)) {
            throw new ApiException(409, 'This bill is closed.');
        }
    }

    /** Raise the current odometer when a fuel reading now sits above it. Never lower it. */
    private function holdOdometer(int $vehicleId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT current_odometer_miles FROM vehicles WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$vehicleId, $this->actor->id()]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return;
        }
        $floor = $this->pdo->prepare(
            'SELECT MAX(odometer_miles) FROM bills WHERE vehicle_id = ? AND odometer_miles IS NOT NULL'
        );
        $floor->execute([$vehicleId]);
        $highest = $floor->fetchColumn();
        if ($highest === null || $highest === false || (int) $current >= (int) $highest) {
            return;
        }
        $this->pdo->prepare(
            'UPDATE vehicles SET current_odometer_miles = ?, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([(int) $highest, gmdate('c'), $vehicleId, $this->actor->id()]);
    }

    /** @param array<string, mixed> $input */
    public function markPaid(int $id, array $input): array
    {
        $existing = $this->row($id);
        if ($existing['status'] !== 'active') {
            throw new ApiException(409, 'Only an active bill can be marked paid.');
        }
        try {
            $paidOn = Dates::parse(is_string($input['paidOn'] ?? null) ? $input['paidOn'] : '')->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            throw new ApiException(422, 'Check the highlighted fields.', ['paidOn' => 'Use a YYYY-MM-DD date.']);
        }

        $now = gmdate('c');
        if ($existing['recurrence'] === 'once') {
            $this->pdo->prepare(
                "UPDATE bills SET status = 'paid', last_paid_on = ?, updated_at = ? WHERE id = ?"
            )->execute([$paidOn, $now, $id]);
        } else {
            $due = (string) $existing['next_due_date'];
            $next = Schedule::advance(
                (string) $existing['recurrence'],
                (string) $existing['anchor_date'],
                $due,
                max(1, (int) ($existing['every_count'] ?? 1)),
            );
            $this->pdo->prepare(
                'UPDATE bills SET last_paid_on = ?, next_due_date = ?, updated_at = ? WHERE id = ?'
            )->execute([$due, $next, $now, $id]);
        }

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function unpay(int $id): array
    {
        $existing = $this->row($id);
        if ($existing['last_paid_on'] === null) {
            throw new ApiException(409, 'This bill has not been marked paid.');
        }

        $now = gmdate('c');
        if ($existing['recurrence'] === 'once') {
            $this->pdo->prepare(
                "UPDATE bills SET status = 'active', last_paid_on = NULL, updated_at = ? WHERE id = ?"
            )->execute([$now, $id]);
        } else {
            $previous = Schedule::rewind(
                (string) $existing['recurrence'],
                (string) $existing['anchor_date'],
                (string) $existing['next_due_date'],
                max(1, (int) ($existing['every_count'] ?? 1)),
            );
            $this->pdo->prepare(
                'UPDATE bills SET last_paid_on = NULL, next_due_date = ?, updated_at = ? WHERE id = ?'
            )->execute([$previous, $now, $id]);
        }

        return $this->get($id);
    }

    /** Sum of amounts on paid occurrences. A repeating bill contributes its latest paid due date once. */
    public function paidMinor(): int
    {
        $total = 0;
        foreach ($this->listed(true) as $bill) {
            if ($bill['status'] === 'paused' || $bill['status'] === 'archived') {
                continue;
            }
            if (self::hasPaidOccurrence($bill)) {
                $total += (int) $bill['amountMinor'];
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $bill */
    public static function hasPaidOccurrence(array $bill): bool
    {
        if ($bill['status'] === 'paid') {
            return true;
        }

        return $bill['recurrence'] !== 'once' && $bill['lastPaidOn'] !== null;
    }

    /** @return list<array<string, mixed>> */
    public function calendar(string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ApiException(422, 'Use a YYYY-MM month.');
        }

        $visible = [];
        foreach ($this->listed(true) as $bill) {
            if ($bill['status'] === 'paused' || $bill['status'] === 'archived') {
                continue;
            }
            $visible[] = $bill;
        }
        $marks = [];
        foreach (Occurrences::inMonth($visible, $month) as $mark) {
            if ($mark['status'] === 'hidden' && !$mark['paid']) {
                continue;
            }
            if ($mark['amountMinor'] < 1 && !$mark['paid']) {
                continue;
            }
            $mark['baseAmountMinor'] = (int) $mark['amountMinor'];
            $marks[] = $mark;
        }

        return $marks;
    }

    /** @return list<array<string, mixed>> */
    public function calendarBetween(string $start, string $end): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1 || $end < $start) {
            throw new ApiException(422, 'Use a start and end date.');
        }
        $visible = [];
        foreach ($this->listed(true) as $bill) {
            if ($bill['status'] === 'paused' || $bill['status'] === 'archived') {
                continue;
            }
            $visible[] = $bill;
        }
        $marks = [];
        foreach (Occurrences::between($visible, $start, $end) as $mark) {
            if ($mark['status'] === 'hidden' && !$mark['paid']) {
                continue;
            }
            if ($mark['amountMinor'] < 1 && !$mark['paid']) {
                continue;
            }
            $mark['baseAmountMinor'] = (int) $mark['amountMinor'];
            $marks[] = $mark;
        }

        return $marks;
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $input */
    public function pause(int $id, array $input = []): array
    {
        return $this->setStatus($id, ['active'], 'paused', 'Only an active bill can be paused.', $input);
    }

    /** @param array<string, mixed> $input */
    public function resume(int $id, array $input = []): array
    {
        return $this->setStatus($id, ['paused'], 'active', 'Only a paused bill can resume.', $input);
    }

    /**
     * @param list<string> $from
     * @param array<string, mixed> $input
     */
    private function setStatus(int $id, array $from, string $to, string $message, array $input = []): array
    {
        $existing = $this->row($id);
        if ($existing['source_account_id'] !== null) {
            throw new ApiException(409, 'This bill is kept by the account.');
        }
        if (!in_array($existing['status'], $from, true)) {
            throw new ApiException(409, $message);
        }
        $this->pdo->prepare('UPDATE bills SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$to, gmdate('c'), $id]);
        $this->fill->apply($this->today($input));

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $input
     * @param ?array<string, mixed> $existing
     * @return array{
     *   name:string,type:string,otherLabel:?string,accountId:int,categoryId:int,amountMinor:int,
     *   currency:string,recurrence:string,every:int,nextDueDate:string,autopay:int,notes:?string,
     *   vehicleId:?int,odometerMiles:?int,pricePerGallonMinor:?int
     * }
     */
    private function validate(array $input, ?array $existing): array
    {
        $errors = [];
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Use a name of 1 to 80 characters.';
        }

        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        if (!in_array($type, BillTypes::all(), true)) {
            $errors['type'] = 'Choose a type.';
        }

        $otherLabel = null;
        if (BillTypes::namesItself($type)) {
            $otherLabel = trim(is_string($input['otherLabel'] ?? null) ? $input['otherLabel'] : '');
            if ($otherLabel === '' || mb_strlen($otherLabel) > 80) {
                $errors['otherLabel'] = 'Use a name of 1 to 80 characters.';
                $otherLabel = null;
            }
        }

        $accountId = is_int($input['accountId'] ?? null)
            ? $input['accountId']
            : (is_string($input['accountId'] ?? null) && ctype_digit($input['accountId']) ? (int) $input['accountId'] : 0);
        $account = null;
        if ($accountId < 1) {
            $errors['accountId'] = 'Choose the account this bill is drawn from.';
        } else {
            $stmt = $this->pdo->prepare('SELECT id, archived_at FROM accounts WHERE id = ? AND user_id = ?');
            $stmt->execute([$accountId, $this->actor->id()]);
            $account = $stmt->fetch();
            if (!is_array($account)) {
                $errors['accountId'] = 'Choose the account this bill is drawn from.';
            } elseif ($account['archived_at'] !== null && (int) ($existing['account_id'] ?? 0) !== $accountId) {
                $errors['accountId'] = 'That account is archived.';
            }
        }

        $amount = $this->asInt($input['amountMinor'] ?? null);
        if ($amount === null || $amount < 1) {
            $errors['amountMinor'] = 'Enter an amount greater than zero.';
        }

        $reading = $this->reading($type, $input, $existing, $errors);

        $recurrence = is_string($input['recurrence'] ?? null) ? $input['recurrence'] : '';
        if (!in_array($recurrence, self::RECURRENCE, true)) {
            $errors['recurrence'] = 'Choose how often it is due.';
        }
        $every = 1;
        if ($recurrence !== 'once') {
            $parsed = $this->asInt($input['every'] ?? 1);
            if ($parsed === null || $parsed < 1 || $parsed > 11) {
                $errors['every'] = 'Choose from 1 to 11.';
            } else {
                $every = $parsed;
            }
        }

        $nextDue = is_string($input['nextDueDate'] ?? null) ? $input['nextDueDate'] : '';
        try {
            $nextDue = Dates::parse($nextDue)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            $errors['nextDueDate'] = 'Use a YYYY-MM-DD date.';
            $nextDue = '';
        }

        $notes = $input['notes'] ?? null;
        $noteText = null;
        if ($notes !== null && $notes !== '') {
            if (!is_string($notes) || mb_strlen(trim($notes)) > 2000) {
                $errors['notes'] = 'Keep notes to 2000 characters.';
            } else {
                $noteText = trim($notes);
                if ($noteText === '') {
                    $noteText = null;
                }
            }
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'name' => $name,
            'type' => $type,
            'otherLabel' => $otherLabel,
            'accountId' => $accountId,
            'categoryId' => $this->bucketId($type, $input, $existing),
            'amountMinor' => (int) $amount,
            'currency' => 'USD',
            'recurrence' => $recurrence,
            'every' => $every,
            'nextDueDate' => $nextDue,
            'autopay' => !empty($input['autopay']) ? 1 : 0,
            'notes' => $noteText,
            'vehicleId' => $reading['vehicleId'],
            'odometerMiles' => $reading['odometerMiles'],
            'pricePerGallonMinor' => $reading['pricePerGallonMinor'],
        ];
    }

    /**
     * A fuel reading stays on the fuel bill. Other types store none.
     * Absent fields keep the reading already stored.
     *
     * @param array<string, mixed> $input
     * @param ?array<string, mixed> $existing
     * @param array<string, string> $errors
     * @return array{vehicleId:?int,odometerMiles:?int,pricePerGallonMinor:?int}
     */
    private function reading(string $type, array $input, ?array $existing, array &$errors): array
    {
        $blank = ['vehicleId' => null, 'odometerMiles' => null, 'pricePerGallonMinor' => null];
        if ($type !== 'fuel') {
            return $blank;
        }
        $mentioned = array_key_exists('vehicleId', $input)
            || array_key_exists('odometerMiles', $input)
            || array_key_exists('pricePerGallonMinor', $input);
        if (!$mentioned && $existing !== null) {
            return [
                'vehicleId' => $existing['vehicle_id'] === null ? null : (int) $existing['vehicle_id'],
                'odometerMiles' => $existing['odometer_miles'] === null ? null : (int) $existing['odometer_miles'],
                'pricePerGallonMinor' => $existing['price_per_gallon_minor'] === null ? null : (int) $existing['price_per_gallon_minor'],
            ];
        }
        $vehicleId = null;
        if (array_key_exists('vehicleId', $input) && $input['vehicleId'] !== null && $input['vehicleId'] !== '') {
            $vehicleId = $this->asInt($input['vehicleId']);
            if ($vehicleId === null || !$this->ownsVehicle($vehicleId)) {
                $errors['vehicleId'] = 'Choose a vehicle.';
                $vehicleId = null;
            }
        }
        $odometer = null;
        if (array_key_exists('odometerMiles', $input) && $input['odometerMiles'] !== null && $input['odometerMiles'] !== '') {
            $odometer = $this->asInt($input['odometerMiles']);
            if ($odometer === null || $odometer < 0) {
                $errors['odometerMiles'] = 'Enter whole miles, or leave it blank.';
                $odometer = null;
            }
        }
        $price = null;
        if (array_key_exists('pricePerGallonMinor', $input) && $input['pricePerGallonMinor'] !== null && $input['pricePerGallonMinor'] !== '') {
            $price = $this->asInt($input['pricePerGallonMinor']);
            if ($price === null || $price < 1) {
                $errors['pricePerGallonMinor'] = 'Enter a price per gallon greater than zero, or leave it blank.';
                $price = null;
            }
        }
        if (($odometer !== null || $price !== null) && $vehicleId === null && !isset($errors['vehicleId'])) {
            $errors['vehicleId'] = 'Choose a vehicle.';
        }

        return [
            'vehicleId' => $vehicleId,
            'odometerMiles' => $odometer,
            'pricePerGallonMinor' => $price,
        ];
    }

    private function ownsVehicle(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM vehicles WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $input
     * @param ?array<string, mixed> $existing
     */
    /**
     * @param array<string, mixed> $input
     * @param ?array<string, mixed> $existing
     */
    private function bucketId(string $type, array $input, ?array $existing): int
    {
        $userId = $this->actor->id();
        if ($type === 'fuel') {
            return SystemBuckets::idForType($this->pdo, $userId, 'fuel');
        }
        $group = BillTypes::group($type);
        $fallback = SystemBuckets::idForType($this->pdo, $userId, $type);
        if (!array_key_exists('categoryId', $input) || $input['categoryId'] === null || $input['categoryId'] === '' || $input['categoryId'] === 0) {
            if ($existing !== null && (string) $existing['type'] === $type) {
                return (int) $existing['category_id'];
            }

            return $fallback;
        }
        $id = $this->asInt($input['categoryId']);
        if ($id === null || $id < 1) {
            throw new ApiException(422, 'Check the highlighted fields.', ['categoryId' => 'Choose a bucket.']);
        }
        $stmt = $this->pdo->prepare(
            'SELECT category_group, role, bill_type, archived_at FROM categories WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $bucket = $stmt->fetch();
        if (!is_array($bucket)) {
            throw new ApiException(422, 'Check the highlighted fields.', ['categoryId' => 'Choose a bucket.']);
        }
        $kept = $existing !== null && (int) $existing['category_id'] === $id;
        if ($bucket['archived_at'] !== null && !$kept) {
            throw new ApiException(422, 'Check the highlighted fields.', ['categoryId' => 'That bucket is archived.']);
        }
        if ((string) $bucket['category_group'] !== $group) {
            return $fallback;
        }
        if ((string) $bucket['role'] === 'locked' && (string) $bucket['bill_type'] !== $type) {
            return $fallback;
        }

        return $id;
    }

    /** @param array<string, mixed> $input */
    private function today(array $input): string
    {
        $today = $input['today'] ?? null;

        return is_string($today) && $today !== '' ? $today : gmdate('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.* FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE b.id = ? AND a.user_id = ?'
        );
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Bill not found.');
        }

        return $row;
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

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'otherLabel' => $row['other_label'],
            'accountId' => (int) $row['account_id'],
            'accountName' => $row['account_name'],
            'currency' => 'USD',
            'exponent' => 2,
            'amountMinor' => (int) $row['amount_minor'],
            'vehicleId' => ($row['vehicle_id'] ?? null) === null ? null : (int) $row['vehicle_id'],
            'odometerMiles' => ($row['odometer_miles'] ?? null) === null ? null : (int) $row['odometer_miles'],
            'pricePerGallonMinor' => ($row['price_per_gallon_minor'] ?? null) === null ? null : (int) $row['price_per_gallon_minor'],
            'recurrence' => $row['recurrence'],
            'every' => max(1, (int) ($row['every_count'] ?? 1)),
            'anchorDate' => $row['anchor_date'],
            'nextDueDate' => $row['next_due_date'],
            'lastPaidOn' => $row['last_paid_on'],
            'autopay' => (int) $row['autopay'] === 1,
            'notes' => $row['notes'],
            'status' => $row['status'],
            'categoryId' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'categoryName' => $row['category_name'] ?? null,
            'locked' => ($row['source_account_id'] ?? null) !== null,
            'sourceAccountId' => ($row['source_account_id'] ?? null) === null ? null : (int) $row['source_account_id'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listed(bool $includeHidden): array
    {
        $sql = 'SELECT b.*, a.name AS account_name, c.name AS category_name
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             LEFT JOIN categories c ON c.id = b.category_id
             WHERE a.user_id = ?';
        if (!$includeHidden) {
            $sql .= " AND b.status != 'hidden'";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();

        return array_map($this->present(...), is_array($rows) ? $rows : []);
    }

    /**
     * A fuel, need, want, or saving log is an unpaid one-time bill. It does not move a balance.
     * It raises that bucket's auto-assigned amount, and the envelope shrinks by the same
     * amount when it has room, so the target stays. An unpaid fuel log still advances the odometer.
     * A need log does not accept Fuel.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function log(array $input): array
    {
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        if ($kind === 'fuel') {
            return $this->logFuel($input);
        }
        if ($kind === 'need' || $kind === 'want' || $kind === 'saving') {
            return $this->logSpend($input, $kind);
        }

        throw new ApiException(422, 'Check the highlighted fields.', ['kind' => 'Choose Fuel, Need, Want, or Saving.']);
    }

    /** @param array<string, mixed> $input */
    public function archiveBill(int $id, array $input = []): array
    {
        $existing = $this->row($id);
        if ($existing['source_account_id'] !== null) {
            throw new ApiException(409, 'This bill is kept by the account.');
        }
        if ($existing['status'] !== 'paused') {
            throw new ApiException(409, 'Only a paused bill can be archived.');
        }
        $this->pdo->prepare("UPDATE bills SET status = 'archived', updated_at = ? WHERE id = ?")
            ->execute([gmdate('c'), $id]);
        $this->fill->apply($this->today($input));

        return $this->get($id);
    }

    /** @param array<string, mixed> $input */
    public function unarchiveBill(int $id, array $input = []): array
    {
        $existing = $this->row($id);
        if ($existing['status'] !== 'archived') {
            throw new ApiException(409, 'Only an archived bill can be restored.');
        }
        $this->pdo->prepare("UPDATE bills SET status = 'paused', updated_at = ? WHERE id = ?")
            ->execute([gmdate('c'), $id]);
        $this->fill->apply($this->today($input));

        return $this->get($id);
    }

    /** @param array<string, mixed> $existing */
    private function editableLog(array $existing): bool
    {
        if ((string) $existing['recurrence'] !== 'once' || $existing['source_account_id'] !== null) {
            return false;
        }

        return BillTypes::group((string) $existing['type']) !== '';
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     */
    private function guardLogEdit(array $existing, array $data): void
    {
        $type = (string) $existing['type'];
        $next = (string) $data['type'];
        if ($type === 'fuel' && $next !== 'fuel') {
            throw new ApiException(422, 'Check the highlighted fields.', ['type' => 'A fuel log stays fuel.']);
        }
        if ($type !== 'fuel' && BillTypes::group($type) === 'needs' && (BillTypes::group($next) !== 'needs' || $next === 'fuel')) {
            throw new ApiException(422, 'Check the highlighted fields.', ['type' => 'Choose a needs type.']);
        }
        if (BillTypes::group($type) === 'wants' && BillTypes::group($next) !== 'wants') {
            throw new ApiException(422, 'Check the highlighted fields.', ['type' => 'Choose a wants type.']);
        }
        if (BillTypes::group($type) === 'savings' && BillTypes::group($next) !== 'savings') {
            throw new ApiException(422, 'Check the highlighted fields.', ['type' => 'Choose a savings type.']);
        }
        if ($data['recurrence'] !== 'once') {
            throw new ApiException(422, 'Check the highlighted fields.', ['recurrence' => 'A log is one time.']);
        }
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private function logFuel(array $input): array
    {
        $errors = [];
        $today = $this->today($input);
        $date = $this->logDate($input, $today, $errors);
        $accountId = $this->logAccount($input, $errors);
        $amount = $this->asInt($input['amountMinor'] ?? null);
        if ($amount === null || $amount < 1) {
            $errors['amountMinor'] = 'Enter an amount greater than zero.';
            $amount = 0;
        }
        $vehicleId = $this->asInt($input['vehicleId'] ?? null);
        $vehicle = null;
        if ($vehicleId === null || !$this->ownsVehicle($vehicleId)) {
            $errors['vehicleId'] = 'Choose a vehicle.';
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM vehicles WHERE id = ? AND user_id = ?');
            $stmt->execute([$vehicleId, $this->actor->id()]);
            $vehicle = $stmt->fetch();
            if (!is_array($vehicle)) {
                $errors['vehicleId'] = 'Choose a vehicle.';
                $vehicle = null;
            }
        }
        $odometer = $this->asInt($input['odometerMiles'] ?? null);
        if ($odometer === null || $odometer < 0) {
            $errors['odometerMiles'] = 'Enter the odometer.';
            $odometer = 0;
        } elseif (is_array($vehicle) && $odometer < (int) $vehicle['current_odometer_miles']) {
            $errors['odometerMiles'] = 'That reading is below the current odometer.';
        }
        $price = null;
        if (array_key_exists('pricePerGallonMinor', $input) && $input['pricePerGallonMinor'] !== null && $input['pricePerGallonMinor'] !== '') {
            $price = $this->asInt($input['pricePerGallonMinor']);
            if ($price === null || $price < 1) {
                $errors['pricePerGallonMinor'] = 'Enter a price per gallon greater than zero, or leave it blank.';
                $price = null;
            }
        }
        $notes = $this->logNotes($input, $errors);
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), 'fuel');
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->advanceOdometer($vehicleId, $odometer);
            $this->pdo->prepare(
                'INSERT INTO bills (
                    name, type, account_id, category_id, amount_minor, currency,
                    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
                    created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor
                 ) VALUES (\'Fuel\', \'fuel\', ?, ?, ?, \'USD\', \'once\', ?, ?, NULL, 0, ?, \'active\', ?, ?, ?, ?, ?)'
            )->execute([
                $accountId, $categoryId, $amount, $date, $date, $notes, $now, $now,
                $vehicleId, $odometer, $price,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->fill->apply($today);

        return $this->get($id);
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private function logSpend(array $input, string $kind): array
    {
        $errors = [];
        $today = $this->today($input);
        $date = $this->logDate($input, $today, $errors);
        $accountId = $this->logAccount($input, $errors);
        $group = $kind === 'need' ? 'needs' : ($kind === 'saving' ? 'savings' : 'wants');
        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        if (BillTypes::group($type) !== $group || ($kind === 'need' && $type === 'fuel')) {
            $errors['type'] = $kind === 'need'
                ? 'Choose a needs type.'
                : ($kind === 'saving' ? 'Choose a savings type.' : 'Choose a wants type.');
        }
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Use a name of 1 to 80 characters.';
        }
        $other = null;
        if (BillTypes::namesItself($type)) {
            $other = trim(is_string($input['otherLabel'] ?? null) ? $input['otherLabel'] : '');
            if ($other === '' || mb_strlen($other) > 80) {
                $errors['otherLabel'] = 'Use a name of 1 to 80 characters.';
                $other = null;
            }
        }
        $amount = $this->asInt($input['amountMinor'] ?? null);
        if ($amount === null || $amount < 1) {
            $errors['amountMinor'] = 'Enter an amount greater than zero.';
            $amount = 0;
        }
        $notes = $this->logNotes($input, $errors);
        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }
        $categoryId = SystemBuckets::idForType($this->pdo, $this->actor->id(), $type);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO bills (
                name, type, other_label, account_id, category_id, amount_minor, currency,
                recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, \'USD\', \'once\', ?, ?, NULL, 0, ?, \'active\', ?, ?)'
        )->execute([
            $name, $type, $other, $accountId, $categoryId, $amount, $date, $date, $notes, $now, $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->fill->apply($today);

        return $this->get($id);
    }

    /** @param array<string, string> $errors */
    private function logDate(array $input, string $today, array &$errors): string
    {
        $raw = is_string($input['date'] ?? null) ? $input['date'] : (is_string($input['postedOn'] ?? null) ? $input['postedOn'] : '');
        try {
            $date = Dates::parse($raw)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            $errors['date'] = 'Use a YYYY-MM-DD date.';

            return $today;
        }
        if ($date > $today) {
            $errors['date'] = 'The date cannot be after today.';
        }

        return $date;
    }

    /** @param array<string, mixed> $input
     *  @param array<string, string> $errors
     */
    private function logAccount(array $input, array &$errors): int
    {
        $accountId = $this->asInt($input['accountId'] ?? null) ?? 0;
        $stmt = $this->pdo->prepare('SELECT id, archived_at FROM accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$accountId, $this->actor->id()]);
        $account = $stmt->fetch();
        if (!is_array($account) || $account['archived_at'] !== null) {
            $errors['accountId'] = 'Choose an open account.';

            return 0;
        }

        return $accountId;
    }

    /** @param array<string, mixed> $input
     *  @param array<string, string> $errors
     */
    private function logNotes(array $input, array &$errors): ?string
    {
        $notes = $input['notes'] ?? null;
        if ($notes === null || $notes === '') {
            return null;
        }
        if (!is_string($notes) || mb_strlen(trim($notes)) > 2000) {
            $errors['notes'] = 'Keep notes to 2000 characters.';

            return null;
        }
        $text = trim($notes);

        return $text === '' ? null : $text;
    }

    private function advanceOdometer(int $vehicleId, int $reading): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT current_odometer_miles, origin_odometer_miles FROM vehicles WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$vehicleId, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }
        $current = (int) $row['current_odometer_miles'];
        $prior = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bills WHERE vehicle_id = ? AND type = \'fuel\' AND odometer_miles IS NOT NULL'
        );
        $prior->execute([$vehicleId]);
        $origin = $row['origin_odometer_miles'];
        if ((int) $prior->fetchColumn() === 0 && $origin === null) {
            $origin = $current;
        }
        $next = $reading > $current ? $reading : $current;
        $this->pdo->prepare(
            'UPDATE vehicles SET current_odometer_miles = ?, origin_odometer_miles = ?, updated_at = ? WHERE id = ?'
        )->execute([$next, $origin, gmdate('c'), $vehicleId]);
    }
}
