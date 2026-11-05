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

final class LockoutTest extends TestCase
{
    private PDO $pdo;

    private AuthService $auth;

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
        $this->auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testShortPasswordIsRejected(): void
    {
        try {
            $this->auth->setup([
                'name' => 'Ada',
                'password' => 'too-short',
                'baseCurrency' => 'USD',
            ]);
            self::fail('A short password should be rejected.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('password', $e->fields);
        }
    }

    public function testFifthFailureLocksTheNextAttempt(): void
    {
        $this->auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->auth->login(['name' => 'Ada', 'password' => 'wrong password here']);
                self::fail('A wrong password should be rejected.');
            } catch (ApiException $e) {
                self::assertSame(401, $e->status);
            }
        }

        try {
            $this->auth->login(['name' => 'Ada', 'password' => 'correct horse battery']);
            self::fail('The account should be locked.');
        } catch (ApiException $e) {
            self::assertSame(423, $e->status);
        }

        $demo = $this->auth->login([
            'name' => DemoLibrary::NAME,
            'password' => DemoLibrary::PASSWORD,
        ]);
        self::assertSame('demo', $demo['role']);
    }
}
