<?php

declare(strict_types=1);

namespace App\Bill;

use App\Auth\Actor;
use App\Category\SystemBuckets;
use App\Envelope\BillFill;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Money\StoredRates;
use App\Money\UnknownCurrency;
use App\Support\Dates;
use PDO;

final class BillService
{
    private const RECURRENCE = ['once', 'weekly', 'monthly', 'yearly'];

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
             WHERE a.user_id = ?
             ORDER BY CASE b.status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END,
                      b.next_due_date, b.id"
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();

        return array_map($this->present(...), is_array($rows) ? $rows : []);
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
                recurrence, anchor_date, next_due_date, autopay, notes, status, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?)'
        )->execute([
            $data['name'], $data['type'], $data['otherLabel'], $data['accountId'], $data['categoryId'], $data['amountMinor'],
            $data['currency'], $data['recurrence'],
            $data['nextDueDate'], $data['nextDueDate'], $data['autopay'], $data['notes'], $now, $now,
        ]);
        $this->fill->apply($this->today($input));

        return $this->get((int) $this->pdo->lastInsertId());
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $existing = $this->row($id);
        if ($existing['status'] === 'paid') {
            throw new ApiException(409, 'This bill is closed.');
        }
        $data = $this->validate($input, $existing);
        $anchor = $data['nextDueDate'] !== $existing['next_due_date']
            ? $data['nextDueDate']
            : (string) $existing['anchor_date'];
        $refill = $data['type'] !== (string) $existing['type']
            || $data['categoryId'] !== (int) $existing['category_id']
            || $data['amountMinor'] !== (int) $existing['amount_minor']
            || $data['currency'] !== (string) $existing['currency']
            || $data['recurrence'] !== (string) $existing['recurrence']
            || $data['nextDueDate'] !== (string) $existing['next_due_date']
            || $anchor !== (string) $existing['anchor_date'];
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE bills SET
                name = ?, type = ?, other_label = ?, account_id = ?, category_id = ?, amount_minor = ?, currency = ?,
                recurrence = ?, anchor_date = ?, next_due_date = ?,
                autopay = ?, notes = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $data['name'], $data['type'], $data['otherLabel'], $data['accountId'], $data['categoryId'], $data['amountMinor'],
            $data['currency'], $data['recurrence'],
            $anchor, $data['nextDueDate'], $data['autopay'], $data['notes'], $now, $id,
        ]);
        if ($refill) {
            $this->fill->apply($this->today($input));
        }

        return $this->get($id);
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
        foreach ($this->list() as $bill) {
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

        $marks = Occurrences::inMonth($this->list(), $month);
        $base = $this->actor->baseCurrency();
        $exponent = $this->currencies->exponent($base);
        $rates = StoredRates::map($this->pdo, $this->actor->id(), $base);
        foreach ($marks as $index => $mark) {
            $marks[$index]['baseAmountMinor'] = StoredRates::toBase(
                (int) $mark['amountMinor'],
                (string) $mark['currency'],
                (int) $mark['exponent'],
                $base,
                $exponent,
                $rates,
            );
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
     *   currency:string,recurrence:string,nextDueDate:string,autopay:int,notes:?string
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

        $currency = '';
        $sentCurrency = $input['currency'] ?? null;
        if (($sentCurrency === null || $sentCurrency === '') && $existing !== null) {
            $currency = (string) $existing['currency'];
        } elseif (is_string($sentCurrency) && $sentCurrency !== '') {
            try {
                $currency = $this->currencies->assert($sentCurrency);
            } catch (UnknownCurrency) {
                $errors['currency'] = 'Use a three-letter ISO currency code.';
            }
        } elseif (is_array($account)) {
            $code = $this->pdo->prepare('SELECT currency FROM accounts WHERE id = ?');
            $code->execute([$accountId]);
            $found = $code->fetchColumn();
            $currency = is_string($found) ? $found : '';
        }
        if ($currency === '' && !isset($errors['currency'])) {
            $errors['currency'] = 'Choose a currency.';
        }

        $recurrence = is_string($input['recurrence'] ?? null) ? $input['recurrence'] : '';
        if (!in_array($recurrence, self::RECURRENCE, true)) {
            $errors['recurrence'] = 'Choose how often it is due.';
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
            'currency' => $currency,
            'recurrence' => $recurrence,
            'nextDueDate' => $nextDue,
            'autopay' => !empty($input['autopay']) ? 1 : 0,
            'notes' => $noteText,
        ];
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
            return SystemBuckets::id($this->pdo, $userId, 'fuel', 'needs');
        }
        $group = BillTypes::group($type);
        $fallback = SystemBuckets::id($this->pdo, $userId, 'default', $group);
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
            'SELECT category_group, role, archived_at FROM categories WHERE id = ? AND user_id = ?'
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
        if ((string) $bucket['category_group'] !== $group || (string) $bucket['role'] === 'fuel') {
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
        $currency = (string) $row['currency'];

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'otherLabel' => $row['other_label'],
            'accountId' => (int) $row['account_id'],
            'accountName' => $row['account_name'],
            'currency' => $currency,
            'exponent' => $this->currencies->exponent($currency),
            'amountMinor' => (int) $row['amount_minor'],
            'recurrence' => $row['recurrence'],
            'anchorDate' => $row['anchor_date'],
            'nextDueDate' => $row['next_due_date'],
            'lastPaidOn' => $row['last_paid_on'],
            'autopay' => (int) $row['autopay'] === 1,
            'notes' => $row['notes'],
            'status' => $row['status'],
            'categoryId' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'categoryName' => $row['category_name'] ?? null,
        ];
    }
}
