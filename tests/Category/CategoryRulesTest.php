<?php

declare(strict_types=1);

namespace App\Tests\Category;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Bill\BillService;
use App\Category\CategoryService;
use App\Database\Database;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Http\ApiException;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class CategoryRulesTest extends TestCase
{
    private PDO $pdo;

    private CategoryService $categories;

    private string $path;

    private int $demoId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $this->demoId = DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $this->categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testNameIsTrimmedAndTheGroupIsOneOfThree(): void
    {
        $category = $this->categories->create([
            'name' => '  Shelter  ',
            'group' => 'Needs',
            'amountMinor' => 900,
        ]);

        self::assertSame('Shelter', $category['name']);
        self::assertSame('needs', $category['group']);
        self::assertSame(0, $category['amountMinor']);
        self::assertNull($category['archivedAt']);

        $long = $this->categories->create([
            'name' => str_repeat('a', 80),
            'group' => 'savings',
        ]);
        self::assertSame(80, mb_strlen((string) $long['name']));

        try {
            $this->categories->create(['name' => '   ', 'group' => 'needs']);
            self::fail('A blank name is refused.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('name', $e->fields);
        }

        try {
            $this->categories->create(['name' => str_repeat('b', 81), 'group' => 'wants']);
            self::fail('A name past 80 characters is refused.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('name', $e->fields);
        }

        try {
            $this->categories->create(['name' => 'Fun', 'group' => 'lifestyle']);
            self::fail('The group has to be Needs, Wants, or Savings.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('group', $e->fields);
        }
    }

    public function testListOrdersGroupsAndHidesArchivedNames(): void
    {
        $alpha = $this->categories->create(['name' => 'Alpha', 'group' => 'needs']);
        $this->categories->create(['name' => 'Zulu', 'group' => 'needs']);
        $this->categories->create(['name' => 'Middle', 'group' => 'wants']);
        $save = $this->categories->create(['name' => 'Save', 'group' => 'savings']);

        self::assertSame(
            ['Alpha', 'Zulu', 'Middle', 'Save'],
            $this->goalNames(false),
        );

        $moved = $this->categories->update($save['id'], [
            'name' => '  Nest Egg  ',
            'group' => 'Needs',
            'amountMinor' => 50,
        ]);
        self::assertSame('Nest Egg', $moved['name']);
        self::assertSame('needs', $moved['group']);
        self::assertSame(0, $moved['amountMinor']);

        $archived = $this->categories->archive($alpha['id']);
        self::assertNotNull($archived['archivedAt']);
        self::assertNotContains('Alpha', array_column($this->categories->list(false), 'name'));
        self::assertSame(
            ['Alpha', 'Nest Egg', 'Zulu', 'Middle'],
            $this->goalNames(true),
        );

        $restored = $this->categories->unarchive($alpha['id']);
        self::assertNull($restored['archivedAt']);
        self::assertContains('Alpha', array_column($this->categories->list(false), 'name'));
    }

    public function testArchiveIsRefusedWhileTheAmountIsNotZero(): void
    {
        $held = $this->categories->create(['name' => 'Shelter', 'group' => 'needs']);
        $this->pdo->prepare('UPDATE categories SET amount_minor = 40 WHERE id = ?')->execute([$held['id']]);

        try {
            $this->categories->archive($held['id']);
            self::fail('A category that holds an amount cannot be archived.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertNull($this->categories->get($held['id'])['archivedAt']);

        $this->pdo->prepare('UPDATE categories SET amount_minor = 0 WHERE id = ?')->execute([$held['id']]);
        self::assertNotNull($this->categories->archive($held['id'])['archivedAt']);

        $owed = $this->categories->create(['name' => 'Dining', 'group' => 'wants']);
        $this->pdo->prepare('UPDATE categories SET amount_minor = -5 WHERE id = ?')->execute([$owed['id']]);
        try {
            $this->categories->archive($owed['id']);
            self::fail('A negative amount is still an amount.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertNull($this->categories->get($owed['id'])['archivedAt']);
    }

    public function testAnotherPersonCannotSeeOrChangeTheseCategories(): void
    {
        $rent = $this->categories->create(['name' => 'Shelter', 'group' => 'needs']);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'EUR\', \'light\', \'owner\', ?)'
        )->execute(['Ada', password_hash('correct horse battery', PASSWORD_DEFAULT), $now]);
        $ownerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)')->execute([$ownerId]);

        $_SESSION['user_id'] = $ownerId;
        self::assertNotContains('Shelter', array_column($this->categories->list(true), 'name'));
        self::assertContains('Rent', array_column($this->categories->list(true), 'name'));
        try {
            $this->categories->get($rent['id']);
            self::fail('Another person cannot open this category.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        try {
            $this->categories->archive($rent['id']);
            self::fail('Another person cannot archive this category.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        try {
            $this->categories->unarchive($rent['id']);
            self::fail('Another person cannot unarchive this category.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }

        $_SESSION['user_id'] = $this->demoId;
        self::assertSame('Shelter', $this->categories->get($rent['id'])['name']);
        self::assertNull($this->categories->get($rent['id'])['archivedAt']);
    }

    public function testDeleteRemovesAnArchivedGoalAndLeavesTheRest(): void
    {
        $goal = $this->categories->create([
            'name' => 'Shelter',
            'group' => 'needs',
            'target' => ['kind' => 'balance', 'amountMinor' => 5000],
        ]);
        $id = (int) $goal['id'];
        $this->categories->archive($id);
        $actor = new Actor($this->pdo);
        $catalog = new CurrencyCatalog();
        $accountId = (int) (new AccountService($this->pdo, $catalog, $actor))->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100000,
        ])['id'];
        (new PayCycleService($this->pdo, $actor))->create([
            'payor' => 'Monthly Payor',
            'payday' => '2026-11-01',
            'length' => 'monthly',
            'payKind' => 'fixed',
            'rateMinor' => 10000,
        ], '2026-11-07');
        $bill = (new BillService($this->pdo, $catalog, $actor))->create([
            'name' => 'Site fee',
            'type' => 'lodging',
            'accountId' => $accountId,
            'amountMinor' => 2500,
            'currency' => 'USD',
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-20',
            'today' => '2026-11-07',
        ]);
        $billId = (int) $bill['id'];
        $this->pdo->prepare('UPDATE bills SET category_id = ? WHERE id = ?')->execute([$id, $billId]);
        $periodId = (int) $this->pdo->query('SELECT id FROM pay_periods LIMIT 1')->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor) VALUES (?, ?, 100, 0)'
        )->execute([$periodId, $id]);
        $this->pdo->prepare(
            'INSERT INTO acct_period_assignments (user_id, acct_period_start, acct_period_length, category_id, amount_minor)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->demoId, '2026-11-01', '1 month', $id, 100]);

        try {
            $this->categories->delete($id, false);
            self::fail('Delete waits for a confirmation.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('Confirm before deleting this bucket.', $e->getMessage());
        }
        self::assertNotNull($this->categories->get($id)['archivedAt']);

        $live = $this->categories->create(['name' => 'Still here', 'group' => 'wants']);
        try {
            $this->categories->delete((int) $live['id'], true);
            self::fail('A goal still on the list cannot be deleted.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('Only an archived bucket can be deleted.', $e->getMessage());
        }

        $locked = (int) $this->pdo->query("SELECT id FROM categories WHERE role = 'locked' AND bill_type = 'rent'")->fetchColumn();
        try {
            $this->categories->delete($locked, true);
            self::fail('A locked bucket stays on the books.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('This bucket stays on the books.', $e->getMessage());
        }

        $this->categories->delete($id, true);
        try {
            $this->categories->get($id);
            self::fail('The archived bucket is gone.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        $home = (int) $this->pdo->query('SELECT category_id FROM bills WHERE id = ' . $billId)->fetchColumn();
        $lodging = (int) $this->pdo->query("SELECT id FROM categories WHERE role = 'locked' AND bill_type = 'lodging'")->fetchColumn();
        self::assertSame($lodging, $home);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM targets WHERE category_id = ' . $id)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM assignments WHERE category_id = ' . $id)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM acct_period_assignments WHERE category_id = ' . $id)->fetchColumn());
        self::assertSame('Still here', $this->categories->get((int) $live['id'])['name']);
        self::assertNotNull($this->categories->get($locked)['name']);
    }

    /** @return list<string> */
    private function goalNames(bool $archived): array
    {
        $names = [];
        foreach ($this->categories->list($archived) as $category) {
            if (($category['role'] ?? '') === 'goal') {
                $names[] = (string) $category['name'];
            }
        }

        return $names;
    }
}
