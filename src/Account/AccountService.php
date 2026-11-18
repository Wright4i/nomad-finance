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
        $sql .= " ORDER BY CASE
            WHEN gas_favorite = 1 THEN 0
            WHEN shopping_favorite = 1 THEN 1
            WHEN asset_favorite = 1 THEN 2
            ELSE 3
        END, type COLLATE NOCASE, name COLLATE NOCASE, id";
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
        $this->pdo->beginTransaction();
        try {
            $this->claim($data, $now, null);
            $this->pdo->prepare(
                'INSERT INTO accounts (
                    user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
                    due_day, credit_limit_minor, autopay_choice, bank_autopay, bill_recurrence, bill_every,
                    liability_bill_type,
                    institution, holder, notes, gas_favorite, shopping_favorite, asset_favorite,
                    in_budget, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->actor->id(),
                $data['name'], $data['type'], $data['side'], $data['currency'], $data['balanceMinor'],
                $data['interestBps'], $data['minimumDueMinor'], $data['dueDay'], $data['creditLimitMinor'],
                $data['autopayChoice'], $data['bankAutopay'], $data['billRecurrence'], $data['billEvery'],
                $data['liabilityBillType'],
                $data['institution'], $data['holder'], $data['notes'],
                $data['gasFavorite'], $data['shoppingFavorite'], $data['assetFavorite'],
                $data['inBudget'], $now, $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->syncBill($id);
        $this->aimLockedBills();

        return $this->get($id);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $existing = $this->get($id);
        $data = $this->validate($input, $existing);
        if ($existing['archivedAt'] !== null && ($data['gasFavorite'] === 1 || $data['shoppingFavorite'] === 1 || $data['assetFavorite'] === 1)) {
            $key = $data['gasFavorite'] === 1 ? 'gasFavorite' : ($data['shoppingFavorite'] === 1 ? 'shoppingFavorite' : 'assetFavorite');
            throw new ApiException(422, 'Check the highlighted fields.', [
                $key => 'Unarchive this account before choosing a favorite.',
            ]);
        }
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->claim($data, $now, $id);
            $this->pdo->prepare(
                'UPDATE accounts SET
                    name = ?, type = ?, side = ?, currency = ?, balance_minor = ?, interest_bps = ?,
                    minimum_due_minor = ?, due_day = ?, credit_limit_minor = ?, autopay_choice = ?,
                    bank_autopay = ?, bill_recurrence = ?, bill_every = ?, liability_bill_type = ?,
                    institution = ?, holder = ?, notes = ?,
                    gas_favorite = ?, shopping_favorite = ?, asset_favorite = ?,
                    in_budget = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            )->execute([
                $data['name'], $data['type'], $data['side'], $data['currency'], $data['balanceMinor'],
                $data['interestBps'], $data['minimumDueMinor'], $data['dueDay'], $data['creditLimitMinor'],
                $data['autopayChoice'], $data['bankAutopay'], $data['billRecurrence'], $data['billEvery'],
                $data['liabilityBillType'],
                $data['institution'], $data['holder'], $data['notes'],
                $data['gasFavorite'], $data['shoppingFavorite'], $data['assetFavorite'],
                $data['inBudget'], $now,
                $id, $this->actor->id(),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->syncBill($id);
        $this->aimLockedBills();

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
            'UPDATE accounts SET archived_at = COALESCE(archived_at, ?), gas_favorite = 0, shopping_favorite = 0, asset_favorite = 0, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $now, $id, $this->actor->id()]);
        $this->aimLockedBills();

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

    public function delete(int $id, bool $confirm): void
    {
        $existing = $this->get($id);
        if ($existing['archivedAt'] === null) {
            throw new ApiException(409, 'Only an archived account can be deleted.');
        }
        if (!$confirm) {
            throw new ApiException(409, 'Confirm before deleting this account.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($this->drawingBillCount($id) > 0) {
                throw new ApiException(409, 'Stop the bills that draw from this account before deleting it.');
            }
            if ($this->stayCount($id) > 0) {
                throw new ApiException(409, 'Move the stays that draw from this account before deleting it.');
            }
            $now = gmdate('c');
            $this->pdo->prepare(
                'UPDATE bills SET source_account_id = NULL, updated_at = ? WHERE source_account_id = ? AND account_id != ?'
            )->execute([$now, $id, $id]);
            $this->pdo->prepare(
                'UPDATE trips SET account_id = NULL, updated_at = ? WHERE account_id = ? AND user_id = ? AND total_minor = 0'
            )->execute([$now, $id, $this->actor->id()]);
            $this->pdo->prepare('DELETE FROM accounts WHERE id = ? AND user_id = ?')
                ->execute([$id, $this->actor->id()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     */
    private function validate(array $input, ?array $existing = null): array
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
        $bank = 0;
        $billRecurrence = 'monthly';
        $billEvery = 1;
        $liabilityBillType = 'debt';
        $liability = in_array($type, self::LIABILITY, true);
        if ($liability) {
            $choice = is_string($input['autopayChoice'] ?? null)
                ? $input['autopayChoice']
                : (is_array($existing) ? (string) ($existing['autopayChoice'] ?? 'none') : 'none');
            if (!in_array($choice, ['none', 'minimum', 'balance'], true)) {
                $errors['autopayChoice'] = 'Choose none, minimum, or current balance.';
            } else {
                $autopay = $choice;
            }
            if (array_key_exists('bankAutopay', $input)) {
                $bank = !empty($input['bankAutopay']) ? 1 : 0;
            } elseif (array_key_exists('autopayChoice', $input) || $existing === null) {
                $bank = in_array($autopay, ['minimum', 'balance'], true) ? 1 : 0;
            } else {
                $bank = !empty($existing['bankAutopay']) ? 1 : 0;
            }
            $rawRecurrence = $input['billRecurrence'] ?? (is_array($existing) ? ($existing['billRecurrence'] ?? 'monthly') : 'monthly');
            if (!is_string($rawRecurrence) || !in_array($rawRecurrence, ['daily', 'monthly', 'yearly'], true)) {
                $errors['billRecurrence'] = 'Choose daily, monthly, or yearly.';
            } else {
                $billRecurrence = $rawRecurrence;
            }
            $parsedEvery = array_key_exists('billEvery', $input)
                ? $this->asInt($input['billEvery'])
                : (is_array($existing) ? (int) ($existing['billEvery'] ?? 1) : 1);
            if ($parsedEvery === null || $parsedEvery < 1 || $parsedEvery > 11) {
                $errors['billEvery'] = 'Choose from 1 to 11.';
            } else {
                $billEvery = $parsedEvery;
            }
            if ($bank === 1 && $autopay === 'none') {
                $autopay = 'balance';
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
            if ($bank === 1 && $billRecurrence !== 'daily' && !is_int($dueDay)) {
                $errors['dueDay'] = 'Enter the due day.';
            }
            if ($bank === 1 && $autopay === 'minimum' && !is_int($minimum)) {
                $errors['minimumDueMinor'] = 'Enter the minimum due.';
            }
            if ($type === 'loan') {
                $rawBillType = $input['liabilityBillType'] ?? (is_array($existing) ? ($existing['liabilityBillType'] ?? 'debt') : 'debt');
                if (!is_string($rawBillType) || !in_array($rawBillType, ['debt', 'vehicle'], true)) {
                    $errors['liabilityBillType'] = 'Choose Debt or Vehicle.';
                } else {
                    $liabilityBillType = $rawBillType;
                }
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
        $holder = $this->optionalText($input['holder'] ?? null, 120);
        if ($holder === false) {
            $errors['holder'] = 'Keep the holder to 120 characters.';
            $holder = null;
        }
        $notes = $this->optionalText($input['notes'] ?? null, 2000);
        if ($notes === false) {
            $errors['notes'] = 'Keep notes to 2000 characters.';
            $notes = null;
        }

        $gasFavorite = $this->flag($input, 'gasFavorite', $existing);
        $shoppingFavorite = $this->flag($input, 'shoppingFavorite', $existing);
        $assetFavorite = $this->flag($input, 'assetFavorite', $existing);
        $chosen = array_keys(array_filter([
            'gasFavorite' => $gasFavorite === 1,
            'shoppingFavorite' => $shoppingFavorite === 1,
            'assetFavorite' => $assetFavorite === 1,
        ]));
        if (count($chosen) > 1) {
            $errors[$chosen[1]] = 'An account can hold only one favorite.';
        }
        if ($gasFavorite === 1 && $type !== 'fuel') {
            $errors['gasFavorite'] = 'A gas favorite is a fuel account.';
        }
        if ($shoppingFavorite === 1 && $type !== 'credit') {
            $errors['shoppingFavorite'] = 'A shopping favorite is a credit account.';
        }
        if ($assetFavorite === 1 && $side !== 'asset') {
            $errors['assetFavorite'] = 'An asset favorite is an asset account.';
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        $inBudget = 0;
        if ($side === 'asset') {
            if (array_key_exists('inBudget', $input)) {
                $inBudget = !empty($input['inBudget']) ? 1 : 0;
            } elseif (
                is_array($existing)
                && ($existing['type'] ?? '') === $type
                && ($existing['side'] ?? '') === $side
            ) {
                $inBudget = !empty($existing['inBudget']) ? 1 : 0;
            } else {
                $inBudget = $type === 'savings' ? 0 : 1;
            }
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
            'bankAutopay' => $bank,
            'billRecurrence' => $billRecurrence,
            'billEvery' => $billEvery,
            'liabilityBillType' => $liabilityBillType,
            'institution' => is_string($institution) ? $institution : null,
            'holder' => is_string($holder) ? $holder : null,
            'notes' => is_string($notes) ? $notes : null,
            'gasFavorite' => $gasFavorite,
            'shoppingFavorite' => $shoppingFavorite,
            'assetFavorite' => $assetFavorite,
            'inBudget' => $inBudget,
        ];
    }

    /** @param array<string, mixed>|null $existing */
    private function flag(array $input, string $key, ?array $existing): int
    {
        if (array_key_exists($key, $input)) {
            return !empty($input[$key]) ? 1 : 0;
        }

        return is_array($existing) && !empty($existing[$key]) ? 1 : 0;
    }

    /** @param array{gasFavorite:int,shoppingFavorite:int,assetFavorite:int} $data */
    private function claim(array &$data, string $now, ?int $exceptId): void
    {
        if ($data['gasFavorite'] === 1) {
            $this->clearFlag('gas_favorite', $now, $exceptId);
            $data['shoppingFavorite'] = 0;
            $data['assetFavorite'] = 0;

            return;
        }
        if ($data['shoppingFavorite'] === 1) {
            $this->clearFlag('shopping_favorite', $now, $exceptId);
            $data['gasFavorite'] = 0;
            $data['assetFavorite'] = 0;

            return;
        }
        if ($data['assetFavorite'] === 1) {
            $this->clearFlag('asset_favorite', $now, $exceptId);
            $data['gasFavorite'] = 0;
            $data['shoppingFavorite'] = 0;
        }
    }

    private function clearFlag(string $column, string $now, ?int $exceptId): void
    {
        if (!in_array($column, ['gas_favorite', 'shopping_favorite', 'asset_favorite'], true)) {
            return;
        }
        if ($exceptId === null) {
            $this->pdo->prepare(
                "UPDATE accounts SET {$column} = 0, updated_at = ? WHERE user_id = ? AND {$column} = 1"
            )->execute([$now, $this->actor->id()]);

            return;
        }
        $this->pdo->prepare(
            "UPDATE accounts SET {$column} = 0, updated_at = ? WHERE user_id = ? AND {$column} = 1 AND id != ?"
        )->execute([$now, $this->actor->id(), $exceptId]);
    }

    private function aimLockedBills(): void
    {
        $star = $this->pdo->prepare(
            'SELECT id FROM accounts WHERE user_id = ? AND asset_favorite = 1 AND archived_at IS NULL'
        );
        $star->execute([$this->actor->id()]);
        $found = $star->fetchColumn();
        $now = gmdate('c');
        if ($found === false) {
            $this->pdo->prepare(
                'UPDATE bills SET account_id = source_account_id, updated_at = ?
                 WHERE source_account_id IS NOT NULL
                   AND source_account_id IN (SELECT id FROM accounts WHERE user_id = ?)'
            )->execute([$now, $this->actor->id()]);

            return;
        }
        $this->pdo->prepare(
            'UPDATE bills SET account_id = ?, updated_at = ?
             WHERE source_account_id IS NOT NULL
               AND source_account_id IN (SELECT id FROM accounts WHERE user_id = ?)'
        )->execute([(int) $found, $now, $this->actor->id()]);
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

    private function drawingBillCount(int $accountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM bills WHERE account_id = ?');
        $stmt->execute([$accountId]);

        return (int) $stmt->fetchColumn();
    }

    private function stayCount(int $accountId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM trips WHERE account_id = ? AND user_id = ? AND total_minor > 0'
        );
        $stmt->execute([$accountId, $this->actor->id()]);

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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
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
            'bankAutopay' => (int) ($row['bank_autopay'] ?? 0) === 1,
            'billRecurrence' => (string) ($row['bill_recurrence'] ?? 'monthly'),
            'billEvery' => max(1, (int) ($row['bill_every'] ?? 1)),
            'liabilityBillType' => in_array((string) ($row['liability_bill_type'] ?? 'debt'), ['debt', 'vehicle'], true)
                ? (string) $row['liability_bill_type']
                : 'debt',
            'institution' => $row['institution'],
            'holder' => $row['holder'],
            'notes' => $row['notes'],
            'gasFavorite' => (int) ($row['gas_favorite'] ?? 0) === 1,
            'shoppingFavorite' => (int) ($row['shopping_favorite'] ?? 0) === 1,
            'assetFavorite' => (int) ($row['asset_favorite'] ?? 0) === 1,
            'inBudget' => (int) ($row['in_budget'] ?? 0) === 1,
            'archivedAt' => $row['archived_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
