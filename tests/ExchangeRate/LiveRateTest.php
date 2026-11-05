<?php

declare(strict_types=1);

namespace App\Tests\ExchangeRate;

use App\Auth\Actor;
use App\Database\Database;
use App\ExchangeRate\RateFeed;
use App\ExchangeRate\RateFeedParser;
use App\ExchangeRate\RateService;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class LiveRateTest extends TestCase
{
    private PDO $pdo;

    private string $path;

    private int $demoId;

    private CountingFeed $feed;

    private RateService $rates;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $this->demoId = DemoSession::start($this->pdo);
        $this->feed = new CountingFeed(['JPY' => '0.01', 'THB' => '0.03']);
        $this->rates = new RateService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo), $this->feed);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO accounts (user_id, name, type, side, currency, balance_minor, created_at, updated_at)
             VALUES (?, \'Yen cash\', \'cash\', \'asset\', \'JPY\', 1000, ?, ?)'
        )->execute([$this->demoId, $now, $now]);
        $this->pdo->prepare(
            'INSERT INTO exchange_rates (user_id, base_currency, currency, rate, created_at, updated_at)
             VALUES (?, \'USD\', \'JPY\', \'0.0060\', ?, ?)'
        )->execute([$this->demoId, $now, $now]);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testParserInvertsTheOpenFeed(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__) . '/fixtures/rate-feed-usd.json');
        $quotes = RateFeedParser::quotes($body, 'USD');

        self::assertSame('0.00631316', $quotes['JPY']);
        self::assertSame('1.11928688', $quotes['EUR']);
        self::assertArrayNotHasKey('USD', $quotes);
    }

    public function testLiveQuoteReplacesTheFallbackAndIsNotPolledAgainTheSameDay(): void
    {
        $rates = $this->rates->into('USD');

        self::assertSame('0.01', $rates['JPY']);
        self::assertSame(1, $this->feed->calls);
        self::assertArrayNotHasKey('THB', $rates);

        $cached = $this->pdo->query('SELECT currency FROM rate_quotes ORDER BY currency')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['JPY'], $cached);

        $this->rates->into('USD');
        self::assertSame(1, $this->feed->calls);

        $row = $this->rates->list()[0];
        self::assertSame('JPY', $row['currency']);
        self::assertSame('0.0060', $row['fallbackRate']);
        self::assertSame('0.01', $row['liveRate']);
        self::assertTrue($row['live']);
    }

    public function testAStaleQuoteFallsBackUntilTheNextDay(): void
    {
        $this->rates->into('USD');
        $old = gmdate('c', time() - 90000);
        $this->pdo->prepare('UPDATE rate_quotes SET fetched_at = ?')->execute([$old]);

        self::assertSame('0.0060', $this->rates->into('USD')['JPY']);
        self::assertSame(1, $this->feed->calls);

        $this->pdo->prepare('UPDATE rate_polls SET attempted_at = ?')->execute([$old]);
        self::assertSame('0.01', $this->rates->into('USD')['JPY']);
        self::assertSame(2, $this->feed->calls);
    }

    public function testAFailedPollWaitsADayAndKeepsTheFallback(): void
    {
        $this->feed->fail = true;

        self::assertSame('0.0060', $this->rates->into('USD')['JPY']);
        self::assertSame(1, $this->feed->calls);
        $this->rates->into('USD');
        self::assertSame(1, $this->feed->calls);

        $shown = $this->rates->list();
        self::assertSame('JPY', $shown[0]['currency']);
        self::assertFalse($shown[0]['live']);
        self::assertNull($shown[0]['liveRate']);
    }

    public function testSavingAFallbackReplacesThatCurrency(): void
    {
        $saved = $this->rates->save(['currency' => 'jpy', 'rate' => '0.0065']);

        self::assertSame('0.0065', $saved['fallbackRate']);
        self::assertArrayNotHasKey('effectiveDate', $saved);
        $count = $this->pdo->query('SELECT COUNT(*) FROM exchange_rates')->fetchColumn();
        self::assertSame(1, (int) $count);
    }

    public function testACurrencyWithNeitherQuoteNorFallbackStaysOut(): void
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO accounts (user_id, name, type, side, currency, balance_minor, created_at, updated_at)
             VALUES (?, \'Won\', \'cash\', \'asset\', \'KPW\', 500, ?, ?)'
        )->execute([$this->demoId, $now, $now]);

        $rates = $this->rates->into('USD');

        self::assertArrayNotHasKey('KPW', $rates);
        self::assertSame('0.01', $rates['JPY']);
    }
}

final class CountingFeed implements RateFeed
{
    public int $calls = 0;

    public bool $fail = false;

    /** @param array<string, string> $quotes */
    public function __construct(private array $quotes)
    {
    }

    public function quotes(string $base): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new \RuntimeException('The feed is down.');
        }

        return $this->quotes;
    }
}
