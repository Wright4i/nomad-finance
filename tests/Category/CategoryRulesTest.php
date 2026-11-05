<?php

declare(strict_types=1);

namespace App\Tests\Category;

use App\Auth\Actor;
use App\Category\CategoryService;
use App\Database\Database;
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
            'name' => '  Rent  ',
            'group' => 'Needs',
            'amountMinor' => 900,
        ]);

        self::assertSame('Rent', $category['name']);
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
            ['Needs Default', 'Fuel Bucket', 'Alpha', 'Zulu', 'Wants Default', 'Middle', 'Savings Default', 'Save'],
            array_column($this->categories->list(false), 'name'),
        );

        $moved = $this->categories->update($save['id'], [
            'name' => '  Emergency  ',
            'group' => 'Needs',
            'amountMinor' => 50,
        ]);
        self::assertSame('Emergency', $moved['name']);
        self::assertSame('needs', $moved['group']);
        self::assertSame(0, $moved['amountMinor']);

        $archived = $this->categories->archive($alpha['id']);
        self::assertNotNull($archived['archivedAt']);
        self::assertNotContains('Alpha', array_column($this->categories->list(false), 'name'));
        self::assertSame(
            ['Needs Default', 'Fuel Bucket', 'Alpha', 'Emergency', 'Zulu', 'Wants Default', 'Middle', 'Savings Default'],
            array_column($this->categories->list(true), 'name'),
        );

        $restored = $this->categories->unarchive($alpha['id']);
        self::assertNull($restored['archivedAt']);
        self::assertContains('Alpha', array_column($this->categories->list(false), 'name'));
    }

    public function testArchiveIsRefusedWhileTheAmountIsNotZero(): void
    {
        $held = $this->categories->create(['name' => 'Rent', 'group' => 'needs']);
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
        $rent = $this->categories->create(['name' => 'Rent', 'group' => 'needs']);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'EUR\', \'light\', \'owner\', ?)'
        )->execute(['Ada', password_hash('correct horse battery', PASSWORD_DEFAULT), $now]);
        $ownerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)')->execute([$ownerId]);

        $_SESSION['user_id'] = $ownerId;
        self::assertSame(
            ['Needs Default', 'Fuel Bucket', 'Wants Default', 'Savings Default'],
            array_column($this->categories->list(true), 'name'),
        );
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
        self::assertSame('Rent', $this->categories->get($rent['id'])['name']);
        self::assertNull($this->categories->get($rent['id'])['archivedAt']);
    }
}
