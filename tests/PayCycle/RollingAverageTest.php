<?php

declare(strict_types=1);

namespace App\Tests\PayCycle;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\ExchangeRate\FailingRateFeed;
use App\ExchangeRate\RateService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\PayCycle\RollingAverage;
use App\Posting\PostingService;
use App\Target\TargetService;
use PDO;
use PHPUnit\Framework\TestCase;

final class RollingAverageTest extends TestCase
{
    private PDO $pdo;

    private string $path;

    private PayCycleService $cycles;

    private RollingAverage $average;

    private EnvelopeService $envelopes;

    private PostingService $postings;

    private int $checkingId;

    private int $cashId;

    private int $categoryId;

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
        $rates = new RateService($this->pdo, $catalog, $actor, new FailingRateFeed());
        $this->cycles = new PayCycleService($this->pdo, $actor);
        $this->average = new RollingAverage($this->pdo, $actor, $rates, $catalog);
        $this->envelopes = new EnvelopeService(
            $this->pdo,
            $actor,
            $rates,
            $catalog,
            new TargetService($this->pdo, $actor),
        );
        $this->postings = new PostingService($this->pdo, $actor, $catalog);
        $accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->checkingId = $this->named($accounts->list(false), 'Checking 1');
        $this->cashId = $this->named($accounts->list(false), 'Cash 3');
        $category = $this->pdo->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ?');
        $category->execute([$actor->id(), 'Needs 1']);
        $this->categoryId = (int) $category->fetchColumn();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testTheDivisorKeepsAnEmptyMonthAndNamesAMissingRate(): void
    {
        $result = RollingAverage::rolling([
            ['month' => '2026-08', 'baseMinor' => 100, 'name' => 'August Pay'],
            ['month' => '2026-10', 'baseMinor' => 50, 'name' => 'October Pay'],
            ['month' => '2026-10', 'baseMinor' => null, 'name' => 'Unrated Pay'],
            ['month' => '2026-11', 'baseMinor' => 999, 'name' => 'November Pay'],
        ], ['2026-08', '2026-09', '2026-10']);

        self::assertSame(50, $result['averageMinor']);
        self::assertSame(['Unrated Pay'], $result['excluded']);
        self::assertSame(2, RollingAverage::rolling([
            ['month' => '2026-08', 'baseMinor' => 5, 'name' => 'August Pay'],
        ], ['2026-08', '2026-09', '2026-10'])['averageMinor']);
    }

    public function testConfirmedInflowsAverageWithoutMovingThePool(): void
    {
        $weekly = $this->cycle('Weekly Payor');
        $saved = $this->cycles->update((int) $weekly['id'], $this->hourly($weekly, 3), '2026-11-04');
        self::assertSame(3, $saved['averageMonths']);

        $available = $this->envelopes->show('2026-11-04')['availableMinor'];
        $postings = $this->rows('postings');
        $assignments = $this->rows('assignments');
        $empty = $this->average->preview((int) $weekly['id'], '2026-11-04');
        self::assertNotNull($empty);
        self::assertSame(['2026-08', '2026-09', '2026-10'], $empty['months']);
        self::assertSame(0, $empty['averageMinor']);
        self::assertSame([], $empty['excluded']);
        self::assertSame($available, $this->envelopes->show('2026-11-04')['availableMinor']);
        self::assertSame($postings, $this->rows('postings'));
        self::assertSame($assignments, $this->rows('assignments'));

        try {
            $this->postings->create([
                'accountId' => $this->checkingId,
                'categoryId' => $this->categoryId,
                'amountMinor' => 100,
                'direction' => 'out',
                'postedOn' => '2026-10-01',
                'payee' => 'Outflow Pay',
                'payCycleId' => $weekly['id'],
            ]);
            self::fail('An outflow named a pay cycle.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('payCycleId', $e->fields);
        }
        self::assertSame($postings, $this->rows('postings'));

        $august = $this->inflow($this->checkingId, 10000, '2026-08-15', 'August Pay', (int) $weekly['id']);
        self::assertSame((int) $weekly['id'], $august['payCycleId']);
        $this->inflow($this->checkingId, 20000, '2026-10-15', 'October Pay', (int) $weekly['id']);
        $this->inflow($this->cashId, 5000, '2026-10-20', 'Unrated Pay', (int) $weekly['id']);
        $this->inflow($this->checkingId, 90000, '2026-11-02', 'November Pay', (int) $weekly['id']);

        $after = $this->envelopes->show('2026-11-04')['availableMinor'];
        $posted = $this->rows('postings');
        self::assertSame($postings + 4, $posted);
        $preview = $this->average->preview((int) $weekly['id'], '2026-11-04');
        self::assertNotNull($preview);
        self::assertSame(10000, $preview['averageMinor']);
        self::assertSame(['Unrated Pay'], $preview['excluded']);
        self::assertSame($after, $this->envelopes->show('2026-11-04')['availableMinor']);
        self::assertSame($posted, $this->rows('postings'));
        self::assertSame($assignments, $this->rows('assignments'));
        self::assertNull($this->average->preview((int) $this->cycle('Monthly Payor')['id'], '2026-11-04'));
    }

    public function testAverageMonthsAreThreeToSixOrOff(): void
    {
        $weekly = $this->cycle('Weekly Payor');
        try {
            $this->cycles->update((int) $weekly['id'], $this->hourly($weekly, 2), '2026-11-04');
            self::fail('An average of 2 months was stored.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('averageMonths', $e->fields);
        }
        try {
            $this->cycles->update((int) $weekly['id'], $this->hourly($weekly, 7), '2026-11-04');
            self::fail('An average of 7 months was stored.');
        } catch (ApiException $e) {
            self::assertArrayHasKey('averageMonths', $e->fields);
        }

        self::assertSame(6, $this->cycles->update((int) $weekly['id'], $this->hourly($weekly, 6), '2026-11-04')['averageMonths']);
        self::assertNull($this->cycles->update((int) $weekly['id'], $this->hourly($weekly, null), '2026-11-04')['averageMonths']);

        $salary = $this->cycle('Monthly Payor');
        try {
            $this->cycles->update((int) $salary['id'], [
                'payor' => 'Monthly Payor',
                'payday' => '2026-11-01',
                'length' => 'monthly',
                'payKind' => 'salary',
                'rateMinor' => 8400000,
                'averageMonths' => 3,
            ], '2026-11-04');
            self::fail('A salary cycle stored an average.');
        } catch (ApiException $e) {
            self::assertArrayHasKey('averageMonths', $e->fields);
        }
    }

    /** @param array<string, mixed> $cycle
     *  @return array<string, mixed>
     */
    private function hourly(array $cycle, int|null $months): array
    {
        return [
            'payor' => $cycle['payor'],
            'payday' => $cycle['payday'],
            'length' => $cycle['length'],
            'payKind' => 'hourly',
            'rateMinor' => $cycle['rateMinor'],
            'hours' => $cycle['hours'],
            'hoursMode' => 'typed',
            'averageMonths' => $months,
        ];
    }

    /** @return array<string, mixed> */
    private function cycle(string $payor): array
    {
        foreach ($this->cycles->list('2026-11-04', true) as $cycle) {
            if ($cycle['payor'] === $payor) {
                return $cycle;
            }
        }
        self::fail('Missing ' . $payor);
    }

    /** @param list<array<string, mixed>> $rows */
    private function named(array $rows, string $name): int
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return (int) $row['id'];
            }
        }
        self::fail('Missing ' . $name);
    }

    /** @return array<string, mixed> */
    private function inflow(int $accountId, int $amount, string $postedOn, string $payee, int $payCycleId): array
    {
        return $this->postings->create([
            'accountId' => $accountId,
            'categoryId' => $this->categoryId,
            'amountMinor' => $amount,
            'direction' => 'in',
            'postedOn' => $postedOn,
            'payee' => $payee,
            'payCycleId' => $payCycleId,
        ]);
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
