<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Database\Database;
use App\Money\CurrencyCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testExistingOwnerBooksSurviveTheDemoMigration(): void
    {
        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sql = file_get_contents(dirname(__DIR__, 2) . '/db/migrations/001_init.sql');
        self::assertIsString($sql);
        $parts = preg_split('/;\s*(?:\R|$)/', trim($sql));
        self::assertIsArray($parts);
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO user (id, name, password_hash, base_currency, theme, created_at) VALUES (1, ?, ?, \'USD\', \'dark\', ?)'
        )->execute(['Ada', password_hash('correct horse battery', PASSWORD_DEFAULT), $now]);
        $pdo->prepare(
            'INSERT INTO accounts (name, type, side, currency, balance_minor, created_at, updated_at)
             VALUES (\'Harbor\', \'checking\', \'asset\', \'USD\', 12345, ?, ?)'
        )->execute([$now, $now]);
        $pdo->exec(
            'CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );
        $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (\'001_init.sql\', ?)')->execute([$now]);
        unset($pdo);

        $pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $_SESSION['user_id'] = 1;
        $accounts = new AccountService($pdo, new CurrencyCatalog(), new Actor($pdo));
        $owner = $accounts->list(false);
        self::assertCount(1, $owner);
        self::assertSame('Harbor', $owner[0]['name']);
        self::assertSame(12345, $owner[0]['balanceMinor']);

        $demoId = $pdo->query("SELECT id FROM user WHERE role = 'demo'")->fetchColumn();
        self::assertNotFalse($demoId);
        $_SESSION['user_id'] = (int) $demoId;
        self::assertCount(9, $accounts->list(false));
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM accounts a JOIN user u ON u.id = a.user_id WHERE u.role = 'owner' AND a.name != 'Harbor'"
        )->fetchColumn());
    }
}
