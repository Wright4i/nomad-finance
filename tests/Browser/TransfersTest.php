<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverSelect;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransfersTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    private static ?ChromeDriver $driver = null;

    private static ?ChromeDriverService $service = null;

    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    private static string $database = '';

    public static function setUpBeforeClass(): void
    {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/public/build/index.html')) {
            throw new RuntimeException('Build the UI first: npm run build --prefix frontend');
        }

        self::$database = sys_get_temp_dir() . '/nomad-browser-' . bin2hex(random_bytes(4)) . '.sqlite';
        $port = self::reservePort();
        self::$base = 'http://127.0.0.1:' . $port;
        self::startServer($root, $port);

        $driverPort = self::reservePort();
        $options = new ChromeOptions();
        $options->setBinary(ChromeBinary::chrome());
        $options->addArguments([
            '--headless=new',
            '--disable-gpu',
            '--window-size=1280,900',
            '--lang=en-US',
            '--no-first-run',
        ]);
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        self::$service = new ChromeDriverService(ChromeBinary::driver(), $driverPort, [
            '--port=' . $driverPort,
            '--allowed-origins=*',
        ], getenv() ?: null);
        self::$driver = ChromeDriver::start($capabilities, self::$service);
        self::$driver->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::$driver->manage()->timeouts()->setScriptTimeout(30);
    }

    public static function tearDownAfterClass(): void
    {
        self::shutdown();
    }

    protected function onNotSuccessfulTest(\Throwable $t): never
    {
        if (self::$driver instanceof ChromeDriver) {
            $dir = dirname(__DIR__, 2) . '/var';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            try {
                file_put_contents($dir . '/browser-failure.png', self::$driver->takeScreenshot());
                file_put_contents($dir . '/browser-failure.html', self::$driver->getPageSource());
                file_put_contents($dir . '/browser-failure.url', self::$driver->getCurrentURL());
            } catch (\Throwable) {
            }
        }
        parent::onNotSuccessfulTest($t);
    }

    public function testTransfersShowTheAccountsTheWaterAndAConfirmationModal(): void
    {
        self::driver()->get(self::$base . '/');
        self::type('name', 'Ada');
        self::type('password', self::PASSWORD);
        self::type('currentOdometer', '0');
        self::click('Create my login');
        self::waitForText('Net worth');
        self::click('Log out');
        self::waitForText('Use demo');
        self::click('Use demo');
        self::waitForText('Available to assign');
        self::seedCard();

        self::clickLink('Transfers');
        self::waitForText('Pay a card');
        self::click('Between accounts');
        $fromLabels = self::selectLabels('moveSource');
        $toLabels = self::selectLabels('moveDestination');
        self::assertContains('Everyday checking - $3,180.00', $fromLabels);
        self::assertContains('Everyday checking - $3,180.00', $toLabels);
        self::assertContains('Emergency fund - $8,600.00', $toLabels);
        self::assertContains('Empty jar - $0.00', $toLabels);
        self::assertSame([], array_values(array_filter(
            $fromLabels,
            static fn (string $label): bool => str_contains($label, 'Empty jar'),
        )));
        self::choose('moveSource', 'Everyday checking - $3,180.00');
        $checking = self::accountCard('Everyday checking');
        self::assertStringContainsString('checking', $checking['text']);
        self::assertStringContainsString('$3,180.00', $checking['text']);
        self::assertSame('yes', $checking['inBudget']);
        self::assertSame('Included in available to assign', $checking['budget']);

        self::choose('moveDestination', 'Emergency fund - $8,600.00');
        $emergency = self::accountCard('Emergency fund');
        self::assertStringContainsString('savings', $emergency['text']);
        self::assertStringContainsString('$8,600.00', $emergency['text']);
        self::assertStringContainsString('APR 4.50%', $emergency['text']);
        self::assertSame('no', $emergency['inBudget']);
        self::assertSame('', $emergency['budget']);

        $amount = self::moneyField('moveAmount');
        self::assertSame('$', $amount['mark']);
        self::replace('moveAmount', '1000.00');
        self::click('Review');
        $leaving = self::dialogText();
        self::assertStringContainsString('Move this money?', $leaving);
        self::assertStringContainsString('Everyday checking', $leaving);
        self::assertStringContainsString('Emergency fund', $leaving);
        self::assertStringContainsString('$1,000.00', $leaving);
        self::assertStringNotContainsString('Unassigned cash covers', $leaving);
        self::assertStringNotContainsString('Marked paid', $leaving);
        self::assertSame(['checking', 'savings'], self::dialogTypes());
        self::assertTrue(self::dialogHasStream());
        $squares = self::dialogSquares();
        self::assertSame($squares['heights'][0], $squares['heights'][1]);
        self::assertSame($squares['tops'][0], $squares['tops'][1]);
        self::assertSame('round', $squares['round']);
        self::assertSame('round', $squares['join']);
        self::assertGreaterThanOrEqual(0, $squares['gap']);
        self::assertLessThanOrEqual(16, $squares['gap']);
        self::assertGreaterThanOrEqual(36, $squares['arrowHeight']);
        self::assertFalse($squares['overlaps']);
        self::assertTrue($squares['between']);
        self::assertTrue($squares['aligned']);
        self::assertTrue($squares['glyphs']);
        self::assertStringContainsString('--from', $squares['gradient']);
        self::assertStringContainsString('--to', $squares['gradient']);
        self::assertStringNotContainsString('Short', $leaving);
        self::assertStringNotContainsString('Accept these buckets', $leaving);
        self::assertStringNotContainsString('Buckets this would reduce', $leaving);
        self::assertTrue(self::confirmEnabled());
        self::click('Back');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$3,180.00', self::accountCard('Everyday checking')['text']);
        self::assertStringContainsString('$8,600.00', self::accountCard('Emergency fund')['text']);

        self::replace('moveAmount', '4000.00');
        self::click('Review');
        $over = self::dialogText();
        self::assertStringContainsString('Short $820.00. You are asking for more than this account holds.', $over);
        self::assertStringNotContainsString('Accept these buckets', $over);
        self::assertStringNotContainsString('Buckets this would reduce', $over);
        self::assertFalse(self::confirmEnabled());
        self::click('Back');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$3,180.00', self::accountCard('Everyday checking')['text']);
        self::assertStringContainsString('$8,600.00', self::accountCard('Emergency fund')['text']);

        self::choose('moveSource', 'Wallet - $86.00');
        self::choose('moveDestination', 'Camp jar - $140.00');
        self::assertSame('Included in available to assign', self::accountCard('Wallet')['budget']);
        self::assertStringContainsString('$86.00', self::accountCard('Wallet')['text']);
        self::assertStringContainsString('cash', self::accountCard('Camp jar')['text']);
        self::assertStringContainsString('$140.00', self::accountCard('Camp jar')['text']);
        self::replace('moveAmount', '1.00');
        self::click('Review');
        $moving = self::dialogText();
        self::assertStringContainsString('Wallet', $moving);
        self::assertStringContainsString('Camp jar', $moving);
        self::assertStringContainsString('$1.00', $moving);
        self::assertStringNotContainsString('Marked paid', $moving);
        self::assertStringNotContainsString('Unassigned cash covers', $moving);
        self::assertStringNotContainsString('Short', $moving);
        self::assertTrue(self::confirmEnabled());
        self::click('Confirm');
        self::waitForText('Transferred.');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$85.00', self::accountCard('Wallet')['text']);
        self::assertStringContainsString('$141.00', self::accountCard('Camp jar')['text']);

        self::click('Pay a card');
        $cardLabels = self::selectLabels('cardId');
        self::assertContains('Camp card - Camp bank (Ada) - $20.00', $cardLabels);
        self::assertContains('Bare card - $10.00', $cardLabels);
        self::assertSame([], array_values(array_filter(
            $cardLabels,
            static fn (string $label): bool => str_contains($label, 'Zero card'),
        )));
        self::choose('cardId', 'Camp card - Camp bank (Ada) - $20.00');
        self::waitForText('Trail snacks');
        self::waitForText('Wingstop');
        self::assertStringNotContainsString('Late supper', self::bodyText());
        $order = self::payOrder();
        self::assertTrue($order['cardBeforeSource']);
        self::assertTrue($order['sourceBeforeBuckets']);
        self::assertTrue($order['bucketsBeforeAmount']);
        self::assertStringContainsString('Bills Buckets', self::bodyText());
        self::assertStringContainsString('Total payment', self::bodyText());
        $payFrom = self::selectLabels('sourceId');
        self::assertContains('Wallet', $payFrom);
        self::assertContains('Empty jar', $payFrom);
        self::assertNotContains('Wallet - $85.00', $payFrom);
        $wing = self::billBucket('Wingstop');
        self::assertStringContainsString('Restaurants', $wing['text']);
        self::assertStringContainsString('$5.00', $wing['text']);
        self::assertSame('false', $wing['pressed']);
        $card = self::accountCard('Camp card');
        self::assertStringContainsString('Card balance', self::bodyText());
        self::assertStringContainsString('$20.00', $card['text']);
        self::assertStringContainsString('Camp bank', $card['text']);
        self::assertStringContainsString('credit', $card['text']);
        self::assertStringContainsString('Autopay full', $card['text']);
        self::assertStringContainsString('APR 19.99%', $card['text']);
        self::assertStringContainsString('Credit limit $500.00', $card['text']);
        self::assertStringContainsString('Ada', $card['text']);
        self::assertSame('', $card['budget']);
        $payAmount = self::moneyField('amount');
        self::assertSame('$', $payAmount['mark']);
        self::assertSame('20.00', $payAmount['value']);
        self::assertNull(self::driver()->executeScript('return document.querySelector(\'input[name="includeCash"]\');'));

        $empty = self::tank();
        self::assertSame('short', $empty['fill']);
        self::assertSame('0.00', $empty['level']);
        self::assertStringContainsString('Buckets cover part of this payment', $empty['text']);
        self::assertStringContainsString('Payment', $empty['text']);
        self::assertStringNotContainsString('Short of the total due', $empty['text']);
        self::assertSame('false', $empty['snacks']);

        self::choose('sourceId', 'Wallet');
        self::assertSame('20.00', self::moneyField('amount')['value']);
        self::assertStringNotContainsString(
            'You may now post a payment of $20.00 to Camp card from Wallet after hitting Confirm.',
            self::bodyText(),
        );
        self::assertSame('false', self::uncovered()['pressed']);
        self::assertStringContainsString('$20.00', self::uncovered()['text']);
        self::assertNull(self::driver()->executeScript('return document.querySelector(\'[data-uncovered] input\');'));
        self::assertFalse(self::reviewEnabled());
        self::clickCss('button[data-uncovered]');
        $covered = self::waitForTank('full', '1.00');
        self::assertStringContainsString('This payment is covered', $covered['text']);
        self::assertSame('20.00', self::moneyField('amount')['value']);
        self::assertSame('true', self::uncovered()['pressed']);
        self::assertTrue(self::reviewEnabled());
        self::click('Review');
        $unmarked = self::dialogText();
        self::assertStringContainsString('Pay this card?', $unmarked);
        self::assertStringContainsString(
            'You may now post a payment of $20.00 to Camp card from Wallet after hitting Confirm.',
            $unmarked,
        );
        self::assertStringNotContainsString('Marked paid', $unmarked);
        self::assertStringNotContainsString('This cash is included', $unmarked);
        self::assertStringNotContainsString('Include this cash', $unmarked);
        self::assertStringNotContainsString('Unassigned cash covers', $unmarked);
        self::assertStringContainsString('$20.00', $unmarked);
        self::assertStringContainsString('After $65.00', $unmarked);
        self::assertStringContainsString('After $0.00', $unmarked);
        self::assertStringContainsString('Beyond the buckets $20.00', $unmarked);
        self::assertStringContainsString('This is the rest of the same payment.', $unmarked);
        self::assertSame(['cash', 'credit'], self::dialogTypes());
        self::assertTrue(self::dialogHasStream());
        self::click('Back');
        self::waitUntilDialogClosed();
        self::clickCss('button[data-uncovered]');
        self::waitForTank('short', '0.00');
        self::assertFalse(self::reviewEnabled());

        self::clickCss('button[data-bill="Wingstop"]');
        self::assertSame('20.00', self::moneyField('amount')['value']);
        $wingOnly = self::waitForTank('short', '0.25');
        self::assertSame('true', self::billBucket('Wingstop')['pressed']);
        self::assertSame('0.25', $wingOnly['level']);
        self::assertStringContainsString('$15.00', self::uncovered()['text']);
        self::clickCss('button[data-uncovered]');
        self::waitForTank('full', '1.00');
        self::assertSame('20.00', self::moneyField('amount')['value']);
        self::clickCss('button[data-uncovered]');
        self::waitForTank('short', '0.25');

        self::clickCss('button[data-bill="Trail snacks"]');
        self::assertSame('20.00', self::moneyField('amount')['value']);
        $partial = self::waitForTank('short', '0.85');
        self::assertSame('true', $partial['snacks']);
        self::assertSame('false', $partial['rinse']);
        self::assertTrue(self::billDisabled('Rinse'));
        self::assertStringContainsString('Restaurants', self::bodyText());
        self::assertStringContainsString('$12.00', self::bodyText());

        self::click('Review');
        $held = self::dialogText();
        self::assertStringContainsString('Marked paid', $held);
        self::assertStringContainsString('Wingstop', $held);
        self::assertStringContainsString('Trail snacks', $held);
        self::assertStringNotContainsString('Rinse', $held);
        self::assertStringContainsString('$20.00', $held);
        self::assertStringContainsString('After $65.00', $held);
        self::assertStringContainsString('After $0.00', $held);
        self::assertStringContainsString('Beyond the buckets $3.00', $held);
        self::assertStringContainsString('This is the rest of the same payment.', $held);
        self::assertStringContainsString('That does not move more cash.', $held);
        self::assertTrue(self::dialogHasStream());
        self::assertTrue(self::confirmEnabled());
        self::click('Back');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$20.00', self::accountCard('Camp card')['text']);
        self::assertStringContainsString('$85.00', self::accountCard('Wallet')['text']);

        self::clickCss('button[data-bill="Wingstop"]');
        self::clickCss('button[data-bill="Trail snacks"]');
        self::waitForTank('short', '0.00');
        self::clickCss('button[data-bill="Trail snacks"]');
        self::clickCss('button[data-bill="Rinse"]');
        $full = self::waitForTank('full', '1.00');
        self::assertStringContainsString('Buckets cover this payment', $full['text']);
        self::assertSame('true', $full['snacks']);
        self::assertSame('true', $full['rinse']);
        self::assertTrue(self::billDisabled('Wingstop'));
        self::assertSame('20.00', self::moneyField('amount')['value']);

        self::click('Review');
        $paying = self::dialogText();
        self::assertStringContainsString('Pay this card?', $paying);
        self::assertStringContainsString('Marked paid', $paying);
        self::assertStringContainsString('Trail snacks', $paying);
        self::assertStringContainsString('Rinse', $paying);
        self::assertStringNotContainsString('Wingstop', $paying);
        self::assertStringContainsString('Wallet', $paying);
        self::assertStringContainsString('Camp card', $paying);
        self::assertStringContainsString('Camp bank', $paying);
        self::assertStringContainsString('$20.00', $paying);
        self::assertStringContainsString('After $65.00', $paying);
        self::assertStringContainsString('After $0.00', $paying);
        self::assertStringContainsString('Available to assign stays the same.', $paying);
        self::assertStringNotContainsString('This is the rest of the same payment.', $paying);
        self::assertStringNotContainsString('Unassigned cash covers', $paying);
        self::assertStringNotContainsString('This cash is included', $paying);
        self::assertSame(['cash', 'credit'], self::dialogTypes());
        self::assertTrue(self::dialogHasStream());
        $paidSquares = self::dialogSquares();
        self::assertSame($paidSquares['heights'][0], $paidSquares['heights'][1]);
        self::assertSame($paidSquares['tops'][0], $paidSquares['tops'][1]);
        self::assertGreaterThanOrEqual(0, $paidSquares['gap']);
        self::assertLessThanOrEqual(16, $paidSquares['gap']);
        self::assertFalse($paidSquares['overlaps']);
        self::assertTrue($paidSquares['between']);
        self::assertTrue($paidSquares['aligned']);
        self::assertTrue($paidSquares['glyphs']);
        self::assertTrue(self::confirmEnabled());
        self::click('Back');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$20.00', self::accountCard('Camp card')['text']);
        self::assertStringContainsString('$85.00', self::accountCard('Wallet')['text']);
        self::assertSame('true', self::tank()['snacks']);

        self::click('Review');
        self::dialogText();
        self::click('Confirm');
        self::waitForText('Paid.');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$0.00', self::accountCard('Camp card')['text']);
        self::assertStringContainsString('$65.00', self::accountCard('Wallet')['text']);
        self::waitForText('You may now post a payment of $20.00 to Camp card from Wallet after hitting Confirm.');
        self::click('Ok');
        self::waitUntilDialogClosed();
        self::waitForTextGone('Trail snacks');
        self::waitForTextGone('Rinse');
        self::assertStringContainsString('Wingstop', self::bodyText());
        self::assertStringNotContainsString('Late supper', self::bodyText());

        self::choose('cardId', 'Bare card - $10.00');
        self::waitForText('No unpaid bill is drawn from this card.');
        self::assertStringNotContainsString('No past unpaid bill is drawn from this card.', self::bodyText());
    }

    private static function seedCard(): void
    {
        $seeded = self::driver()->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            const accept = { Accept: 'application/json' };
            fetch('/api/session', { headers: accept, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((session) => {
                    const headers = {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': session.csrfToken,
                    };
                    const post = (path, body) => fetch(path, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers,
                        body: JSON.stringify(body),
                    }).then(async (response) => ({ ok: response.ok, status: response.status, body: await response.json() }));
                    return post('/api/accounts', {
                        name: 'Camp card',
                        type: 'credit',
                        balanceMinor: 2000,
                        autopayChoice: 'balance',
                        bankAutopay: true,
                        dueDay: 12,
                        institution: 'Camp bank',
                        holder: 'Ada',
                        creditLimitMinor: 50000,
                        interestBps: 1999,
                    }).then(async (card) => {
                        if (!card.ok) return { ok: false, step: 'card', card };
                        const accountId = card.body.account.id;
                        const now = new Date();
                        const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
                        const snacks = await post('/api/bills', {
                            name: 'Trail snacks',
                            type: 'restaurants',
                            accountId,
                            amountMinor: 1200,
                            recurrence: 'once',
                            nextDueDate: '2020-01-15',
                            autopay: false,
                        });
                        const rinse = await post('/api/bills', {
                            name: 'Rinse',
                            type: 'laundry',
                            accountId,
                            amountMinor: 800,
                            recurrence: 'once',
                            nextDueDate: '2020-01-16',
                            autopay: false,
                        });
                        const supper = await post('/api/bills', {
                            name: 'Late supper',
                            type: 'restaurants',
                            accountId,
                            amountMinor: 5000,
                            recurrence: 'once',
                            nextDueDate: '2027-06-15',
                            autopay: false,
                        });
                        const wing = await post('/api/bills', {
                            name: 'Wingstop',
                            type: 'restaurants',
                            accountId,
                            amountMinor: 500,
                            recurrence: 'once',
                            nextDueDate: today,
                            autopay: false,
                        });
                        const bare = await post('/api/accounts', {
                            name: 'Bare card',
                            type: 'credit',
                            balanceMinor: 1000,
                            autopayChoice: 'balance',
                            bankAutopay: true,
                            dueDay: 3,
                        });
                        const zero = await post('/api/accounts', {
                            name: 'Zero card',
                            type: 'credit',
                            balanceMinor: 0,
                            autopayChoice: 'balance',
                            bankAutopay: true,
                            dueDay: 4,
                        });
                        const empty = await post('/api/accounts', {
                            name: 'Empty jar',
                            type: 'cash',
                            balanceMinor: 0,
                        });
                        return { ok: snacks.ok && rinse.ok && supper.ok && wing.ok && bare.ok && zero.ok && empty.ok, snacks, rinse, supper, wing, bare, zero, empty };
                    });
                })
                .then((result) => done(result))
                .catch((err) => done({ ok: false, message: String(err) }));
        JS);
        self::assertIsArray($seeded);
        self::assertTrue($seeded['ok'] === true, json_encode($seeded));
    }

    /** @return array{text:string,budget:string,inBudget:string} */
    private static function accountCard(string $name): array
    {
        $facts = self::wait()->until(function () use ($name): array|false {
            $found = self::driver()->executeScript(<<<'JS'
                const card = document.querySelector(`[data-account="${arguments[0]}"]`);
                if (!card) return null;
                const budget = card.querySelector('.account-budget');
                return {
                    text: card.innerText,
                    budget: budget ? budget.getAttribute('aria-label') : '',
                    inBudget: card.dataset.inBudget || '',
                };
            JS, [$name]);

            return is_array($found) ? $found : false;
        });
        self::assertIsArray($facts);

        return $facts;
    }

    /** @return array{text:string,pressed:string} */
    private static function billBucket(string $name): array
    {
        $facts = self::driver()->executeScript(<<<'JS'
            const button = document.querySelector(`button[data-bill="${arguments[0]}"]`);
            if (!button) return null;
            return {
                text: button.innerText,
                pressed: button.getAttribute('aria-pressed') || '',
            };
        JS, [$name]);
        self::assertIsArray($facts);

        return $facts;
    }

    /** @return array{fill:string,level:string,text:string,snacks:string,rinse:string} */
    private static function tank(): array
    {
        $facts = self::driver()->executeScript(<<<'JS'
            const tank = document.querySelector('.card-tank');
            const pressed = (name) => {
                const button = document.querySelector(`button[data-bill="${name}"]`);
                return button ? button.getAttribute('aria-pressed') : '';
            };
            if (!tank) return null;
            return {
                fill: tank.dataset.fill || '',
                level: tank.dataset.level || '',
                text: tank.innerText,
                snacks: pressed('Trail snacks'),
                rinse: pressed('Rinse'),
            };
        JS);
        self::assertIsArray($facts);

        return $facts;
    }

    /** @return array{value:?string,mark:string} */
    private static function moneyField(string $name): array
    {
        $facts = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector(`input[name="${arguments[0]}"]`);
            const mark = input ? input.closest('.money-input')?.querySelector('.money-mark') : null;
            return { value: input ? input.value : null, mark: mark ? mark.textContent : '' };
        JS, [$name]);
        self::assertIsArray($facts);

        return $facts;
    }

    /** @return list<string> */
    private static function selectLabels(string $name): array
    {
        self::wait()->until(function () use ($name): bool {
            $ready = self::driver()->executeScript(
                'return !!document.querySelector(arguments[0]);',
                ['select[name="' . $name . '"]'],
            );

            return $ready === true;
        });
        $labels = self::driver()->executeScript(
            'const select = document.querySelector(arguments[0]); return [...select.options].map((option) => option.textContent.trim());',
            ['select[name="' . $name . '"]'],
        );
        self::assertIsArray($labels);

        return array_values($labels);
    }

    /** @return array{cardBeforeSource:bool,sourceBeforeBuckets:bool,bucketsBeforeAmount:bool} */
    private static function payOrder(): array
    {
        $order = self::driver()->executeScript(<<<'JS'
            const card = document.querySelector('[data-account="Camp card"]');
            const board = document.querySelector('.pull-board');
            const source = document.querySelector('select[name="sourceId"]');
            const amount = document.querySelector('input[name="amount"]');
            const follows = (first, second) => !!(first && second && (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING));
            return {
                cardBeforeSource: follows(card, source),
                sourceBeforeBuckets: follows(source, board),
                bucketsBeforeAmount: follows(board, amount),
            };
        JS);
        self::assertIsArray($order);

        return $order;
    }

    /** @return array{pressed:?string,text:string} */
    private static function uncovered(): array
    {
        $card = self::driver()->executeScript(<<<'JS'
            const button = document.querySelector('button[data-uncovered]');
            if (!button) return null;
            return { pressed: button.getAttribute('aria-pressed'), text: button.innerText };
        JS);
        self::assertIsArray($card);

        return $card;
    }

    private static function billDisabled(string $name): bool
    {
        $disabled = self::driver()->executeScript(
            'const button = document.querySelector(arguments[0]); return button ? button.disabled : null;',
            ['button[data-bill="' . $name . '"]'],
        );

        return $disabled === true;
    }

    private static function waitForAmount(string $name, string $value): void
    {
        self::wait()->until(function () use ($name, $value): bool {
            $current = self::driver()->executeScript(
                'const input = document.querySelector(arguments[0]); return input ? input.value : null;',
                ['input[name="' . $name . '"]'],
            );

            return $current === $value;
        });
    }

    /** @return array{fill:string,level:string,text:string,snacks:string,rinse:string} */
    private static function waitForTank(string $fill, string $level): array
    {
        $facts = self::wait()->until(function () use ($fill, $level): array|false {
            $tank = self::driver()->executeScript(<<<'JS'
                const tank = document.querySelector('.card-tank');
                const pressed = (name) => {
                    const button = document.querySelector(`button[data-bill="${name}"]`);
                    return button ? button.getAttribute('aria-pressed') : '';
                };
                if (!tank) return null;
                return {
                    fill: tank.dataset.fill || '',
                    level: tank.dataset.level || '',
                    text: tank.innerText,
                    snacks: pressed('Trail snacks'),
                    rinse: pressed('Rinse'),
                };
            JS);
            if (!is_array($tank)) {
                return false;
            }

            return $tank['fill'] === $fill && $tank['level'] === $level ? $tank : false;
        });
        self::assertIsArray($facts);

        return $facts;
    }

    private static function dialogText(): string
    {
        self::wait()->until(function (): bool {
            $open = self::driver()->executeScript(
                'const dialog = document.querySelector("dialog.modal"); return !!(dialog && dialog.open);',
            );

            return $open === true;
        });
        $text = self::driver()->executeScript('return document.querySelector("dialog.modal").innerText;');
        self::assertIsString($text);

        return $text;
    }

    /** @return list<string> */
    private static function dialogTypes(): array
    {
        $types = self::driver()->executeScript(
            'return [...document.querySelector("dialog.modal").querySelectorAll(".transfer-end")].map((node) => node.dataset.type);',
        );
        self::assertIsArray($types);

        return array_values($types);
    }

    /** @return array{heights: list<int>, tops: list<int>, round: string, join: string, gap: int, arrowHeight: int, gradient: string, overlaps: bool, between: bool, aligned: bool, glyphs: bool} */
    private static function dialogSquares(): array
    {
        $facts = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal');
            const ends = [...dialog.querySelectorAll('.transfer-end')];
            const arrow = dialog.querySelector('.transfer-arrow');
            const head = arrow ? arrow.querySelector('path[stroke-linejoin]') : null;
            const amount = dialog.querySelector('.transfer-amount');
            const arrowBox = arrow.getBoundingClientRect();
            const amountBox = amount.getBoundingClientRect();
            const boxes = ends.map((node) => node.getBoundingClientRect());
            const hits = (box) => amountBox.left < box.right - 1
                && amountBox.right > box.left + 1
                && amountBox.top < box.bottom - 1
                && amountBox.bottom > box.top + 1;
            const left = boxes[0];
            const right = boxes[1];
            return {
                heights: boxes.map((box) => Math.round(box.height)),
                tops: boxes.map((box) => Math.round(box.top)),
                round: head ? head.getAttribute('stroke-linecap') : '',
                join: head ? head.getAttribute('stroke-linejoin') : '',
                gap: Math.round(amountBox.top - arrowBox.bottom),
                arrowHeight: Math.round(arrowBox.height),
                gradient: arrow ? arrow.innerHTML : '',
                overlaps: boxes.some(hits),
                between: !!left && !!right && amountBox.left >= left.right - 1 && amountBox.right <= right.left + 1,
                aligned: Math.abs(((arrowBox.left + arrowBox.right) / 2) - ((amountBox.left + amountBox.right) / 2)) <= 2,
                glyphs: ends.length === 2 && ends.every((node) => {
                    const glyph = node.querySelector('.glyph');
                    return !!glyph && !glyph.classList.contains('mini');
                }),
            };
        JS);
        self::assertIsArray($facts);

        return $facts;
    }

    private static function dialogHasStream(): bool
    {
        $found = self::driver()->executeScript(
            'return !!document.querySelector("dialog.modal .transfer-stream");',
        );

        return $found === true;
    }

    private static function reviewEnabled(): bool
    {
        $enabled = self::driver()->executeScript(<<<'JS'
            const button = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Review');
            return !!(button && !button.disabled);
        JS);

        return $enabled === true;
    }

    private static function confirmEnabled(): bool
    {
        $enabled = self::driver()->executeScript(<<<'JS'
            const button = [...document.querySelectorAll('dialog.modal button')].find((node) => node.textContent.trim() === 'Confirm');
            return !!(button && !button.disabled);
        JS);

        return $enabled === true;
    }

    private static function waitUntilDialogClosed(): void
    {
        self::wait()->until(function (): bool {
            $open = self::driver()->executeScript(
                'return [...document.querySelectorAll("dialog.modal")].some((dialog) => dialog.open);',
            );

            return $open === false;
        });
    }

    private static function bodyText(): string
    {
        return self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();
    }

    private static function choose(string $name, string $label): void
    {
        self::wait()->until(function () use ($name, $label): bool {
            $ready = self::driver()->executeScript(
                'const select = document.querySelector(arguments[0]); if (!select) return false; return [...select.options].some((option) => option.textContent.trim() === arguments[1]);',
                ['select[name="' . $name . '"]', $label],
            );

            return $ready === true;
        });
        $element = self::driver()->findElement(WebDriverBy::name($name));
        (new WebDriverSelect($element))->selectByVisibleText($label);
    }

    private static function clickLink(string $label): void
    {
        $by = WebDriverBy::linkText($label);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function click(string $label): void
    {
        $by = WebDriverBy::xpath('//button[normalize-space()=' . self::quote($label) . ']');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function clickCss(string $selector): void
    {
        $by = WebDriverBy::cssSelector($selector);
        $element = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$element]);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function type(string $name, string $value): void
    {
        $by = WebDriverBy::name($name);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        $element->clear();
        $element->sendKeys($value);
    }

    private static function replace(string $name, string $value): void
    {
        $by = WebDriverBy::name($name);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$element]);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        $element->click();
        self::driver()->executeScript(
            <<<'JS'
                const input = arguments[0];
                const value = arguments[1];
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
                setter.call(input, value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                for (const symbol of Object.getOwnPropertySymbols(input)) {
                    const map = input[symbol];
                    if (map && typeof map.input === 'function') {
                        map.input.call(input, { currentTarget: input, target: input });
                    }
                }
            JS,
            [$element, $value],
        );
        self::wait()->until(function () use ($name, $value): bool {
            $current = self::driver()->executeScript(
                'const input = document.querySelector(arguments[0]); return input ? input.value : null;',
                ['input[name="' . $name . '"]'],
            );

            return $current === $value;
        });
    }

    private static function waitForText(string $text): void
    {
        $body = self::wait()->until(function () use ($text): string|false {
            $body = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();

            return stripos($body, $text) !== false ? $body : false;
        });
        self::assertIsString($body);
        self::assertNotFalse(stripos($body, $text));
    }

    private static function waitForTextGone(string $text): void
    {
        $body = self::wait()->until(function () use ($text): string|false {
            $body = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();

            return stripos($body, $text) === false ? $body : false;
        });
        self::assertIsString($body);
        self::assertFalse(stripos($body, $text));
    }

    private static function wait(): WebDriverWait
    {
        return new WebDriverWait(self::driver(), 20);
    }

    private static function driver(): ChromeDriver
    {
        if (!self::$driver instanceof ChromeDriver) {
            throw new RuntimeException('Chrome is not running.');
        }

        return self::$driver;
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace('"', '', $value) . '"';
    }

    private static function startServer(string $root, int $port): void
    {
        $log = $root . '/var/browser-server.log';
        if (!is_dir($root . '/var')) {
            mkdir($root . '/var', 0775, true);
        }
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['FINANCE_DB'] = self::$database;
        $env['FINANCE_RATE_FEED'] = dirname(__DIR__, 2) . '/tests/fixtures/rate-feed-usd.json';
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            $env,
        );
        if (!is_resource(self::$server)) {
            throw new RuntimeException('Could not start the PHP server.');
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }

        throw new RuntimeException('The PHP server did not start. See var/browser-server.log.');
    }

    private static function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $match)) {
            throw new RuntimeException('Could not reserve a port.');
        }

        return (int) $match[1];
    }

    private static function shutdown(): void
    {
        if (self::$driver instanceof ChromeDriver) {
            try {
                self::$driver->quit();
            } catch (\Throwable) {
            }
            self::$driver = null;
        }
        if (self::$service instanceof ChromeDriverService) {
            try {
                self::$service->stop();
            } catch (\Throwable) {
            }
            self::$service = null;
        }
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$database !== '' && is_file(self::$database)) {
            unlink(self::$database);
        }
    }
}
