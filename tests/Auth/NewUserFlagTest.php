<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class NewUserFlagTest extends TestCase
{
    private PDO $pdo;

    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testClosedFlagRefusesANewUserAndAnOpenFlagAcceptsOne(): void
    {
        $closed = $this->auth(false);
        $owner = $closed->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        self::assertSame('owner', $owner['role']);
        self::assertFalse($closed->allowsNewUser());

        $ownerRow = $this->userWhere("role = 'owner'");
        $demoRow = $this->userWhere("role = 'demo'");
        self::assertSame(2, $this->tally('SELECT COUNT(*) FROM user'));

        try {
            $closed->createUser([
                'name' => 'Bea',
                'password' => 'bea keeps her own books',
                'baseCurrency' => 'USD',
            ]);
            self::fail('A closed flag refuses another user.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
        }

        self::assertSame(2, $this->tally('SELECT COUNT(*) FROM user'));
        self::assertSame($ownerRow, $this->userWhere("role = 'owner'"));
        self::assertSame($demoRow, $this->userWhere("role = 'demo'"));
        self::assertSame(0, $this->tally("SELECT COUNT(*) FROM user WHERE name = 'Bea' COLLATE NOCASE"));

        $ownerAgain = $closed->login([
            'name' => 'Ada',
            'password' => 'correct horse battery',
        ]);
        self::assertSame('owner', $ownerAgain['role']);
        $demoAgain = $closed->enterDemo();
        self::assertSame('demo', $demoAgain['role']);
        $demoByPassword = $closed->login([
            'name' => DemoLibrary::NAME,
            'password' => DemoLibrary::PASSWORD,
        ]);
        self::assertSame('demo', $demoByPassword['role']);

        $open = $this->auth(true);
        self::assertTrue($open->allowsNewUser());
        $created = $open->createUser([
            'name' => 'Bea',
            'password' => 'bea keeps her own books',
            'baseCurrency' => 'USD',
        ]);
        self::assertSame('Bea', $created['name']);
        self::assertSame('user', $created['role']);
        self::assertSame($ownerRow, $this->userWhere("role = 'owner'"));
        self::assertSame($demoRow, $this->userWhere("role = 'demo'"));
        self::assertSame(1, $this->tally("SELECT COUNT(*) FROM user WHERE role = 'user'"));
    }

    private function auth(bool $allowNewUser): AuthService
    {
        $catalog = new CurrencyCatalog();

        return new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog), $allowNewUser);
    }

    /** @return array<string, mixed> */
    private function userWhere(string $where): array
    {
        $row = $this->pdo->query(
            'SELECT id, name, password_hash, base_currency, theme, role, span_length FROM user WHERE ' . $where
        )->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function tally(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
