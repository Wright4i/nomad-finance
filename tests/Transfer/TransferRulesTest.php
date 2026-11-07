<?php

declare(strict_types=1);

namespace App\Tests\Transfer;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Target\TargetService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Transfer\TransferService;
use App\Trip\Drive;
use PDO;
use PHPUnit\Framework\TestCase;

final class TransferRulesTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private BillService $bills;

    private EnvelopeService $envelopes;

    private TransferService $transfers;

    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $auth->enterDemo();
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->bills = new BillService($this->pdo, $catalog, $actor);
        $this->envelopes = new EnvelopeService(
            $this->pdo,
            $actor,
            $catalog,
            new TargetService($this->pdo, $actor),
            new PayCycleService($this->pdo, $actor),
            new Drive(false),
        );
        $this->transfers = new TransferService($this->pdo, $actor, $this->bills, $this->envelopes);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCardPayMarksTheBillMovesBalancesAndRefusesAShortfall(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE accounts SET balance_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('UPDATE pay_cycles SET rate_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'DELETE FROM payroll_entries WHERE pay_cycle_id IN (SELECT id FROM pay_cycles WHERE user_id = ?)'
        )->execute([$userId]);

        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 20000,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 5000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $bill = $this->bills->create([
            'name' => 'Card dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 2000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-01',
            'today' => '2026-11-06',
        ]);
        $this->plant((int) $bill['categoryId'], '2026-11-06', 4000);
        $before = $this->assignment((int) $bill['categoryId']);

        try {
            $this->transfers->payCard([
                'today' => '2026-11-06',
                'cardId' => $card['id'],
                'sourceId' => $source['id'],
                'amountMinor' => 5000,
                'billIds' => [$bill['id']],
            ]);
            self::fail('A short unassigned remainder was paid.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('3000', $e->fields['gapMinor']);
        }
        self::assertSame('active', $this->bills->get((int) $bill['id'])['status']);
        self::assertSame(20000, $this->balance((int) $source['id']));
        self::assertSame(5000, $this->balance((int) $card['id']));

        $paid = $this->transfers->payCard([
            'today' => '2026-11-06',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 2000,
            'billIds' => [$bill['id']],
        ]);
        self::assertSame('paid', $this->bills->get((int) $bill['id'])['status']);
        self::assertSame($before - 2000, $this->assignment((int) $bill['categoryId']));
        self::assertSame(18000, $paid['source']['balanceMinor']);
        self::assertSame(3000, $paid['card']['balanceMinor']);
    }

    public function testAnInBudgetTransferMovesBalancesAndLeavesAssignments(): void
    {
        $left = $this->accounts->create([
            'name' => 'Left jar',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 8000,
            'inBudget' => true,
        ]);
        $right = $this->accounts->create([
            'name' => 'Right jar',
            'type' => 'savings',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'inBudget' => true,
        ]);
        $before = $this->assignmentTotal();
        $available = $this->envelopes->show('2026-11-06')['availableMinor'];

        $moved = $this->transfers->move([
            'today' => '2026-11-06',
            'sourceId' => $left['id'],
            'destinationId' => $right['id'],
            'amountMinor' => 2500,
        ]);

        self::assertSame(5500, $moved['source']['balanceMinor']);
        self::assertSame(3500, $moved['destination']['balanceMinor']);
        self::assertSame($before, $this->assignmentTotal());
        self::assertSame($available, $this->envelopes->show('2026-11-06')['availableMinor']);
        self::assertSame([], $moved['buckets']);
    }

    private function plant(int $categoryId, string $today, int $amount): void
    {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pay_periods
             WHERE user_id = ? AND period_start <= ? AND (period_end IS NULL OR period_end >= ?)
             ORDER BY period_start DESC LIMIT 1'
        );
        $stmt->execute([$userId, $today, $today]);
        $periodId = (int) $stmt->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor)
             VALUES (?, ?, ?, 0)
             ON CONFLICT(pay_period_id, category_id) DO UPDATE SET amount_minor = excluded.amount_minor'
        )->execute([$periodId, $categoryId, $amount]);
    }

    private function balance(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT balance_minor FROM accounts WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function assignment(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount_minor), 0) FROM assignments WHERE category_id = ?');
        $stmt->execute([$categoryId]);

        return (int) $stmt->fetchColumn();
    }

    private function assignmentTotal(): int
    {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.amount_minor), 0)
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.user_id = ?'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }
}
