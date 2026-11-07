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
        self::choose('moveSource', 'Everyday checking');
        $checking = self::accountCard('Everyday checking');
        self::assertStringContainsString('checking', $checking['text']);
        self::assertStringContainsString('$3,180.00', $checking['text']);
        self::assertSame('yes', $checking['inBudget']);
        self::assertSame('Included in available to assign', $checking['budget']);

        self::choose('moveDestination', 'Emergency fund');
        $emergency = self::accountCard('Emergency fund');
        self::assertStringContainsString('savings', $emergency['text']);
        self::assertStringContainsString('$8,600.00', $emergency['text']);
        self::assertStringContainsString('APR 4.50%', $emergency['text']);
        self::assertSame('no', $emergency['inBudget']);
        self::assertSame('', $emergency['budget']);

        $amount = self::moneyField('moveAmount');
        self::assertSame('$', $amount['mark']);
        self::replace('moveAmount', '1.00');
        self::click('Review');
        $leaving = self::dialogText();
        self::assertStringContainsString('Move this money?', $leaving);
        self::assertStringContainsString('Everyday checking', $leaving);
        self::assertStringContainsString('Emergency fund', $leaving);
        self::assertStringContainsString('$1.00', $leaving);
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
        self::assertStringContainsString('--from', $squares['gradient']);
        self::assertStringContainsString('--to', $squares['gradient']);
        self::click('Back');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$3,180.00', self::accountCard('Everyday checking')['text']);
        self::assertStringContainsString('$8,600.00', self::accountCard('Emergency fund')['text']);

        self::choose('moveSource', 'Wallet');
        self::choose('moveDestination', 'Camp jar');
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
        self::assertTrue(self::confirmEnabled());
        self::click('Confirm');
        self::waitForText('Transferred.');
        self::waitUntilDialogClosed();
        self::assertStringContainsString('$85.00', self::accountCard('Wallet')['text']);
        self::assertStringContainsString('$141.00', self::accountCard('Camp jar')['text']);

        self::click('Pay a card');
        self::choose('cardId', 'Camp card - Camp bank (Ada)');
        self::waitForText('Trail snacks');
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

        $empty = self::tank();
        self::assertSame('short', $empty['fill']);
        self::assertSame('0.00', $empty['level']);
        self::assertStringContainsString('Short of the total due', $empty['text']);
        self::assertStringContainsString('Total due', $empty['text']);
        self::assertSame('false', $empty['snacks']);

        self::clickCss('button[data-bill="Trail snacks"]');
        $partial = self::tank();
        self::assertSame('short', $partial['fill']);
        self::assertSame('0.60', $partial['level']);
        self::assertSame('true', $partial['snacks']);
        self::assertSame('false', $partial['rinse']);
        self::assertStringContainsString('Restaurants', self::bodyText());
        self::assertStringContainsString('$12.00', self::bodyText());

        self::clickCss('button[data-bill="Rinse"]');
        $full = self::tank();
        self::assertSame('full', $full['fill']);
        self::assertSame('1.00', $full['level']);
        self::assertStringContainsString('The total due is covered', $full['text']);
        self::assertSame('true', $full['snacks']);
        self::assertSame('true', $full['rinse']);

        self::clickCss('button[data-bill="Trail snacks"]');
        $dropped = self::tank();
        self::assertSame('short', $dropped['fill']);
        self::assertSame('0.40', $dropped['level']);
        self::clickCss('button[data-bill="Trail snacks"]');
        self::assertSame('full', self::tank()['fill']);

        self::choose('sourceId', 'Wallet');
        self::assertStringContainsString('$85.00', self::accountCard('Wallet')['text']);
        self::click('Review');
        $paying = self::dialogText();
        self::assertStringContainsString('Pay this card?', $paying);
        self::assertStringContainsString('Marked paid', $paying);
        self::assertStringContainsString('Trail snacks', $paying);
        self::assertStringContainsString('Rinse', $paying);
        self::assertStringContainsString('Wallet', $paying);
        self::assertStringContainsString('Camp card', $paying);
        self::assertStringContainsString('$20.00', $paying);
        self::assertStringContainsString('After $65.00', $paying);
        self::assertStringContainsString('After $0.00', $paying);
        self::assertSame(['cash', 'credit'], self::dialogTypes());
        self::assertSame('full', self::dialogFill());
        self::assertTrue(self::dialogHasStream());
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
        self::waitForText('Nothing is due');
        self::assertStringContainsString('$0.00', self::accountCard('Camp card')['text']);
        self::assertStringContainsString('$65.00', self::accountCard('Wallet')['text']);
        self::waitForTextGone('Trail snacks');
        self::waitForTextGone('Rinse');
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
                        return { ok: snacks.ok && rinse.ok, snacks, rinse };
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

    /** @return array{heights: list<int>, tops: list<int>, round: string, join: string, gap: int, arrowHeight: int, gradient: string} */
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
            return {
                heights: ends.map((node) => Math.round(node.getBoundingClientRect().height)),
                tops: ends.map((node) => Math.round(node.getBoundingClientRect().top)),
                round: head ? head.getAttribute('stroke-linecap') : '',
                join: head ? head.getAttribute('stroke-linejoin') : '',
                gap: Math.round(amountBox.top - arrowBox.bottom),
                arrowHeight: Math.round(arrowBox.height),
                gradient: arrow ? arrow.innerHTML : '',
            };
        JS);
        self::assertIsArray($facts);

        return $facts;
    }

    private static function dialogFill(): string
    {
        $fill = self::driver()->executeScript(
            'const node = document.querySelector("dialog.modal .transfer-end.with-water"); return node ? node.dataset.fill : "";',
        );
        self::assertIsString($fill);

        return $fill;
    }

    private static function dialogHasStream(): bool
    {
        $found = self::driver()->executeScript(
            'return !!document.querySelector("dialog.modal .transfer-stream");',
        );

        return $found === true;
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
                'const dialog = document.querySelector("dialog.modal"); return dialog ? dialog.open : false;',
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
