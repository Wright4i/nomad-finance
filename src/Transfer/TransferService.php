<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Account\LiabilityBill;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Support\Dates;
use PDO;

final class TransferService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly BillService $bills,
        private readonly EnvelopeService $envelopes,
    ) {
    }

    /** @return array<string, mixed> */
    public function cardPreview(int $cardId, string $today): array
    {
        $today = $this->day($today);
        $card = $this->paidInFull($cardId);
        $charges = $this->charges((int) $card['id'], $today);
        $available = $this->available($today);

        return [
            'card' => $this->accountView($card),
            'paymentMinor' => (int) $card['balance_minor'],
            'charges' => $charges,
            'unassignedMinor' => max(0, $available),
            'availableMinor' => $available,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function payCard(array $input): array
    {
        $today = $this->day(is_string($input['today'] ?? null) ? $input['today'] : '');
        $card = $this->paidInFull($this->int($input['cardId'] ?? null));
        $source = $this->asset($this->int($input['sourceId'] ?? null));
        if ((int) $source['id'] === (int) $card['id']) {
            throw new ApiException(422, 'Choose a different account to pay from.');
        }
        $payment = array_key_exists('amountMinor', $input)
            ? $this->int($input['amountMinor'])
            : (int) $card['balance_minor'];
        if ($payment < 1) {
            throw new ApiException(422, 'Enter an amount greater than zero.', ['amountMinor' => 'Enter an amount greater than zero.']);
        }
        if ($payment > (int) $card['balance_minor'] || $payment > (int) $source['balance_minor']) {
            throw new ApiException(422, 'The payment cannot be more than either balance.');
        }

        $charges = $this->charges((int) $card['id'], $today);
        $byId = [];
        foreach ($charges as $charge) {
            $byId[$charge['id']] = $charge;
        }
        $selected = [];
        $used = 0;
        foreach ($this->idList($input['billIds'] ?? null) as $id) {
            if (!isset($byId[$id])) {
                throw new ApiException(422, 'Choose bills drawn from this card.');
            }
            $selected[] = $byId[$id];
            $used += (int) $byId[$id]['amountMinor'];
        }
        if ($used > $payment) {
            throw new ApiException(422, 'The selected bills are more than this payment.');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($selected as $charge) {
                $this->bills->markPaid((int) $charge['id'], ['paidOn' => $charge['dueOn']]);
                $this->lowerBucket((int) $charge['categoryId'], (int) $charge['amountMinor'], $today);
            }
            $this->decrease((int) $source['id'], $payment);
            $this->decrease((int) $card['id'], $payment);
            $this->syncLiability((int) $card['id'], $today);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'paymentMinor' => $payment,
            'selectedMinor' => $used,
            'beyondMinor' => $payment - $used,
            'source' => $this->accountView($this->account((int) $source['id'])),
            'card' => $this->accountView($this->account((int) $card['id'])),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function previewMove(array $input): array
    {
        $today = $this->day(is_string($input['today'] ?? null) ? $input['today'] : '');
        $source = $this->asset($this->int($input['sourceId'] ?? null));
        $destination = $this->asset($this->int($input['destinationId'] ?? null));
        $amount = $this->int($input['amountMinor'] ?? null);
        $this->guardMove($source, $destination, $amount);
        $plan = $this->coverPlan($source, $destination, $amount, $today);

        return [
            'source' => $this->accountView($source),
            'destination' => $this->accountView($destination),
            'amountMinor' => $amount,
            'leavesPool' => $plan['leavesPool'],
            'unassignedMinor' => $plan['unassignedMinor'],
            'fromUnassignedMinor' => $plan['fromUnassignedMinor'],
            'buckets' => $plan['buckets'],
            'gapMinor' => $plan['gapMinor'],
            'ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function move(array $input): array
    {
        $today = $this->day(is_string($input['today'] ?? null) ? $input['today'] : '');
        $source = $this->asset($this->int($input['sourceId'] ?? null));
        $destination = $this->asset($this->int($input['destinationId'] ?? null));
        $amount = $this->int($input['amountMinor'] ?? null);
        $this->guardMove($source, $destination, $amount);
        $plan = $this->coverPlan($source, $destination, $amount, $today);

        $this->pdo->beginTransaction();
        try {
            foreach ($plan['buckets'] as $bucket) {
                $this->lowerBucket((int) $bucket['categoryId'], (int) $bucket['amountMinor'], $today);
            }
            $this->decrease((int) $source['id'], $amount);
            $this->increase((int) $destination['id'], $amount);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'amountMinor' => $amount,
            'source' => $this->accountView($this->account((int) $source['id'])),
            'destination' => $this->accountView($this->account((int) $destination['id'])),
            'buckets' => $plan['buckets'],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $destination
     */
    private function guardMove(array $source, array $destination, int $amount): void
    {
        if ((int) $source['id'] === (int) $destination['id']) {
            throw new ApiException(422, 'Choose two different accounts.');
        }
        if ($amount < 1) {
            throw new ApiException(422, 'Enter an amount greater than zero.', ['amountMinor' => 'Enter an amount greater than zero.']);
        }
        if ($amount > (int) $source['balance_minor']) {
            $short = $amount - (int) $source['balance_minor'];
            throw new ApiException(422, 'Short ' . $this->dollars($short) . '. You are asking for more than this account holds.');
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $destination
     * @return array{leavesPool:bool,unassignedMinor:int,fromUnassignedMinor:int,buckets:list<array{categoryId:int,name:string,amountMinor:int}>,gapMinor:int}
     */
    private function coverPlan(array $source, array $destination, int $amount, string $today): array
    {
        $leaves = (int) $source['in_budget'] === 1 && (int) $destination['in_budget'] !== 1;
        $unassigned = max(0, $this->available($today));
        if (!$leaves) {
            return [
                'leavesPool' => false,
                'unassignedMinor' => $unassigned,
                'fromUnassignedMinor' => 0,
                'buckets' => [],
                'gapMinor' => 0,
            ];
        }
        $fromUnassigned = min($unassigned, $amount);
        $short = $amount - $fromUnassigned;
        $buckets = [];
        if ($short > 0) {
            foreach ($this->pullable($today) as $row) {
                if ($short < 1) {
                    break;
                }
                $take = min($short, (int) $row['room']);
                if ($take < 1) {
                    continue;
                }
                $buckets[] = [
                    'categoryId' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'amountMinor' => $take,
                ];
                $short -= $take;
            }
        }

        return [
            'leavesPool' => true,
            'unassignedMinor' => $unassigned,
            'fromUnassignedMinor' => $fromUnassigned,
            'buckets' => $buckets,
            'gapMinor' => $short,
        ];
    }

    /** @return list<array{id:int,name:string,room:int}> */
    private function pullable(string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.role, SUM(a.amount_minor) AS amount_minor
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             JOIN categories c ON c.id = a.category_id
             WHERE p.user_id = ? AND a.amount_minor > 0
               AND p.period_start <= ? AND (p.period_end IS NULL OR p.period_end >= ?)
             GROUP BY c.id
             ORDER BY CASE c.role WHEN \'locked\' THEN 1 ELSE 0 END, c.name COLLATE NOCASE, c.id'
        );
        $stmt->execute([$this->actor->id(), $today, $today]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $amount = (int) $row['amount_minor'];
            $floor = (string) $row['role'] === 'locked' ? $this->billFloor((int) $row['id'], $today) : 0;
            $room = max(0, $amount - $floor);
            if ($room < 1) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'room' => $room,
            ];
        }

        return $rows;
    }

    private function billFloor(int $categoryId, string $today): int
    {
        $sheet = $this->envelopes->show($today);
        foreach ($sheet['categories'] as $category) {
            if (!is_array($category) || (int) ($category['id'] ?? 0) !== $categoryId) {
                continue;
            }

            return (int) ($category['billMinor'] ?? 0);
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function paidInFull(int $id): array
    {
        $account = $this->account($id);
        $type = (string) $account['type'];
        if (!in_array($type, ['credit', 'fuel'], true) || (string) $account['autopay_choice'] !== 'balance' || (int) $account['bank_autopay'] !== 1) {
            throw new ApiException(422, 'Choose a paid-in-full card.');
        }
        if ($account['archived_at'] !== null) {
            throw new ApiException(409, 'That account is archived.');
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function asset(int $id): array
    {
        $account = $this->account($id);
        if ((string) $account['side'] !== 'asset' || $account['archived_at'] !== null) {
            throw new ApiException(422, 'Choose an open asset account.');
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function account(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Account not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $account
     * @return array{id:int,name:string,type:string,balanceMinor:int,inBudget:bool}
     */
    private function accountView(array $account): array
    {
        return [
            'id' => (int) $account['id'],
            'name' => (string) $account['name'],
            'type' => (string) $account['type'],
            'balanceMinor' => (int) $account['balance_minor'],
            'inBudget' => (int) $account['in_budget'] === 1,
        ];
    }

    /** @return list<array{id:int,name:string,categoryId:int,categoryName:string,group:string,amountMinor:int,dueOn:string}> */
    private function charges(int $cardId, string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.name, b.amount_minor, b.next_due_date, b.category_id,
                    c.name AS category_name, c.category_group
             FROM bills b
             LEFT JOIN categories c ON c.id = b.category_id
             WHERE b.account_id = ? AND b.status = \'active\'
               AND b.next_due_date <= ?
               AND (b.source_account_id IS NULL OR b.source_account_id != b.account_id)
             ORDER BY b.next_due_date, b.name COLLATE NOCASE, b.id'
        );
        $stmt->execute([$cardId, $today]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'categoryId' => (int) $row['category_id'],
                'categoryName' => $row['category_name'] === null ? '' : (string) $row['category_name'],
                'group' => $row['category_group'] === null ? '' : (string) $row['category_group'],
                'amountMinor' => (int) $row['amount_minor'],
                'dueOn' => (string) $row['next_due_date'],
            ];
        }

        return $rows;
    }

    private function available(string $today): int
    {
        $sheet = $this->envelopes->show($today);

        return (int) ($sheet['availableMinor'] ?? 0);
    }

    private function lowerBucket(int $categoryId, int $amount, string $today): void
    {
        if ($categoryId < 1 || $amount < 1) {
            return;
        }
        $left = $amount;
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.amount_minor, a.filled_minor
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.user_id = ? AND a.category_id = ? AND a.amount_minor > 0
               AND p.period_start <= ? AND (p.period_end IS NULL OR p.period_end >= ?)
             ORDER BY a.amount_minor DESC, a.id'
        );
        $stmt->execute([$this->actor->id(), $categoryId, $today, $today]);
        $update = $this->pdo->prepare('UPDATE assignments SET amount_minor = ?, filled_minor = ? WHERE id = ?');
        while ($left > 0 && ($row = $stmt->fetch())) {
            if (!is_array($row)) {
                continue;
            }
            $have = (int) $row['amount_minor'];
            $take = min($left, $have);
            $filled = max(0, (int) $row['filled_minor'] - $take);
            $update->execute([$have - $take, $filled, (int) $row['id']]);
            $left -= $take;
        }
        $this->pdo->prepare(
            'UPDATE categories SET amount_minor = CASE WHEN amount_minor > ? THEN amount_minor - ? ELSE 0 END, updated_at = ?
             WHERE id = ? AND user_id = ?'
        )->execute([$amount, $amount, gmdate('c'), $categoryId, $this->actor->id()]);
    }

    private function decrease(int $accountId, int $amount): void
    {
        $this->pdo->prepare(
            'UPDATE accounts SET balance_minor = CASE WHEN balance_minor > ? THEN balance_minor - ? ELSE 0 END, updated_at = ?
             WHERE id = ? AND user_id = ?'
        )->execute([$amount, $amount, gmdate('c'), $accountId, $this->actor->id()]);
    }

    private function increase(int $accountId, int $amount): void
    {
        $this->pdo->prepare(
            'UPDATE accounts SET balance_minor = balance_minor + ?, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$amount, gmdate('c'), $accountId, $this->actor->id()]);
    }

    private function syncLiability(int $accountId, string $today): void
    {
        $account = $this->account($accountId);
        (new LiabilityBill($this->pdo, $this->actor))->sync($account, $today);
    }

    /** @return list<int> */
    private function idList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = $this->int($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private function dollars(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $whole = intdiv($abs, 100);
        $cents = $abs % 100;

        return $sign . '$' . number_format($whole, 0, '.', ',') . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    private function day(string $value): string
    {
        try {
            return Dates::parse($value)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            throw new ApiException(422, 'Check the highlighted fields.', ['today' => 'Use a YYYY-MM-DD date.']);
        }
    }
}
