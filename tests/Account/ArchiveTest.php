<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private BillService $bills;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        DemoSession::start($this->pdo);
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->bills = new BillService($this->pdo, $catalog, $actor);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAPausedBillStillBlocksArchive(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 5000,
        ]);
        $bill = $this->bills->create([
            'name' => 'Rent',
            'type' => 'rent',
            'accountId' => $account['id'],
            'amountMinor' => 120000,
            'recurrence' => 'monthly',
            'nextDueDate' => '2026-01-31',
        ]);

        try {
            $this->accounts->archive($account['id']);
            self::fail('Archive should be refused.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }

        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-01-31']);
        self::assertSame('2026-02-28', $paid['nextDueDate']);
        $paid = $this->bills->markPaid($paid['id'], ['paidOn' => '2026-02-28']);
        self::assertSame('2026-03-31', $paid['nextDueDate']);

        $this->bills->pause($bill['id']);
        try {
            $this->accounts->archive($account['id']);
            self::fail('A paused bill should still block archive.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testOneOffPaidBillDoesNotBlockArchive(): void
    {
        $account = $this->accounts->create([
            'name' => 'Cash',
            'type' => 'cash',
            'currency' => 'EUR',
            'balanceMinor' => 100,
        ]);
        $bill = $this->bills->create([
            'name' => 'Visa fee',
            'type' => 'other',
            'otherLabel' => 'Visa',
            'accountId' => $account['id'],
            'amountMinor' => 8000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-04-01',
        ]);
        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-04-01']);
        self::assertSame('paid', $paid['status']);
        self::assertNotNull($this->accounts->archive($account['id'])['archivedAt']);
    }

    public function testUnarchivePutsTheAccountBackOnTheList(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 5000,
        ]);
        $this->accounts->archive($account['id']);
        self::assertNotContains('Checking', array_column($this->accounts->list(false), 'name'));

        $restored = $this->accounts->unarchive($account['id']);
        self::assertNull($restored['archivedAt']);
        self::assertSame(5000, $restored['balanceMinor']);
        self::assertContains('Checking', array_column($this->accounts->list(false), 'name'));
    }

    public function testDeleteRemovesAnArchivedAccountAndLeavesTheRest(): void
    {
        $keep = $this->accounts->create([
            'name' => 'Still here',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 8000,
        ]);
        $account = $this->accounts->create([
            'name' => 'Old wallet',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 1200,
        ]);
        $id = (int) $account['id'];
        $bill = $this->bills->create([
            'name' => 'Camp fee',
            'type' => 'other',
            'otherLabel' => 'Camp fee',
            'accountId' => $id,
            'amountMinor' => 500,
            'recurrence' => 'once',
            'nextDueDate' => '2026-04-01',
        ]);
        $paid = $this->bills->markPaid($bill['id'], ['paidOn' => '2026-04-01']);
        self::assertSame('paid', $paid['status']);
        self::assertNotNull($this->accounts->archive($id)['archivedAt']);

        try {
            $this->accounts->delete($id, false);
            self::fail('Delete waits for a confirmation.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('Confirm before deleting this account.', $e->getMessage());
        }
        self::assertSame('Old wallet', $this->accounts->get($id)['name']);

        try {
            $this->accounts->delete((int) $keep['id'], true);
            self::fail('A live account cannot be deleted.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('Only an archived account can be deleted.', $e->getMessage());
        }
        self::assertSame('Still here', $this->accounts->get((int) $keep['id'])['name']);

        try {
            $this->accounts->delete($id, true);
            self::fail('A bill that still draws from the account blocks delete.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('Stop the bills that draw from this account before deleting it.', $e->getMessage());
        }
        self::assertSame('paid', $this->bills->get((int) $bill['id'])['status']);
        self::assertSame('Old wallet', $this->accounts->get($id)['name']);

        $demoId = (int) $this->pdo->query("SELECT id FROM user WHERE role = 'demo'")->fetchColumn();
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'USD\', \'light\', \'owner\', ?)'
        )->execute(['Ada', password_hash('correct horse battery', PASSWORD_DEFAULT), $now]);
        $ownerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)')->execute([$ownerId]);
        $_SESSION['user_id'] = $ownerId;
        try {
            $this->accounts->delete($id, true);
            self::fail('Another person cannot delete this account.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        $_SESSION['user_id'] = $demoId;
        self::assertSame('Old wallet', $this->accounts->get($id)['name']);

        $this->pdo->prepare('DELETE FROM bills WHERE id = ?')->execute([(int) $bill['id']]);
        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, arrive_on, depart_on, latitude, longitude,
                account_id, total_minor, amount_due_minor, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$demoId, 'Overflow lot', '2026-11-01', '2026-11-02', '0', '0', $id, 1500, 0, $now, $now]);
        try {
            $this->accounts->delete($id, true);
            self::fail('A stay with an amount blocks delete.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('Move the stays that draw from this account before deleting it.', $e->getMessage());
        }
        self::assertSame('Old wallet', $this->accounts->get($id)['name']);
        $this->pdo->prepare('UPDATE trips SET total_minor = 0 WHERE place_name = ?')->execute(['Overflow lot']);

        $this->accounts->delete($id, true);
        try {
            $this->accounts->get($id);
            self::fail('The archived account is gone.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        $stay = $this->pdo->query("SELECT account_id FROM trips WHERE place_name = 'Overflow lot'")->fetch();
        self::assertIsArray($stay);
        self::assertNull($stay['account_id']);
        self::assertSame('Still here', $this->accounts->get((int) $keep['id'])['name']);
        self::assertNotContains('Old wallet', array_column($this->accounts->list(true), 'name'));

        $home = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 10000,
            'assetFavorite' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Old card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4000,
            'minimumDueMinor' => 2500,
            'dueDay' => 15,
            'autopayChoice' => 'minimum',
            'bankAutopay' => true,
        ]);
        $cardId = (int) $card['id'];
        $kept = $this->pdo->prepare('SELECT id, account_id, source_account_id FROM bills WHERE source_account_id = ?');
        $kept->execute([$cardId]);
        $schedule = $kept->fetch();
        self::assertIsArray($schedule);
        self::assertSame((int) $home['id'], (int) $schedule['account_id']);
        self::assertNotNull($this->accounts->archive($cardId)['archivedAt']);
        $this->accounts->delete($cardId, true);
        try {
            $this->accounts->get($cardId);
            self::fail('The archived card is gone.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        $left = $this->pdo->prepare('SELECT account_id, source_account_id, status FROM bills WHERE id = ?');
        $left->execute([(int) $schedule['id']]);
        $row = $left->fetch();
        self::assertIsArray($row);
        self::assertSame((int) $home['id'], (int) $row['account_id']);
        self::assertNull($row['source_account_id']);
        self::assertSame('Pay from', $this->accounts->get((int) $home['id'])['name']);
    }
}
