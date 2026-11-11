<?php

declare(strict_types=1);

namespace App\Tests\Homepage;

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Homepage\HomepageTodayService;
use App\Money\CurrencyCatalog;
use PHPUnit\Framework\TestCase;

final class HomepageTodayTest extends TestCase
{
    private \PDO $pdo;

    private string $path;

    protected function setUp(): void
    {
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

    public function testUnconfiguredOwnerReturnsReadyFalse(): void
    {
        $service = new HomepageTodayService($this->pdo, 'America/Los_Angeles');
        $payload = $service->show();
        self::assertFalse($payload['ready']);
        self::assertSame('Setup not finished', $payload['summary']);
    }

    public function testOwnerCalendarAndStaySurfaceOnToday(): void
    {
        $catalog = new CurrencyCatalog();
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $ownerId = (int) $this->pdo->query("SELECT id FROM user WHERE role = 'owner'")->fetchColumn();
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO calendar_entries (user_id, text, start_on, end_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ownerId, 'Dump and fill', '2026-11-05', '2026-11-10', $now, $now]);
        $this->pdo->prepare(
            'INSERT INTO calendar_entries (user_id, text, start_on, end_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ownerId, 'Mail pickup', '2026-11-10', '2026-11-10', $now, $now]);
        $this->pdo->prepare(
            'INSERT INTO trips (
                user_id, place_name, note, arrive_on, depart_on, latitude, longitude,
                city, region, address, account_id, total_minor, amount_due_minor, balance_due_on,
                rating_half_stars, rating_comment, travel, route_order, created_at, updated_at
             ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, 0, NULL, NULL, NULL, \'drive\', 1, ?, ?)'
        )->execute([
            $ownerId,
            'Scenic View RV',
            '2026-11-08',
            '2026-11-12',
            '44.0582',
            '-121.3153',
            'Bend',
            'OR',
            $now,
            $now,
        ]);

        $service = new HomepageTodayService($this->pdo, 'America/Los_Angeles');
        $payload = $service->show('2026-11-10');
        self::assertTrue($payload['ready']);
        self::assertSame('Dump and fill (last day)', $payload['line1']);
        self::assertSame('Mail pickup', $payload['line2']);
        self::assertSame('', $payload['line3']);
        self::assertSame(2, $payload['entryCount']);
        self::assertSame('Bend, OR', $payload['stay']);
        self::assertStringContainsString('2 notes', $payload['summary']);
        self::assertStringContainsString('Bend', $payload['preview']);
    }
}
