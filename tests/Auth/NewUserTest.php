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

final class NewUserTest extends TestCase
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

    public function testNewUserGetsEmptyBooksAndLeavesTheOwnerAndDemoAlone(): void
    {
        try {
            $this->auth->createUser([
                'name' => 'Bea',
                'password' => 'bea keeps her own books',
                'baseCurrency' => 'USD',
            ]);
            self::fail('A new user waits until setup has created the owner.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(0, $this->tally('SELECT COUNT(*) FROM user'));

        $owner = $this->auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        self::assertSame('owner', $owner['role']);

        $ownerRow = $this->userWhere("role = 'owner'");
        $demoRow = $this->userWhere("role = 'demo'");
        $ownerId = (int) $ownerRow['id'];
        $demoId = (int) $demoRow['id'];
        $ownerBooks = $this->books($ownerId);
        $demoBooks = $this->books($demoId);
        self::assertNotSame([], $demoBooks['trips']);
        self::assertContains('Stay 1', array_column($demoBooks['trips'], 'place_name'));

        $created = $this->auth->createUser([
            'name' => 'Bea',
            'password' => 'bea keeps her own books',
            'baseCurrency' => 'USD',
        ]);
        self::assertSame('Bea', $created['name']);
        self::assertSame('user', $created['role']);
        self::assertSame('USD', $created['baseCurrency']);
        self::assertNull($created['fuelPriceMinor']);

        $beaRow = $this->userWhere("name = 'Bea'");
        $beaId = (int) $beaRow['id'];
        self::assertNotSame($ownerId, $beaId);
        self::assertNotSame($demoId, $beaId);
        self::assertSame('user', $beaRow['role']);
        self::assertNull($beaRow['fuel_price_minor']);
        self::assertSame($ownerRow, $this->userWhere("role = 'owner'"));
        self::assertSame($demoRow, $this->userWhere("role = 'demo'"));
        self::assertSame($ownerBooks, $this->books($ownerId));
        self::assertSame($demoBooks, $this->books($demoId));
        self::assertNotSame([], $ownerBooks['rates']);
        self::assertNotSame([], $demoBooks['accounts']);
        $beaBooks = $this->books($beaId);
        self::assertSame([], $beaBooks['accounts']);
        self::assertSame([], $beaBooks['bills']);
        self::assertSame([], $beaBooks['rates']);
        self::assertSame([], $beaBooks['trips']);
        self::assertSame([], $beaBooks['categories']);
        self::assertSame([], $beaBooks['payCycles']);
        self::assertCount(1, $beaBooks['vehicles']);
        self::assertSame('Vehicle 1', $beaBooks['vehicles'][0]['name']);
        self::assertSame('rv', $beaBooks['vehicles'][0]['kind']);
        $beaVehicle = $this->pdo->prepare('SELECT id FROM vehicles WHERE user_id = ?');
        $beaVehicle->execute([$beaId]);
        $ownerVehicle = $this->pdo->prepare('SELECT id FROM vehicles WHERE user_id = ?');
        $ownerVehicle->execute([$ownerId]);
        self::assertNotSame((int) $ownerVehicle->fetchColumn(), (int) $beaVehicle->fetchColumn());
        self::assertSame(1, $this->tally("SELECT COUNT(*) FROM user WHERE role = 'owner'"));
        self::assertSame(1, $this->tally("SELECT COUNT(*) FROM user WHERE role = 'demo'"));
        self::assertSame($demoId, (int) $this->pdo->query("SELECT user_id FROM trips WHERE place_name = 'Stay 1'")->fetchColumn());

        $ownerAgain = $this->auth->login([
            'name' => 'Ada',
            'password' => 'correct horse battery',
        ]);
        self::assertSame('owner', $ownerAgain['role']);
        $demoAgain = $this->auth->login([
            'name' => DemoLibrary::NAME,
            'password' => DemoLibrary::PASSWORD,
        ]);
        self::assertSame('demo', $demoAgain['role']);
        $beaAgain = $this->auth->login([
            'name' => 'Bea',
            'password' => 'bea keeps her own books',
        ]);
        self::assertSame('user', $beaAgain['role']);
        self::assertSame('Bea', $beaAgain['name']);

        try {
            $this->auth->createUser([
                'name' => 'Ada',
                'password' => 'bea keeps her own books',
                'baseCurrency' => 'USD',
            ]);
            self::fail('A taken name is refused.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('name', $e->fields);
        }
        try {
            $this->auth->createUser([
                'name' => 'Demo',
                'password' => 'bea keeps her own books',
                'baseCurrency' => 'USD',
            ]);
            self::fail('Demo stays the sample login.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }

        self::assertSame($ownerRow, $this->userWhere("role = 'owner'"));
        self::assertSame($demoRow, $this->userWhere("role = 'demo'"));
        self::assertSame(1, $this->tally("SELECT COUNT(*) FROM user WHERE name = 'Bea' COLLATE NOCASE"));
    }

    /** @return array<string, mixed> */
    private function userWhere(string $where): array
    {
        $row = $this->pdo->query(
            'SELECT id, name, password_hash, base_currency, theme, role, horizon_days, fuel_price_minor FROM user WHERE ' . $where
        )->fetch();
        self::assertIsArray($row);

        return $row;
    }

    /** @return array{accounts:list<array<string,mixed>>,bills:list<array<string,mixed>>,rates:list<array<string,mixed>>,vehicles:list<array<string,mixed>>,trips:list<array<string,mixed>>,categories:list<array<string,mixed>>,payCycles:list<array<string,mixed>>} */
    private function books(int $userId): array
    {
        return [
            'accounts' => $this->rows('SELECT name, balance_minor, currency FROM accounts WHERE user_id = ? ORDER BY name', $userId),
            'bills' => $this->rows(
                'SELECT b.name, b.amount_minor, b.status FROM bills b JOIN accounts a ON a.id = b.account_id WHERE a.user_id = ? ORDER BY b.name',
                $userId,
            ),
            'rates' => $this->rows('SELECT currency, rate FROM exchange_rates WHERE user_id = ? ORDER BY currency', $userId),
            'vehicles' => $this->rows('SELECT name, kind FROM vehicles WHERE user_id = ? ORDER BY name', $userId),
            'trips' => $this->rows('SELECT place_name, arrive_on FROM trips WHERE user_id = ? ORDER BY place_name', $userId),
            'categories' => $this->rows('SELECT name FROM categories WHERE user_id = ? ORDER BY name', $userId),
            'payCycles' => $this->rows('SELECT payor FROM pay_cycles WHERE user_id = ? ORDER BY payor', $userId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int $userId): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        self::assertIsArray($rows);

        return $rows;
    }

    private function tally(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
