<?php

declare(strict_types=1);

namespace App\Account;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use PDO;

final class AccountService
{
    private const TYPES = ['checking', 'savings', 'cash', 'credit', 'loan', 'fuel', 'other'];

    private const FIXED_SIDE = [
        'checking' => 'asset',
        'savings' => 'asset',
        'cash' => 'asset',
        'credit' => 'liability',
        'loan' => 'liability',
        'fuel' => 'liability',
    ];

    private const LIABILITY = ['credit', 'loan', 'fuel'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
        private readonly Actor $actor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $includeArchived): array
    {
        $sql = 'SELECT * FROM accounts WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= " ORDER BY CASE side WHEN 'asset' THEN 0 ELSE 1 END, name COLLATE NOCASE, id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();

        return array_map($this->present(...), is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Account not found.');
        }

        return $this->present($row);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $data = $this->validate($input);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO accounts (
                user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
                due_day, credit_limit_minor, autopay_choice, institution, notes, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->actor->id(),
            $data['name'], $data['type'], $data['side'], $data['currency'], $data['balanceMinor'],
            $data['interestBps'], $data['minimumDueMinor'], $data['dueDay'], $data['creditLimitMinor'],
            $data['autopayChoice'], $data['institution'], $data['notes'], $now, $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->syncBill($id);

        return $this->get($id);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $this->get($id);
        $data = $this->validate($input);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE accounts SET
                name = ?, type = ?, side = ?, currency = ?, balance_minor = ?, interest_bps = ?,
                minimum_due_minor = ?, due_day = ?, credit_limit_minor = ?, autopay_choice = ?,
                institution = ?, notes = ?, updated_at = ?
             WHERE id = ? AND user_id = ?'
        )->execute([
            $data['name'], $data['type'], $data['side'], $data['currency'], $data['balanceMinor'],
            $data['interestBps'], $data['minimumDueMinor'], $data['dueDay'], $data['creditLimitMinor'],
            $data['autopayChoice'], $data['institution'], $data['notes'], $now, $id, $this->actor->id(),
        ]);
        $this->syncBill($id);

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function archive(int $id): array
    {
        $this->get($id);
        (new LiabilityBill($this->pdo, $this->actor))->hide($id);
        if ($this->openBillCount($id) > 0) {
            throw new ApiException(409, 'Stop the bills that draw from this account before archiving it.');
        }
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE accounts SET archived_at = COALESCE(archived_at, ?), updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $now, $id, $this->actor->id()]);

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function unarchive(int $id): array
    {
        $this->get($id);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE accounts SET archived_at = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $id, $this->actor->id()]);
        $this->syncBill($id);

        return $this->get($id);
    }

    /** @param array<string, mixed> $input
     *  @return array{
     *    name:string,type:string,side:string,currency:string,balanceMinor:int,
     *    interestBps:?int,minimumDueMinor:?int,dueDay:?int,creditLimitMinor:?int,
     *    autopayChoice:string,institution:?string,notes:?string
     *  }
     */
    private function validate(array $input): array
    {
        $errors = [];
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Use a name of 1 to 80 characters.';
        }

        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Choose a type.';
        }

        $side = is_string($input['side'] ?? null) ? $input['side'] : '';
        if ($type === 'other') {
            if (!in_array($side, ['asset', 'liability'], true)) {
                $errors['side'] = 'Choose asset or liability.';
            }
        } elseif (isset(self::FIXED_SIDE[$type])) {
            $side = self::FIXED_SIDE[$type];
        }

        $balance = $this->minor($input['balanceMinor'] ?? null, true);
        if (!is_int($balance)) {
            $errors['balanceMinor'] = 'Enter a balance of zero or more.';
        }

        $interest = $this->optionalMinor($input['interestBps'] ?? null);
        if ($interest === false) {
            $errors['interestBps'] = 'Enter an annual percent, or leave it blank.';
        } elseif (is_int($interest) && $interest > 100000) {
            $errors['interestBps'] = 'Keep interest at or below 1000%.';
        }

        $minimum = null;
        $dueDay = null;
        $limit = null;
        $autopay = 'none';
        $liability = in_array($type, self::LIABILITY, true);
        if ($liability) {
            $choice = is_string($input['autopayChoice'] ?? null) ? $input['autopayChoice'] : 'none';
            if (!in_array($choice, ['none', 'minimum', 'balance'], true)) {
                $errors['autopayChoice'] = 'Choose none, minimum, or current balance.';
            } else {
                $autopay = $choice;
            }
            $minimum = $this->optionalMinor($input['minimumDueMinor'] ?? null);
            if ($minimum === false) {
                $errors['minimumDueMinor'] = 'Enter a minimum due of zero or more, or leave it blank.';
                $minimum = null;
            }
            $dueDay = $this->dueDay($input['dueDay'] ?? null);
            if ($dueDay === false) {
                $errors['dueDay'] = 'Use a day from 1 to 31, or leave it blank.';
                $dueDay = null;
            }
            if ($autopay === 'minimum' || $autopay === 'balance') {
                if (!is_int($dueDay)) {
                    $errors['dueDay'] = 'Enter the due day.';
                }
            }
            if ($autopay === 'minimum' && !is_int($minimum)) {
                $errors['minimumDueMinor'] = 'Enter the minimum due.';
            }
        }
        if ($type === 'credit' || $type === 'fuel') {
            $limit = $this->optionalMinor($input['creditLimitMinor'] ?? null);
            if ($limit === false) {
                $errors['creditLimitMinor'] = 'Enter a credit limit of zero or more, or leave it blank.';
                $limit = null;
            }
        }

        $institution = $this->optionalText($input['institution'] ?? null, 120);
        if ($institution === false) {
            $errors['institution'] = 'Keep the institution to 120 characters.';
            $institution = null;
        }
        $notes = $this->optionalText($input['notes'] ?? null, 2000);
        if ($notes === false) {
            $errors['notes'] = 'Keep notes to 2000 characters.';
            $notes = null;
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'name' => $name,
            'type' => $type,
            'side' => $side,
            'currency' => 'USD',
            'balanceMinor' => (int) $balance,
            'interestBps' => is_int($interest) ? $interest : null,
            'minimumDueMinor' => is_int($minimum) ? $minimum : null,
            'dueDay' => is_int($dueDay) ? $dueDay : null,
            'creditLimitMinor' => is_int($limit) ? $limit : null,
            'autopayChoice' => $autopay,
            'institution' => is_string($institution) ? $institution : null,
            'notes' => is_string($notes) ? $notes : null,
        ];
    }

    private function syncBill(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }
        (new LiabilityBill($this->pdo, $this->actor))->sync($row);
    }

    private function openBillCount(int $accountId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bills WHERE account_id = ? AND status IN ('active', 'paused')"
        );
        $stmt->execute([$accountId]);

        return (int) $stmt->fetchColumn();
    }

    private function minor(mixed $value, bool $allowZero): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = $this->asInt($value);
        if ($int === null || $int < 0 || (!$allowZero && $int === 0)) {
            return false;
        }

        return $int;
    }

    /** @return int|null|false null when blank, false when invalid */
    private function optionalMinor(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = $this->asInt($value);
        if ($int === null || $int < 0) {
            return false;
        }

        return $int;
    }

    /** @return int|null|false */
    private function dueDay(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = $this->asInt($value);
        if ($int === null || $int < 1 || $int > 31) {
            return false;
        }

        return $int;
    }

    /** @return string|null|false */
    private function optionalText(mixed $value, int $max): string|null|false
    {
        if ($value === null) {
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
            'side' => $row['side'],
            'currency' => 'USD',
            'exponent' => 2,
            'balanceMinor' => (int) $row['balance_minor'],
            'interestBps' => $row['interest_bps'] === null ? null : (int) $row['interest_bps'],
            'minimumDueMinor' => $row['minimum_due_minor'] === null ? null : (int) $row['minimum_due_minor'],
            'dueDay' => $row['due_day'] === null ? null : (int) $row['due_day'],
            'creditLimitMinor' => $row['credit_limit_minor'] === null ? null : (int) $row['credit_limit_minor'],
            'autopayChoice' => (string) ($row['autopay_choice'] ?? 'none'),
            'institution' => $row['institution'],
            'notes' => $row['notes'],
            'archivedAt' => $row['archived_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
