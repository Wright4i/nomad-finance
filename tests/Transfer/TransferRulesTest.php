<?php

declare(strict_types=1);

namespace App\Tests\Transfer;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Target\TargetService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\Transfer\TransferService;
use App\Trip\Drive;
use PDO;
use PHPUnit\Framework\TestCase;

final class TransferRulesTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private BillService $bills;

    private EnvelopeService $envelopes;

    private TransferService $transfers;

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
        $this->transfers = new TransferService($this->pdo, $actor, $this->bills, $this->envelopes);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCardPayMarksTheBillAndMovesBalancesWhenUnassignedCashIsShort(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE accounts SET balance_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('UPDATE pay_cycles SET rate_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'DELETE FROM payroll_entries WHERE pay_cycle_id IN (SELECT id FROM pay_cycles WHERE user_id = ?)'
        )->execute([$userId]);

        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 20000,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 5000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $bill = $this->bills->create([
            'name' => 'Card dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 2000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-01',
            'today' => '2026-11-06',
        ]);
        $this->plant((int) $bill['categoryId'], '2026-11-06', 4000);
        $before = $this->assignment((int) $bill['categoryId']);
        $unassigned = max(0, $this->envelopes->show('2026-11-06')['availableMinor']);
        self::assertLessThan(3000, $unassigned);

        $paid = $this->transfers->payCard([
            'today' => '2026-11-06',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 5000,
            'billIds' => [$bill['id']],
        ]);
        self::assertSame('paid', $this->bills->get((int) $bill['id'])['status']);
        self::assertSame($before - 2000, $this->assignment((int) $bill['categoryId']));
        self::assertSame(15000, $paid['source']['balanceMinor']);
        self::assertSame(0, $paid['card']['balanceMinor']);
        self::assertSame(15000, $this->balance((int) $source['id']));
        self::assertSame(0, $this->balance((int) $card['id']));
    }

    public function testAnInBudgetTransferMovesBalancesAndLeavesAssignments(): void
    {
        $left = $this->accounts->create([
            'name' => 'Left jar',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 8000,
            'inBudget' => true,
        ]);
        $right = $this->accounts->create([
            'name' => 'Right jar',
            'type' => 'savings',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'inBudget' => true,
        ]);
        $before = $this->assignmentTotal();
        $available = $this->envelopes->show('2026-11-06')['availableMinor'];

        $moved = $this->transfers->move([
            'today' => '2026-11-06',
            'sourceId' => $left['id'],
            'destinationId' => $right['id'],
            'amountMinor' => 2500,
        ]);

        self::assertSame(5500, $moved['source']['balanceMinor']);
        self::assertSame(3500, $moved['destination']['balanceMinor']);
        self::assertSame($before, $this->assignmentTotal());
        self::assertSame($available, $this->envelopes->show('2026-11-06')['availableMinor']);
        self::assertSame([], $moved['buckets']);
    }

    public function testCardPreviewListsUnpaidBillsDrawnFromTheCard(): void
    {
        $card = $this->accounts->create([
            'name' => 'Camp card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 8000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $checking = $this->namedAccount('Everyday checking');
        $dinner = $this->bills->create([
            'name' => 'Dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 2000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-02',
            'today' => '2026-11-06',
        ]);
        $snacks = $this->bills->create([
            'name' => 'Snacks',
            'type' => 'laundry',
            'accountId' => $card['id'],
            'amountMinor' => 800,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-01',
            'today' => '2026-11-06',
        ]);
        $breakfast = $this->bills->create([
            'name' => 'Camp breakfast',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 1100,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-06',
            'today' => '2026-11-06',
        ]);
        $supper = $this->bills->create([
            'name' => 'Late supper',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 5000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-07',
            'today' => '2026-11-06',
        ]);
        $this->bills->create([
            'name' => 'Later',
            'type' => 'groceries',
            'accountId' => $card['id'],
            'amountMinor' => 500,
            'recurrence' => 'once',
            'nextDueDate' => '2026-12-01',
            'today' => '2026-11-06',
        ]);
        $paused = $this->bills->create([
            'name' => 'Paused meal',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 700,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-03',
            'today' => '2026-11-06',
        ]);
        $this->bills->archiveBill((int) $paused['id'], ['today' => '2026-11-06']);
        $paid = $this->bills->create([
            'name' => 'Paid meal',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 600,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-04',
            'today' => '2026-11-06',
        ]);
        $this->bills->markPaid((int) $paid['id'], ['paidOn' => '2026-10-04']);
        $this->bills->create([
            'name' => 'Somewhere else',
            'type' => 'rent',
            'accountId' => $checking,
            'amountMinor' => 900,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-05',
            'today' => '2026-11-06',
        ]);

        $preview = $this->transfers->cardPreview((int) $card['id'], '2026-11-06');

        self::assertSame(8000, $preview['paymentMinor']);
        self::assertSame(8000, $preview['card']['balanceMinor']);
        self::assertSame(['Dinner', 'Snacks', 'Camp breakfast'], array_column($preview['charges'], 'name'));
        self::assertSame('Restaurants', $preview['charges'][0]['categoryName']);
        self::assertSame('wants', $preview['charges'][0]['group']);
        self::assertSame('needs', $preview['charges'][1]['group']);
        self::assertSame((int) $dinner['id'], $preview['charges'][0]['id']);
        self::assertSame((int) $snacks['id'], $preview['charges'][1]['id']);
        self::assertSame((int) $breakfast['id'], $preview['charges'][2]['id']);
        self::assertSame(1100, $preview['charges'][2]['amountMinor']);
        self::assertSame('Restaurants', $preview['charges'][2]['categoryName']);
        self::assertSame('wants', $preview['charges'][2]['group']);
        self::assertSame(['2026-10-02', '2026-11-01', '2026-11-06'], array_column($preview['charges'], 'dueOn'));
        self::assertNotContains('Late supper', array_column($preview['charges'], 'name'));
        self::assertNotContains('Later', array_column($preview['charges'], 'name'));
        self::assertSame((int) $supper['id'], $this->bills->get((int) $supper['id'])['id']);
    }

    public function testBillsPastThePaymentAreRefusedAndTheFullPaymentMarksThemPaid(): void
    {
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 20000,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 9000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $big = $this->bills->create([
            'name' => 'Big dinner',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 4000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-02',
            'today' => '2026-11-06',
        ]);
        $small = $this->bills->create([
            'name' => 'Small wash',
            'type' => 'laundry',
            'accountId' => $card['id'],
            'amountMinor' => 1500,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-03',
            'today' => '2026-11-06',
        ]);
        $foreign = $this->bills->create([
            'name' => 'Not on the card',
            'type' => 'rent',
            'accountId' => $source['id'],
            'amountMinor' => 500,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-04',
            'today' => '2026-11-06',
        ]);
        $this->plant((int) $big['categoryId'], '2026-11-06', 20000);
        $this->plant((int) $small['categoryId'], '2026-11-06', 20000);
        $laundryBefore = $this->assignment((int) $small['categoryId']);
        $restaurantsBefore = $this->assignment((int) $big['categoryId']);

        try {
            $this->transfers->payCard([
                'today' => '2026-11-06',
                'cardId' => $card['id'],
                'sourceId' => $source['id'],
                'amountMinor' => 1500,
                'billIds' => [$foreign['id']],
            ]);
            self::fail('A bill drawn from another account was accepted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Choose bills drawn from this card.', $e->getMessage());
        }
        self::assertSame('active', $this->bills->get((int) $small['id'])['status']);
        self::assertSame(20000, $this->balance((int) $source['id']));

        try {
            $this->transfers->payCard([
                'today' => '2026-11-06',
                'cardId' => $card['id'],
                'sourceId' => $source['id'],
                'amountMinor' => 1500,
                'billIds' => [$big['id'], $small['id']],
            ]);
            self::fail('Bills larger than the payment were accepted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('The selected bills are more than this payment.', $e->getMessage());
        }
        self::assertSame('active', $this->bills->get((int) $big['id'])['status']);
        self::assertSame('active', $this->bills->get((int) $small['id'])['status']);
        self::assertSame(20000, $this->balance((int) $source['id']));
        self::assertSame(9000, $this->balance((int) $card['id']));
        $available = (int) $this->envelopes->show('2026-11-06')['availableMinor'];

        $paid = $this->transfers->payCard([
            'today' => '2026-11-06',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 5500,
            'billIds' => [$big['id'], $small['id']],
        ]);

        self::assertSame('paid', $this->bills->get((int) $big['id'])['status']);
        self::assertSame('paid', $this->bills->get((int) $small['id'])['status']);
        self::assertSame($restaurantsBefore - 4000, $this->assignment((int) $big['categoryId']));
        self::assertSame($laundryBefore - 1500, $this->assignment((int) $small['categoryId']));
        self::assertSame(14500, $paid['source']['balanceMinor']);
        self::assertSame(3500, $paid['card']['balanceMinor']);
        self::assertSame(5500, $paid['paymentMinor']);
        self::assertSame(5500, $paid['selectedMinor']);
        self::assertSame(0, $paid['beyondMinor']);
        self::assertSame(14500, $this->balance((int) $source['id']));
        self::assertSame(3500, $this->balance((int) $card['id']));
        self::assertSame($available, (int) $this->envelopes->show('2026-11-06')['availableMinor']);
    }

    public function testABillDueTodayIsABucketAndAFutureBillIsNot(): void
    {
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 20000,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 8000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $yesterday = $this->bills->create([
            'name' => 'Yesterday',
            'type' => 'groceries',
            'accountId' => $card['id'],
            'amountMinor' => 900,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-06',
            'today' => '2026-11-07',
        ]);
        $todayBill = $this->bills->create([
            'name' => 'Wingstop',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 1400,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-07',
            'today' => '2026-11-07',
        ]);
        $tomorrow = $this->bills->create([
            'name' => 'Tomorrow',
            'type' => 'laundry',
            'accountId' => $card['id'],
            'amountMinor' => 600,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-08',
            'today' => '2026-11-07',
        ]);

        $preview = $this->transfers->cardPreview((int) $card['id'], '2026-11-07');

        self::assertSame(['Yesterday', 'Wingstop'], array_column($preview['charges'], 'name'));
        self::assertSame(['2026-11-06', '2026-11-07'], array_column($preview['charges'], 'dueOn'));
        self::assertSame((int) $yesterday['id'], $preview['charges'][0]['id']);
        self::assertSame((int) $todayBill['id'], $preview['charges'][1]['id']);
        self::assertNotContains('Tomorrow', array_column($preview['charges'], 'name'));

        try {
            $this->transfers->payCard([
                'today' => '2026-11-07',
                'cardId' => $card['id'],
                'sourceId' => $source['id'],
                'amountMinor' => 600,
                'billIds' => [$tomorrow['id']],
            ]);
            self::fail('A bill due after today was selected.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Choose bills drawn from this card.', $e->getMessage());
        }
        self::assertSame('active', $this->bills->get((int) $tomorrow['id'])['status']);
        self::assertSame(20000, $this->balance((int) $source['id']));
        self::assertSame(8000, $this->balance((int) $card['id']));
    }

    public function testTheFullPaymentLeavesCheckingAndTheBucketOnlyMarksTheBill(): void
    {
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 50000,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 20000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $bill = $this->bills->create([
            'name' => 'Wingstop',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 17000,
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-06',
            'today' => '2026-11-07',
        ]);
        $this->plant((int) $bill['categoryId'], '2026-11-07', 20000);
        $before = $this->assignment((int) $bill['categoryId']);
        $available = (int) $this->envelopes->show('2026-11-07')['availableMinor'];

        $paid = $this->transfers->payCard([
            'today' => '2026-11-07',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 20000,
            'billIds' => [$bill['id']],
        ]);

        self::assertSame('paid', $this->bills->get((int) $bill['id'])['status']);
        self::assertSame($before - 17000, $this->assignment((int) $bill['categoryId']));
        self::assertSame(20000, $paid['paymentMinor']);
        self::assertSame(17000, $paid['selectedMinor']);
        self::assertSame(3000, $paid['beyondMinor']);
        self::assertSame(30000, $paid['source']['balanceMinor']);
        self::assertSame(0, $paid['card']['balanceMinor']);
        self::assertSame(30000, $this->balance((int) $source['id']));
        self::assertSame(0, $this->balance((int) $card['id']));
        self::assertSame($available, (int) $this->envelopes->show('2026-11-07')['availableMinor']);
    }

    public function testAPaymentWithNoBillStillMovesTheFullAmount(): void
    {
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 30000,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 20000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);

        $paid = $this->transfers->payCard([
            'today' => '2026-11-07',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 15000,
            'billIds' => [],
        ]);

        self::assertSame(15000, $paid['paymentMinor']);
        self::assertSame(0, $paid['selectedMinor']);
        self::assertSame(15000, $paid['beyondMinor']);
        self::assertSame(15000, $paid['source']['balanceMinor']);
        self::assertSame(5000, $paid['card']['balanceMinor']);
        self::assertSame(15000, $this->balance((int) $source['id']));
        self::assertSame(5000, $this->balance((int) $card['id']));
    }

    public function testPayingTheWholeBalanceLandsOnZeroAndCannotPassEitherBalance(): void
    {
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 2500,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 2500,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $bill = $this->bills->create([
            'name' => 'Exact',
            'type' => 'restaurants',
            'accountId' => $card['id'],
            'amountMinor' => 2500,
            'recurrence' => 'once',
            'nextDueDate' => '2026-10-02',
            'today' => '2026-11-06',
        ]);

        try {
            $this->transfers->payCard([
                'today' => '2026-11-06',
                'cardId' => $card['id'],
                'sourceId' => $source['id'],
                'amountMinor' => 2501,
                'billIds' => [$bill['id']],
            ]);
            self::fail('A payment above both balances was accepted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame('active', $this->bills->get((int) $bill['id'])['status']);
        self::assertSame(2500, $this->balance((int) $source['id']));
        self::assertSame(2500, $this->balance((int) $card['id']));

        $paid = $this->transfers->payCard([
            'today' => '2026-11-06',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 2500,
            'billIds' => [$bill['id']],
        ]);

        self::assertSame(0, $paid['source']['balanceMinor']);
        self::assertSame(0, $paid['card']['balanceMinor']);
        self::assertSame('paid', $this->bills->get((int) $bill['id'])['status']);
    }

    public function testACardThatIsNotPaidInFullOrIsArchivedIsRefused(): void
    {
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 5000,
            'inBudget' => true,
        ]);
        $minimum = $this->accounts->create([
            'name' => 'Minimum card',
            'type' => 'fuel',
            'currency' => 'USD',
            'balanceMinor' => 4000,
            'autopayChoice' => 'minimum',
            'bankAutopay' => true,
            'minimumDueMinor' => 2500,
            'dueDay' => 20,
        ]);
        try {
            $this->transfers->cardPreview((int) $minimum['id'], '2026-11-06');
            self::fail('A minimum-payment card was offered.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
        }

        $card = $this->accounts->create([
            'name' => 'Closable card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 9,
        ]);
        $this->accounts->archive((int) $card['id']);
        try {
            $this->transfers->payCard([
                'today' => '2026-11-06',
                'cardId' => $card['id'],
                'sourceId' => $source['id'],
                'amountMinor' => 100,
                'billIds' => [],
            ]);
            self::fail('An archived card was paid.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(5000, $this->balance((int) $source['id']));
    }

    public function testUnassignedCashCanPayACardWhenNoBillIsSelected(): void
    {
        $this->quietBooks();
        $source = $this->accounts->create([
            'name' => 'Pay from',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 0,
            'inBudget' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Pay card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'autopayChoice' => 'balance',
            'bankAutopay' => true,
            'dueDay' => 12,
        ]);
        $funded = $this->fundUntil((int) $source['id'], 100);

        $paid = $this->transfers->payCard([
            'today' => '2026-11-06',
            'cardId' => $card['id'],
            'sourceId' => $source['id'],
            'amountMinor' => 100,
            'billIds' => [],
        ]);

        self::assertSame(0, $paid['selectedMinor']);
        self::assertSame(100, $paid['paymentMinor']);
        self::assertSame(100, $paid['beyondMinor']);
        self::assertSame($funded - 100, $paid['source']['balanceMinor']);
        self::assertSame(0, $paid['card']['balanceMinor']);
    }

    public function testAnInBudgetTransferLeavesAPlantedBucketAlone(): void
    {
        $spare = $this->goal('Spare jar');
        $this->plant($spare, '2026-11-06', 3000);
        $left = $this->accounts->create([
            'name' => 'Left jar',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 4000,
            'inBudget' => true,
        ]);
        $right = $this->accounts->create([
            'name' => 'Right jar',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 500,
            'inBudget' => true,
        ]);
        $before = $this->balance((int) $left['id']);

        $preview = $this->transfers->previewMove([
            'today' => '2026-11-06',
            'sourceId' => $left['id'],
            'destinationId' => $right['id'],
            'amountMinor' => 1200,
        ]);
        self::assertFalse($preview['leavesPool']);
        self::assertSame([], $preview['buckets']);
        self::assertSame(0, $preview['gapMinor']);
        self::assertTrue($preview['ready']);
        self::assertSame($before, $this->balance((int) $left['id']));

        $moved = $this->transfers->move([
            'today' => '2026-11-06',
            'sourceId' => $left['id'],
            'destinationId' => $right['id'],
            'amountMinor' => 1200,
        ]);

        self::assertSame(2800, $moved['source']['balanceMinor']);
        self::assertSame(1700, $moved['destination']['balanceMinor']);
        self::assertSame(3000, $this->assignment($spare));
        self::assertSame([], $moved['buckets']);
    }

    public function testATransferCannotUseTheSameAccountOrMoreThanTheSource(): void
    {
        $source = $this->accounts->create([
            'name' => 'Only jar',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'inBudget' => true,
        ]);
        $other = $this->accounts->create([
            'name' => 'Other jar',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 1000,
            'inBudget' => true,
        ]);
        $loan = $this->accounts->create([
            'name' => 'Not an asset',
            'type' => 'loan',
            'currency' => 'USD',
            'balanceMinor' => 5000,
            'autopayChoice' => 'none',
            'bankAutopay' => false,
        ]);

        foreach ([
            ['sourceId' => $source['id'], 'destinationId' => $source['id'], 'amountMinor' => 100],
            ['sourceId' => $source['id'], 'destinationId' => $other['id'], 'amountMinor' => 0],
            ['sourceId' => $source['id'], 'destinationId' => $loan['id'], 'amountMinor' => 100],
        ] as $input) {
            try {
                $this->transfers->move(['today' => '2026-11-06'] + $input);
                self::fail('A refused transfer was posted.');
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
                self::assertStringNotContainsString('Short', $e->getMessage());
            }
        }

        try {
            $this->transfers->previewMove([
                'today' => '2026-11-06',
                'sourceId' => $source['id'],
                'destinationId' => $other['id'],
                'amountMinor' => 1001,
            ]);
            self::fail('A preview above the From balance was offered.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Short $0.01. You are asking for more than this account holds.', $e->getMessage());
        }
        try {
            $this->transfers->move([
                'today' => '2026-11-06',
                'sourceId' => $source['id'],
                'destinationId' => $other['id'],
                'amountMinor' => 1001,
            ]);
            self::fail('A transfer above the From balance was posted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Short $0.01. You are asking for more than this account holds.', $e->getMessage());
        }

        self::assertSame(1000, $this->balance((int) $source['id']));
        self::assertSame(1000, $this->balance((int) $other['id']));
        self::assertSame(5000, $this->balance((int) $loan['id']));

        $moved = $this->transfers->move([
            'today' => '2026-11-06',
            'sourceId' => $source['id'],
            'destinationId' => $other['id'],
            'amountMinor' => 1000,
        ]);
        self::assertSame(0, $moved['source']['balanceMinor']);
        self::assertSame(2000, $moved['destination']['balanceMinor']);
    }

    public function testLeavingThePoolTakesUnassignedThenBucketsAndWillNotPassALockedFloor(): void
    {
        $this->quietBooks();
        $sheet = $this->envelopes->show('2026-11-06');
        $locked = null;
        foreach ($sheet['categories'] as $category) {
            if (!is_array($category)) {
                continue;
            }
            if (($category['role'] ?? '') === 'locked' && (int) ($category['billMinor'] ?? 0) > 0) {
                $locked = $category;
                break;
            }
        }
        self::assertIsArray($locked, 'A locked bucket with bills is part of the sample.');
        $lockedId = (int) $locked['id'];
        $floor = (int) $locked['billMinor'];
        $source = $this->accounts->create([
            'name' => 'Pool jar',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 20000000,
            'inBudget' => true,
        ]);
        $destination = $this->accounts->create([
            'name' => 'Parked fund',
            'type' => 'savings',
            'currency' => 'USD',
            'balanceMinor' => 0,
            'inBudget' => false,
        ]);
        $this->hold($lockedId, '2026-11-06', $floor + 400);
        $spare = $this->goal('Spare jar');
        $this->hold($spare, '2026-11-06', 1000);
        self::assertSame($floor + 400, $this->assignment($lockedId));
        self::assertSame(1000, $this->assignment($spare));

        $available = (int) $this->envelopes->show('2026-11-06')['availableMinor'];
        $unassigned = max(0, $available);
        $amount = $unassigned + 1400;
        self::assertLessThanOrEqual(20000000, $amount);
        $input = [
            'today' => '2026-11-06',
            'sourceId' => $source['id'],
            'destinationId' => $destination['id'],
            'amountMinor' => $amount,
        ];

        $preview = $this->transfers->previewMove($input);
        self::assertTrue($preview['leavesPool']);
        self::assertSame($unassigned, $preview['fromUnassignedMinor']);
        self::assertSame(0, $preview['gapMinor']);
        self::assertTrue($preview['ready']);
        $taken = [];
        foreach ($preview['buckets'] as $bucket) {
            $taken[(int) $bucket['categoryId']] = (int) $bucket['amountMinor'];
        }
        self::assertSame(1000, $taken[$spare] ?? null);
        self::assertSame(400, $taken[$lockedId] ?? null);
        self::assertCount(2, $preview['buckets']);
        self::assertSame(20000000, $this->balance((int) $source['id']));

        $short = $input;
        $short['amountMinor'] = $amount + 50;
        $gap = $this->transfers->previewMove($short);
        self::assertSame(50, $gap['gapMinor']);
        self::assertTrue($gap['ready']);
        self::assertSame(20000000, $this->balance((int) $source['id']));
        self::assertSame(0, $this->balance((int) $destination['id']));

        $moved = $this->transfers->move($short);
        self::assertSame(20000000 - ($amount + 50), $moved['source']['balanceMinor']);
        self::assertSame($amount + 50, $moved['destination']['balanceMinor']);
        self::assertSame($floor, $this->assignment($lockedId));
        self::assertSame(0, $this->assignment($spare));

        $left = 20000000 - ($amount + 50);
        try {
            $this->transfers->move([
                'today' => '2026-11-06',
                'sourceId' => $source['id'],
                'destinationId' => $destination['id'],
                'amountMinor' => $left + 1,
            ]);
            self::fail('A transfer above the From balance was posted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Short $0.01. You are asking for more than this account holds.', $e->getMessage());
        }
        self::assertSame($left, $this->balance((int) $source['id']));
        self::assertSame($amount + 50, $this->balance((int) $destination['id']));
        self::assertSame($floor, $this->assignment($lockedId));
        self::assertSame(0, $this->assignment($spare));
    }

    private function hold(int $categoryId, string $today, int $amount): void
    {
        $this->pdo->prepare('UPDATE assignments SET amount_minor = 0 WHERE category_id = ?')->execute([$categoryId]);
        $this->plant($categoryId, $today, $amount);
    }

    private function plant(int $categoryId, string $today, int $amount): void
    {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pay_periods
             WHERE user_id = ? AND period_start <= ? AND (period_end IS NULL OR period_end >= ?)
             ORDER BY period_start DESC LIMIT 1'
        );
        $stmt->execute([$userId, $today, $today]);
        $periodId = (int) $stmt->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor)
             VALUES (?, ?, ?, 0)
             ON CONFLICT(pay_period_id, category_id) DO UPDATE SET amount_minor = excluded.amount_minor'
        )->execute([$periodId, $categoryId, $amount]);
    }

    private function balance(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT balance_minor FROM accounts WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function assignment(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount_minor), 0) FROM assignments WHERE category_id = ?');
        $stmt->execute([$categoryId]);

        return (int) $stmt->fetchColumn();
    }

    private function assignmentTotal(): int
    {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.amount_minor), 0)
             FROM assignments a
             JOIN pay_periods p ON p.id = a.pay_period_id
             WHERE p.user_id = ?'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function quietBooks(): void
    {
        $userId = (int) $_SESSION['user_id'];
        $this->pdo->prepare('UPDATE accounts SET balance_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('UPDATE pay_cycles SET rate_minor = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'DELETE FROM payroll_entries WHERE pay_cycle_id IN (SELECT id FROM pay_cycles WHERE user_id = ?)'
        )->execute([$userId]);
        $this->pdo->prepare(
            'UPDATE assignments SET amount_minor = 0
             WHERE pay_period_id IN (SELECT id FROM pay_periods WHERE user_id = ?)'
        )->execute([$userId]);
    }

    private function goal(string $name): int
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO categories (user_id, name, category_group, amount_minor, role, created_at, updated_at)
             VALUES (?, ?, \'wants\', 0, \'goal\', ?, ?)'
        )->execute([(int) $_SESSION['user_id'], $name, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function namedAccount(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM accounts WHERE user_id = ? AND name = ?');
        $stmt->execute([(int) $_SESSION['user_id'], $name]);

        return (int) $stmt->fetchColumn();
    }

    private function fundUntil(int $accountId, int $need): int
    {
        $this->pdo->prepare('UPDATE accounts SET balance_minor = 0 WHERE id = ?')->execute([$accountId]);
        $base = (int) $this->envelopes->show('2026-11-06')['availableMinor'];
        $balance = $need - $base;
        if ($balance < $need) {
            $balance = $need;
        }
        $this->pdo->prepare('UPDATE accounts SET balance_minor = ? WHERE id = ?')->execute([$balance, $accountId]);
        $available = (int) $this->envelopes->show('2026-11-06')['availableMinor'];
        self::assertGreaterThanOrEqual($need, $available, 'Unassigned cash was not funded.');

        return $balance;
    }
}
