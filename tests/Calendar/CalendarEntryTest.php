<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Auth\Actor;
use App\Auth\AuthService;
use App\Calendar\CalendarEntryService;
use App\Database\Database;
use App\Dashboard\DashboardService;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use App\Trip\Drive;
use App\Trip\TripService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CalendarEntryTest extends TestCase
{
    private PDO $pdo;

    private CalendarEntryService $entries;

    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $this->entries = new CalendarEntryService($this->pdo, new Actor($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCreateUpdateAndDelete(): void
    {
        $created = $this->entries->create([
            'text' => '  Range 1  ',
            'startOn' => '2026-11-25',
            'endOn' => '2026-11-27',
        ]);
        self::assertSame('Range 1', $created['text']);
        self::assertSame('2026-11-25', $created['startOn']);
        self::assertSame('2026-11-27', $created['endOn']);

        $updated = $this->entries->update((int) $created['id'], [
            'text' => 'Range 2',
            'startOn' => '2026-11-26',
            'endOn' => '2026-11-26',
        ]);
        self::assertSame((int) $created['id'], $updated['id']);
        self::assertSame('Range 2', $updated['text']);
        self::assertSame(['2026-11-26'], $this->days($this->entries->month('2026-11'), 'Range 2'));

        $this->entries->delete((int) $created['id']);
        self::assertSame([], $this->days($this->entries->month('2026-11'), 'Range 2'));
        try {
            $this->entries->delete((int) $created['id']);
            self::fail('A deleted calendar entry was deleted again.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testEndBeforeStartIsRejected(): void
    {
        try {
            $this->entries->create([
                'text' => 'Range 1',
                'startOn' => '2026-11-10',
                'endOn' => '2026-11-09',
            ]);
            self::fail('A reversed date range was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('End is on or after the start.', $e->fields['endOn']);
        }
        self::assertSame([], $this->entries->month('2026-11'));

        $sameDay = $this->entries->create([
            'text' => 'Range 1',
            'startOn' => '2026-11-10',
            'endOn' => '2026-11-10',
        ]);
        self::assertSame('2026-11-10', $sameDay['startOn']);
        self::assertSame('2026-11-10', $sameDay['endOn']);
    }

    public function testTextIsOneToEightyCharacters(): void
    {
        $kept = $this->entries->create([
            'text' => str_repeat('a', 80),
            'startOn' => '2026-11-02',
            'endOn' => '2026-11-02',
        ]);
        self::assertSame(80, mb_strlen($kept['text']));

        foreach (['', '   ', str_repeat('b', 81)] as $text) {
            try {
                $this->entries->create([
                    'text' => $text,
                    'startOn' => '2026-11-03',
                    'endOn' => '2026-11-03',
                ]);
                self::fail('Text outside 1 to 80 characters was stored.');
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
                self::assertSame('Use text of 1 to 80 characters.', $e->fields['text']);
            }
        }
    }

    public function testAnotherUserCannotReadOrChangeTheEntry(): void
    {
        $created = $this->entries->create([
            'text' => 'Range 1',
            'startOn' => '2026-11-25',
            'endOn' => '2026-11-25',
        ]);
        $demoId = $_SESSION['user_id'];
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
             VALUES (?, ?, \'USD\', \'dark\', \'user\', ?)'
        )->execute(['Bea', password_hash('correct horse battery', PASSWORD_DEFAULT), $now]);
        $_SESSION['user_id'] = (int) $this->pdo->lastInsertId();

        self::assertSame([], $this->entries->month('2026-11'));
        try {
            $this->entries->update((int) $created['id'], [
                'text' => 'Taken',
                'startOn' => '2026-11-25',
                'endOn' => '2026-11-25',
            ]);
            self::fail('Another user changed the calendar entry.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
        try {
            $this->entries->delete((int) $created['id']);
            self::fail('Another user deleted the calendar entry.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }

        $_SESSION['user_id'] = $demoId;
        self::assertSame(['2026-11-25'], $this->days($this->entries->month('2026-11'), 'Range 1'));
    }

    public function testTheCalendarAndDashboardIncludeEachCoveredDay(): void
    {
        $_SESSION = [];
        $catalog = new CurrencyCatalog();
        $actor = new Actor($this->pdo);
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $auth->enterDemo();
        (new DemoLibrary($this->pdo, $catalog))->resetDemo();
        $entries = new CalendarEntryService($this->pdo, $actor);
        $created = $entries->create([
            'text' => 'Range 1',
            'startOn' => '2026-10-30',
            'endOn' => '2026-11-02',
        ]);

        self::assertSame(['2026-11-05', '2026-11-06'], $this->days($entries->month('2026-11'), 'Dump and fill'));
        self::assertSame(['2026-11-01', '2026-11-02'], $this->days($entries->month('2026-11'), 'Range 1'));
        self::assertSame(['2026-10-30', '2026-10-31'], $this->days($entries->month('2026-10'), 'Range 1'));
        self::assertSame([], $this->days($entries->month('2026-12'), 'Range 1'));

        $dashboard = new DashboardService(
            $this->pdo,
            $catalog,
            $actor,
            new EnvelopeService($this->pdo, $actor, $catalog, new TargetService($this->pdo, $actor), new PayCycleService($this->pdo, $actor)),
            new PayCycleService($this->pdo, $actor),
            new TripService($this->pdo, $actor, $catalog, new Drive(false)),
            $entries,
        );
        $sheet = $dashboard->show('2026-11-01');
        self::assertSame(['2026-11-01', '2026-11-02'], $this->timelineDays($sheet['timeline'], 'Range 1'));
        self::assertSame(['2026-11-05', '2026-11-06'], $this->timelineDays($sheet['timeline'], 'Dump and fill'));
        self::assertNotContains('2026-10-31', $this->timelineDays($sheet['timeline'], 'Range 1'));

        $entries->delete((int) $created['id']);
        $after = $dashboard->show('2026-11-01');
        self::assertSame([], $this->timelineDays($after['timeline'], 'Range 1'));
        self::assertSame(['2026-11-05', '2026-11-06'], $this->timelineDays($after['timeline'], 'Dump and fill'));
    }

    /** @param list<array<string, mixed>> $entries
     *  @return list<string>
     */
    private function days(array $entries, string $text): array
    {
        foreach ($entries as $entry) {
            if ($entry['text'] === $text) {
                return $entry['days'];
            }
        }

        return [];
    }

    /** @param list<array<string, mixed>> $timeline
     *  @return list<string>
     */
    private function timelineDays(array $timeline, string $text): array
    {
        $days = [];
        foreach ($timeline as $row) {
            if (($row['kind'] ?? '') === 'entry' && ($row['text'] ?? '') === $text) {
                $days[] = (string) $row['on'];
            }
        }

        return $days;
    }
}
