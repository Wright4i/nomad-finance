<?php

declare(strict_types=1);

namespace App\Tests\Money;

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Money\Converter;
use App\Money\CurrencyCatalog;
use App\Money\RateBaseline;
use App\Money\UnknownCurrency;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateBaselineTest extends TestCase
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

    public function testSnapshotCoversQuotedCurrencies(): void
    {
        $catalog = new CurrencyCatalog();
        $snapshot = RateBaseline::load();

        self::assertSame('USD', $snapshot['base']);
        self::assertSame('2026-11-03', $snapshot['effectiveDate']);
        self::assertSame('USD (US Dollar)', $catalog->label('USD'));
        self::assertSame('JPY (Japanese Yen)', $catalog->label('JPY'));
        self::assertSame('0.00631316', $snapshot['rates']['JPY']);
        self::assertSame('1.11928688', $snapshot['rates']['EUR']);
        self::assertSame(0, $catalog->exponent('JPY'));
        self::assertSame(3, $catalog->exponent('BHD'));
        self::assertSame(4, $catalog->exponent('CLF'));
        self::assertSame(2, $catalog->exponent('XCG'));
        self::assertSame(2, $catalog->exponent('ZWG'));
        self::assertGreaterThan(150, count($catalog->codes()));

        $unquoted = [];
        foreach ($catalog->codes() as $code) {
            if ($code === 'USD') {
                continue;
            }
            if (!isset($snapshot['rates'][$code])) {
                $unquoted[] = $code;
                continue;
            }
            Converter::assertRate($snapshot['rates'][$code]);
        }
        self::assertSame(['KPW', 'SVC', 'UYW'], $unquoted);
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->expectException(UnknownCurrency::class);
        (new CurrencyCatalog())->assert('ZZZ');
    }

    public function testUsdSetupCopiesTheSnapshotAndDemoLeavesEurOut(): void
    {
        $this->auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);

        $owner = (int) $this->pdo->query("SELECT id FROM user WHERE role = 'owner'")->fetchColumn();
        $ownerCount = $this->pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE user_id = ?');
        $ownerCount->execute([$owner]);
        self::assertSame(count(RateBaseline::rates()), (int) $ownerCount->fetchColumn());

        $ownerEur = $this->pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE user_id = ? AND currency = \'EUR\'');
        $ownerEur->execute([$owner]);
        self::assertSame(1, (int) $ownerEur->fetchColumn());

        $demo = (int) $this->pdo->query("SELECT id FROM user WHERE role = 'demo'")->fetchColumn();
        $demoJpy = $this->pdo->prepare(
            'SELECT rate FROM exchange_rates WHERE user_id = ? AND currency = \'JPY\''
        );
        $demoJpy->execute([$demo]);
        self::assertSame(['0.00631316'], $demoJpy->fetchAll(PDO::FETCH_COLUMN));
        $demoEur = $this->pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE user_id = ? AND currency = \'EUR\'');
        $demoEur->execute([$demo]);
        self::assertSame(1, (int) $demoEur->fetchColumn());
    }

    public function testAnotherBaseStartsWithoutRates(): void
    {
        $this->auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'EUR',
        ]);

        $owner = (int) $this->pdo->query("SELECT id FROM user WHERE role = 'owner'")->fetchColumn();
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE user_id = ?');
        $count->execute([$owner]);
        self::assertSame(0, (int) $count->fetchColumn());
    }
}
