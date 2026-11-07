<?php

declare(strict_types=1);

namespace App\Tests\Envelope;

use App\Account\AccountService;
use App\Account\FloatCover;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Target\TargetService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Trip\Drive;
use PDO;
use PHPUnit\Framework\TestCase;

final class FloatTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private BillService $bills;

    private EnvelopeService $envelopes;

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
        $actor = new Actor($this->pdo);
        $auth = new AuthService($this->pdo, $catalog, new DemoLibrary($this->pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);
        $auth->enterDemo();
        $this->accounts = new AccountService($this->pdo, $catalog, $actor);
        $this->bills = new BillService($this->pdo, $catalog, $actor);
        $this->envelopes = new EnvelopeService(
            $this->pdo,
            $actor,
            $catalog,
            new TargetService($this->pdo, $actor),
            new PayCycleService($this->pdo, $actor),
            new Drive(false),
        );
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testUncoveredFloatIsBalanceMinusPastChargesCappedAtTheBalance(): void
    {
        self::assertSame(['covered' => 4000, 'uncovered' => 6000], FloatCover::split(10000, 4000));
        self::assertSame(['covered' => 10000, 'uncovered' => 0], FloatCover::split(10000, 15000));
        self::assertSame(['covered' => 0, 'uncovered' => 0], FloatCover::split(0, 4000));

        $before = $this->envelopes->show('2026-11-06');
        $card = $this->accounts->create([
            'name' => 'Float card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 10000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 15,
        ]);
        $this->bills->create([
            'name' => 'Past dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 4000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-01',
            'today' => '2026-11-06',
        ]);
        $this->bills->create([
            'name' => 'Future dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 2500,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-20',
            'today' => '2026-11-06',
        ]);

        $sheet = $this->envelopes->show('2026-11-06');
        $float = null;
        foreach ($sheet['sources']['floats'] as $row) {
            if ($row['name'] === 'Float card') {
                $float = $row;
            }
        }
        self::assertIsArray($float);
        self::assertSame(10000, $float['balanceMinor']);
        self::assertSame(4000, $float['coveredMinor']);
        self::assertSame(6000, $float['uncoveredMinor']);
        self::assertSame($before['poolMinor'] - 6000, $sheet['poolMinor']);
        self::assertNotContains('Float card', array_column($sheet['sources']['accounts'], 'name'));
    }

    public function testACardWithNothingUncoveredIsLeftOffTheAvailableList(): void
    {
        $settled = $this->accounts->create([
            'name' => 'Settled card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 15,
        ]);
        $this->bills->create([
            'name' => 'Past lunch',
            'type' => 'restaurants',
            'accountId' => $settled['id'],
            'amountMinor' => 4000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-01',
            'today' => '2026-11-06',
        ]);
        $this->accounts->create([
            'name' => 'Empty card',
            'type' => 'fuel',
            'currency' => 'USD',
            'balanceMinor' => 0,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 8,
        ]);

        $names = array_column($this->envelopes->show('2026-11-06')['sources']['floats'], 'name');
        self::assertNotContains('Settled card', $names);
        self::assertNotContains('Empty card', $names);
    }
}
