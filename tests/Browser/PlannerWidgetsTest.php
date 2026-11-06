<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\JsonWireCompat;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlannerWidgetsTest extends TestCase
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

    public function testThePlannerShowsTheSixWidgetsAndKeepsAMove(): void
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
        self::waitForText('Weekly Payor');

        $seen = self::driver()->executeScript(<<<'JS'
            const text = (selector) => {
                const node = document.querySelector(selector);
                return node ? node.innerText.replace(/\s+/g, ' ').trim() : '';
            };
            const box = (selector) => {
                const node = document.querySelector(selector);
                if (!node) return null;
                const rect = node.getBoundingClientRect();
                return { top: rect.top, left: rect.left, width: rect.width, height: rect.height };
            };
            const money = document.querySelector('[data-account-type="credit"] .money');
            const checking = document.querySelector('[data-account-type="checking"] .money');
            const pie = document.querySelector('svg.bill-pie');
            const pieBox = pie ? pie.getBoundingClientRect() : null;
            const figure = document.querySelector('.planner-net .figure');
            const now = new Date();
            const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            return {
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
                widgets: [...document.querySelectorAll('[data-widget]')].map((node) => node.dataset.widget),
                income: text('[data-section="income"]'),
                kicker: text('[data-section="income"] .kicker'),
                day: text('[data-section="calendar"] strong'),
                calendar: text('[data-section="calendar"]'),
                dayShare: (() => {
                    const day = box('.planner-day');
                    const widget = box('[data-section="calendar"]');
                    return day && widget && widget.height > 0 ? day.height / widget.height : 0;
                })(),
                assign: document.querySelector('[data-section="envelopes"] a[href="/envelopes"]') !== null,
                expectedDay: `${weekdays[now.getDay()]} ${now.getDate()}`,
                trips: text('[data-section="trips"]'),
                envelopes: text('[data-section="envelopes"]'),
                accounts: text('[data-section="accounts"]'),
                bills: text('[data-section="bills"]'),
                pies: document.querySelectorAll('svg.bill-pie').length,
                pieWidth: pieBox ? pieBox.width : 0,
                credit: money ? getComputedStyle(money).color : '',
                checking: checking ? getComputedStyle(checking).color : '',
                creditClass: money ? money.className : '',
                figureChildren: figure ? figure.childElementCount : -1,
                net: box('.planner-net'),
                incomeBox: box('[data-section="income"]'),
                calendarBox: box('[data-section="calendar"]'),
                tripBox: box('[data-section="trips"]'),
                accountBox: box('[data-section="accounts"]'),
            };
        JS);
        self::assertIsArray($seen);
        self::assertSame(
            ['income', 'calendar', 'trips', 'accounts', 'envelopes', 'bills'],
            $seen['widgets'],
        );
        self::assertSame('income,calendar,trips,accounts,envelopes,bills', $seen['order']);
        self::assertStringContainsString('Weekly Payor', $seen['income']);
        self::assertStringContainsString('Nov 8, 2026', $seen['income']);
        self::assertStringContainsString('$1,000.00', $seen['income']);
        self::assertStringContainsString('8 payors', $seen['income']);
        self::assertSame('income', strtolower((string) $seen['kicker']));
        self::assertStringContainsString('Still coming in', $seen['income']);
        self::assertStringContainsString('$17,500.00', $seen['income']);
        self::assertSame($seen['expectedDay'], $seen['day']);
        self::assertStringContainsStringIgnoringCase('Calendar', (string) $seen['calendar']);
        self::assertGreaterThan(0.5, $seen['dayShare']);
        self::assertStringContainsStringIgnoringCase('Trip', (string) $seen['trips']);
        self::assertStringNotContainsString('Trips', (string) $seen['trips']);
        self::assertStringContainsString('No current stay', $seen['trips']);
        self::assertStringContainsStringIgnoringCase('Accounts', (string) $seen['accounts']);
        self::assertStringContainsStringIgnoringCase('Envelopes', (string) $seen['envelopes']);
        self::assertStringContainsStringIgnoringCase('Assigned', $seen['envelopes']);
        self::assertStringContainsStringIgnoringCase('Max', $seen['envelopes']);
        self::assertFalse($seen['assign']);
        self::assertStringContainsString('50%', $seen['envelopes']);
        self::assertStringContainsString('30%', $seen['envelopes']);
        self::assertStringContainsString('20%', $seen['envelopes']);
        self::assertStringContainsString('Needs', $seen['envelopes']);
        self::assertStringContainsString('Available to assign', $seen['envelopes']);
        $accounts = strtolower((string) $seen['accounts']);
        self::assertStringContainsString('checking', $accounts);
        self::assertStringContainsString('$2,490.00', $accounts);
        self::assertStringContainsString('savings', $accounts);
        self::assertStringContainsString('$10,200.00', $accounts);
        self::assertStringContainsString('credit', $accounts);
        self::assertStringContainsString('$400.00', $accounts);
        self::assertStringContainsString('loan', $accounts);
        self::assertStringContainsString('$5,000.00', $accounts);
        self::assertStringContainsString('fuel', $accounts);
        self::assertStringContainsString('$80.00', $accounts);
        self::assertStringContainsString('coral', $seen['creditClass']);
        self::assertNotSame($seen['checking'], $seen['credit']);
        self::assertStringContainsString('rent', $seen['bills']);
        self::assertSame(1, $seen['pies']);
        self::assertGreaterThan(0, $seen['pieWidth']);
        self::assertLessThan(200, $seen['pieWidth']);
        self::assertSame(0, $seen['figureChildren']);
        self::assertStringNotContainsString('USD', $seen['income'] . $seen['accounts'] . $seen['envelopes'] . $seen['bills']);
        self::assertGreaterThan($seen['net']['left'] + $seen['net']['width'] - 8, $seen['incomeBox']['left']);
        self::assertEqualsWithDelta($seen['calendarBox']['top'], $seen['tripBox']['top'], 4);
        self::assertEqualsWithDelta($seen['calendarBox']['top'], $seen['accountBox']['top'], 4);
        self::assertGreaterThan($seen['calendarBox']['left'], $seen['tripBox']['left']);
        self::assertGreaterThan($seen['tripBox']['left'], $seen['accountBox']['left']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 900));
        $stacked = self::driver()->executeScript(<<<'JS'
            const box = (selector) => document.querySelector(selector).getBoundingClientRect();
            const calendar = box('[data-section="calendar"]');
            const trips = box('[data-section="trips"]');
            const accounts = box('[data-section="accounts"]');
            return { calendar: calendar.top, trips: trips.top, accounts: accounts.top, height: calendar.height };
        JS);
        self::assertIsArray($stacked);
        self::assertGreaterThan($stacked['calendar'] + $stacked['height'] - 2, $stacked['trips']);
        self::assertGreaterThan($stacked['trips'], $stacked['accounts']);
        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));

        $grab = self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="bills"] .planner-grab'));
        $income = self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="income"]'));
        self::dragOnto($grab, $income);
        self::wait()->until(function (): bool {
            $saved = self::driver()->executeScript(
                'return document.querySelector(".planner-board")?.dataset.saved ?? ""',
            );

            return is_string($saved) && str_starts_with($saved, 'bills,');
        });

        self::driver()->navigate()->refresh();
        self::waitForText('Weekly Payor');
        $kept = self::driver()->executeScript(<<<'JS'
            return {
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
                widgets: [...document.querySelectorAll('[data-widget]')].map((node) => node.dataset.widget),
                income: document.querySelector('[data-section="income"]')?.innerText ?? '',
                pie: document.querySelectorAll('svg.bill-pie').length,
            };
        JS);
        self::assertIsArray($kept);
        self::assertSame(
            ['bills', 'income', 'calendar', 'trips', 'accounts', 'envelopes'],
            $kept['widgets'],
        );
        self::assertSame('bills,income,calendar,trips,accounts,envelopes', $kept['order']);
        self::assertStringContainsString('Weekly Payor', $kept['income']);
        self::assertSame(1, $kept['pie']);

        // Income is half beside net worth. Calendar, Trips, and Accounts are single, three across.
        // Envelopes and Bills each take the row.
        $defaults = self::driver()->executeScript(<<<'JS'
            const span = (id) => document.querySelector(`[data-widget="${id}"]`)?.dataset.span ?? '';
            return {
                income: span('income'),
                calendar: span('calendar'),
                trips: span('trips'),
                accounts: span('accounts'),
                envelopes: span('envelopes'),
                bills: span('bills'),
                wrenches: document.querySelectorAll('.planner-size').length,
                netWrench: document.querySelector('.planner-net .planner-size') !== null,
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
            };
        JS);
        self::assertIsArray($defaults);
        self::assertSame('3', $defaults['income']);
        self::assertSame('2', $defaults['calendar']);
        self::assertSame('2', $defaults['trips']);
        self::assertSame('2', $defaults['accounts']);
        self::assertSame('6', $defaults['envelopes']);
        self::assertSame('6', $defaults['bills']);
        self::assertSame(6, $defaults['wrenches']);
        self::assertFalse($defaults['netWrench']);

        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="income"] .planner-size'))->click();
        self::wait()->until(function (): bool {
            $text = self::driver()->executeScript(
                'return document.querySelector("dialog.modal[open]")?.innerText ?? ""',
            );

            return is_string($text)
                && str_contains($text, 'Size Income')
                && str_contains($text, 'Single')
                && str_contains($text, 'Half')
                && str_contains($text, 'Full');
        });
        $selected = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('dialog.modal[open] input[value="3"]');
            return {
                checked: input?.checked === true,
                on: input?.closest('label')?.classList.contains('on') === true,
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
            };
        JS);
        self::assertIsArray($selected);
        self::assertTrue($selected['checked']);
        self::assertTrue($selected['on']);
        self::assertSame($defaults['order'], $selected['order']);

        self::chooseSpan('Full');
        $full = self::waitForSpan('income', 6);
        self::assertEqualsWithDelta((float) $full['board'], (float) $full['width'], 2.0);

        self::chooseSpan('Single');
        $single = self::waitForSpan('income', 2);
        self::assertLessThan((float) $single['board'] * 0.4, (float) $single['width']);

        self::chooseSpan('Half');
        $half = self::waitForSpan('income', 3);
        self::assertGreaterThan((float) $half['board'] * 0.4, (float) $half['width']);
        self::assertLessThan((float) $half['board'] * 0.6, (float) $half['width']);

        $inside = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            const rect = dialog.getBoundingClientRect();
            return { x: Math.round(rect.left + rect.width / 2), y: Math.round(rect.top + 28) };
        JS);
        self::assertIsArray($inside);
        self::pointerSweep((int) $inside['x'], (int) $inside['y'], 8, 8);
        self::assertTrue(self::driver()->executeScript('return document.querySelector("dialog.modal[open]") !== null') === true);
        self::pointerClick(8, 8);
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );

        self::driver()->navigate()->refresh();
        self::waitForText('Weekly Payor');
        $restored = self::driver()->executeScript(<<<'JS'
            return {
                span: document.querySelector('[data-widget="income"]')?.dataset.span ?? '',
                column: getComputedStyle(document.querySelector('[data-widget="income"]')).gridColumn,
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
            };
        JS);
        self::assertIsArray($restored);
        self::assertSame('3', $restored['span']);
        self::assertMatchesRegularExpression('/span\s+3(?!\d)/', (string) $restored['column']);
        self::assertSame('bills,income,calendar,trips,accounts,envelopes', $restored['order']);
    }

    private static function chooseSpan(string $label): void
    {
        $by = WebDriverBy::xpath(
            '//dialog[contains(@class,"modal") and @open]//label[normalize-space()=' . self::quote($label) . ']',
        );
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    /** @return array<string, mixed> */
    private static function waitForSpan(string $widget, int $span): array
    {
        $seen = self::wait()->until(function () use ($widget, $span): array|false {
            $read = self::driver()->executeScript(<<<'JS'
                const widget = arguments[0];
                const node = document.querySelector(`[data-widget="${widget}"]`);
                const board = document.querySelector('.planner-board');
                if (!node || !board) return null;
                return {
                    span: node.dataset.span ?? '',
                    column: getComputedStyle(node).gridColumn,
                    width: node.getBoundingClientRect().width,
                    board: board.getBoundingClientRect().width,
                    saved: board.dataset.spans ?? '',
                };
            JS, [$widget]);
            if (!is_array($read)) {
                return false;
            }
            if ((string) $read['span'] !== (string) $span) {
                return false;
            }
            if (!is_string($read['column']) || !preg_match('/span\s+' . $span . '(?!\d)/', $read['column'])) {
                return false;
            }
            if (!is_string($read['saved']) || !str_contains($read['saved'], $widget . ':' . $span)) {
                return false;
            }

            return $read;
        });
        self::assertIsArray($seen);

        return $seen;
    }

    private static function pointerClick(int $x, int $y): void
    {
        self::pointerPath([
            ['type' => 'pointerMove', 'duration' => 0, 'x' => $x, 'y' => $y, 'origin' => 'viewport'],
            ['type' => 'pointerDown', 'button' => 0],
            ['type' => 'pointerUp', 'button' => 0],
        ]);
    }

    private static function pointerSweep(int $x0, int $y0, int $x1, int $y1): void
    {
        self::pointerPath([
            ['type' => 'pointerMove', 'duration' => 0, 'x' => $x0, 'y' => $y0, 'origin' => 'viewport'],
            ['type' => 'pointerDown', 'button' => 0],
            ['type' => 'pointerMove', 'duration' => 80, 'x' => $x1, 'y' => $y1, 'origin' => 'viewport'],
            ['type' => 'pointerUp', 'button' => 0],
        ]);
    }

    /** @param list<array<string, mixed>> $actions */
    private static function pointerPath(array $actions): void
    {
        self::driver()->execute('actions', [
            'actions' => [[
                'type' => 'pointer',
                'id' => 'mouse',
                'parameters' => ['pointerType' => 'mouse'],
                'actions' => $actions,
            ]],
        ]);
    }

    private static function dragOnto(WebDriverElement $grab, WebDriverElement $target): void
    {
        $origin = static fn (WebDriverElement $element): array => [
            JsonWireCompat::WEB_DRIVER_ELEMENT_IDENTIFIER => $element->getID(),
        ];
        self::driver()->execute('actions', [
            'actions' => [[
                'type' => 'pointer',
                'id' => 'mouse',
                'parameters' => ['pointerType' => 'mouse'],
                'actions' => [
                    ['type' => 'pointerMove', 'duration' => 0, 'origin' => $origin($grab), 'x' => 0, 'y' => 0],
                    ['type' => 'pointerDown', 'button' => 0],
                    ['type' => 'pointerMove', 'duration' => 200, 'origin' => $origin($target), 'x' => 0, 'y' => 0],
                    ['type' => 'pointerUp', 'button' => 0],
                ],
            ]],
        ]);
    }

    private static function click(string $label): void
    {
        $by = WebDriverBy::xpath('//button[normalize-space()=' . self::quote($label) . ']');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function type(string $name, string $value): void
    {
        $element = self::wait()->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::name($name)),
        );
        $element->clear();
        $element->sendKeys($value);
    }

    private static function waitForText(string $text): void
    {
        $body = self::wait()->until(function () use ($text): string|false {
            $body = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();

            return stripos($body, $text) !== false ? $body : false;
        });
        self::assertIsString($body);
    }

    private static function wait(): WebDriverWait
    {
        return new WebDriverWait(self::driver(), 15);
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
