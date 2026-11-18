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

    public function testUncoveredFloatIsTheBalanceMinusBillsAlreadyInBuckets(): void
    {
        self::assertSame(['covered' => 200000, 'uncovered' => 20000], FloatCover::split(220000, 200000));
        self::assertSame(['covered' => 220000, 'uncovered' => 0], FloatCover::split(220000, 250000));
        self::assertSame(['covered' => 0, 'uncovered' => 0], FloatCover::split(0, 200000));

        $this->pdo->prepare('UPDATE user SET acct_period_start = ?, acct_period_length = ? WHERE role = \'demo\'')
            ->execute(['2026-11-01', '1 month']);
        $before = $this->envelopes->show('2026-11-06');
        $card = $this->accounts->create([
            'name' => 'Float card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 220000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 15,
        ]);
        $this->bills->create([
            'name' => 'Bucket dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 200000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-20',
            'today' => '2026-11-06',
        ]);
        $this->bills->create([
            'name' => 'Later dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 50000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-12-20',
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
        self::assertSame(220000, $float['balanceMinor']);
        self::assertSame(200000, $float['coveredMinor']);
        self::assertSame(20000, $float['uncoveredMinor']);
        self::assertSame($before['poolMinor'] - 20000, $sheet['poolMinor']);
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
