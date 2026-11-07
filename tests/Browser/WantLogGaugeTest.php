<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WantLogGaugeTest extends TestCase
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

    public function testAWantLogShowsTheMatchingLockedBucketAndWarnsWhenTheAmountPassesMax(): void
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
        self::clickLink('New log');
        self::click('Want');
        self::waitForGauge();

        $restaurants = self::gauge();
        $books = self::lockedBooks();
        self::assertSame($books['restaurants']['name'], $restaurants['bucket']);
        self::assertSame($books['restaurants']['assigned'], $restaurants['assigned']);
        self::assertSame($books['restaurants']['max'], $restaurants['max']);
        self::assertStringContainsString('Assigned', $restaurants['text']);
        self::assertStringContainsString('Max', $restaurants['text']);
        self::assertStringContainsString($restaurants['assignedDollars'], $restaurants['text']);
        self::assertStringContainsString($restaurants['maxDollars'], $restaurants['text']);
        self::assertStringNotContainsString('USD', $restaurants['text']);
        self::assertEquals(0, $restaurants['preview']);
        self::assertSame($restaurants['assigned'] > $restaurants['max'] ? '1' : '0', $restaurants['over']);

        self::click('shopping');
        self::wait()->until(fn (): bool => self::gauge()['bucket'] === $books['shopping']['name']);
        $shopping = self::gauge();
        self::assertSame($books['shopping']['assigned'], $shopping['assigned']);
        self::assertSame($books['shopping']['max'], $shopping['max']);
        self::assertNotSame($restaurants['bucket'], $shopping['bucket']);

        self::click('entertainment');
        self::wait()->until(fn (): bool => self::gauge()['bucket'] === $books['entertainment']['name']);
        $entertainment = self::gauge();
        self::assertSame($books['entertainment']['assigned'], $entertainment['assigned']);
        self::assertSame($books['entertainment']['max'], $entertainment['max']);
        if ($entertainment['assigned'] === 0 && $entertainment['max'] === 0) {
            self::assertSame('1', $entertainment['neutral']);
            self::assertEquals(0, $entertainment['preview']);
            self::type('amount', '4');
            self::wait()->until(fn (): bool => self::gauge()['over'] === '1');
            $keyed = self::gauge();
            self::assertEquals(0, $keyed['preview']);
            self::assertStringContainsString('Over', $keyed['text']);
            self::assertStringContainsString('$4.00', $keyed['text']);
            self::clearAmount();
        }

        self::click('restaurants');
        self::wait()->until(fn (): bool => self::gauge()['bucket'] === $books['restaurants']['name']);
        $open = self::gauge();
        if ($open['max'] - $open['assigned'] < 2000) {
            $raised = $open['assigned'] + 10000;
            self::raiseMax($books['restaurants']['id'], $raised);
            self::driver()->navigate()->refresh();
            self::click('Want');
            self::wait()->until(fn (): bool => self::gauge()['max'] === $raised);
        }

        $roomy = self::gauge();
        self::assertGreaterThanOrEqual(2000, $roomy['max'] - $roomy['assigned']);
        self::type('amount', '10');
        self::wait()->until(fn (): bool => self::gauge()['preview'] > 4);
        $under = self::gauge();
        self::assertSame('0', $under['over']);
        self::assertGreaterThan(0, $under['preview']);
        self::assertStringContainsString('repeating-linear-gradient', $under['stripes']);
        self::assertNull($under['overAnimation']);
        self::assertStringNotContainsString('Over', $under['text']);

        self::type('amount', '500');
        self::wait()->until(function (): bool {
            $gauge = self::gauge();

            return $gauge['over'] === '1'
                && abs($gauge['track'] - ($gauge['assignedHeight'] + $gauge['preview'])) < 2;
        });
        $over = self::gauge();
        self::assertStringContainsString('Over', $over['text']);
        self::assertStringContainsString('repeating-linear-gradient', $over['stripes']);
        self::assertGreaterThan(0, $over['preview']);
        self::assertEqualsWithDelta($over['track'], $over['assignedHeight'] + $over['preview'], 2.0);
        self::assertNotNull($over['overColor']);
        self::assertGreaterThan($over['overColor']['g'], $over['overColor']['r']);
        self::assertGreaterThan($over['overColor']['b'], $over['overColor']['r']);
        self::assertStringContainsString('want-gauge-shake', (string) $over['overAnimation']);

        self::click('Fuel');
        self::wait()->until(function (): bool {
            $gone = self::driver()->executeScript('return document.querySelector("[data-want-gauge]") === null');
            $odometer = self::driver()->executeScript('return document.querySelector(\'input[name="odometer"]\') !== null');

            return $gone === true && $odometer === true;
        });
    }

    /** @return array<string, array{id:int,name:string,assigned:int,max:int}> */
    private static function lockedBooks(): array
    {
        /** @var array<string, array{id:int,name:string,assigned:int,max:int}> $books */
        $books = self::driver()->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            const maxOf = (row) => {
                const cap = row.target && row.target.amountMinor > 0 ? row.target.amountMinor : 0;
                if (cap > 0) return cap;
                const bills = row.billMinor != null ? row.billMinor : row.amountMinor;
                return bills > 0 ? bills : 0;
            };
            Promise.all([
                fetch('/api/budget?today=' + today, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((response) => response.json()),
                fetch('/api/buckets', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((response) => response.json()),
            ]).then(([sheet, listed]) => {
                const read = (type) => {
                    const cat = listed.categories.find((item) => item.locked && item.billType === type);
                    const row = cat && sheet.categories.find((item) => item.id === cat.id);
                    return row ? { id: row.id, name: row.name, assigned: row.amountMinor, max: maxOf(row) } : null;
                };
                done({
                    restaurants: read('restaurants'),
                    shopping: read('shopping'),
                    entertainment: read('entertainment'),
                });
            }).catch((err) => done({ error: String(err) }));
        JS);
        self::assertArrayHasKey('restaurants', $books);
        foreach (['restaurants', 'shopping', 'entertainment'] as $type) {
            self::assertIsArray($books[$type], $type);
        }

        return $books;
    }

    private static function raiseMax(int $categoryId, int $maxMinor): void
    {
        $saved = self::driver()->executeAsyncScript(<<<'JS'
            const categoryId = arguments[0];
            const maxMinor = arguments[1];
            const done = arguments[arguments.length - 1];
            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            fetch('/api/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((session) => fetch('/api/budget?today=' + today, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then((response) => response.json())
                    .then((sheet) => fetch('/api/budget', {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': session.csrfToken,
                        },
                        body: JSON.stringify({
                            today,
                            payCycleId: sheet.payCycleId,
                            amounts: sheet.categories.map((row) => ({ categoryId: row.id, amountMinor: row.amountMinor })),
                            maximums: [{ categoryId, amountMinor: maxMinor }],
                        }),
                    })))
                .then(async (response) => {
                    const data = await response.json();
                    const row = (data.categories || []).find((item) => item.id === categoryId);
                    done({ ok: response.status, max: row && row.target ? row.target.amountMinor : null, message: data.error || '' });
                })
                .catch((err) => done({ ok: 0, max: null, message: String(err) }));
        JS, [$categoryId, $maxMinor]);
        self::assertIsArray($saved);
        self::assertSame(200, $saved['ok'], (string) ($saved['message'] ?? ''));
        self::assertSame($maxMinor, $saved['max']);
    }

    /** @return array{bucket:string,assigned:int,max:int,over:string,neutral:string,text:string,assignedDollars:string,maxDollars:string,preview:float,assignedHeight:float,track:float,stripes:string,overAnimation:?string,overColor:?array{r:int,g:int,b:int}} */
    private static function gauge(): array
    {
        /** @var array{bucket:string,assigned:int,max:int,over:string,neutral:string,text:string,assignedDollars:string,maxDollars:string,preview:float,assignedHeight:float,track:float,stripes:string,overAnimation:?string,overColor:?array{r:int,g:int,b:int}} $gauge */
        $gauge = self::driver()->executeScript(<<<'JS'
            const gauge = document.querySelector('[data-want-gauge]');
            if (!gauge) return null;
            const money = (minor) => {
                const abs = Math.abs(Math.trunc(Number(minor)));
                const whole = Math.trunc(abs / 100).toLocaleString('en-US');
                const frac = String(abs % 100).padStart(2, '0');
                return `$${whole}.${frac}`;
            };
            const preview = gauge.querySelector('.want-gauge-preview');
            const assigned = gauge.querySelector('.want-gauge-assigned');
            const track = gauge.querySelector('.want-gauge-track');
            const over = gauge.querySelector('.want-gauge-over');
            const overStyle = over ? getComputedStyle(over) : null;
            const color = overStyle ? overStyle.backgroundColor.match(/\d+/g) : null;
            return {
                bucket: gauge.dataset.bucket,
                assigned: Number(gauge.dataset.assignedMinor),
                max: Number(gauge.dataset.maxMinor),
                over: gauge.dataset.over,
                neutral: gauge.dataset.neutral,
                text: gauge.textContent,
                assignedDollars: money(gauge.dataset.assignedMinor),
                maxDollars: money(gauge.dataset.maxMinor),
                preview: preview ? preview.getBoundingClientRect().height : 0,
                assignedHeight: assigned ? assigned.getBoundingClientRect().height : 0,
                track: track ? track.getBoundingClientRect().height : 0,
                stripes: preview ? getComputedStyle(preview).backgroundImage : '',
                overAnimation: overStyle ? overStyle.animationName : null,
                overColor: color ? { r: Number(color[0]), g: Number(color[1]), b: Number(color[2]) } : null,
            };
        JS);
        if (!is_array($gauge)) {
            throw new NoSuchElementException('The want gauge is not on screen.');
        }

        return $gauge;
    }

    private static function clearAmount(): void
    {
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="amount"]');
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::wait()->until(fn (): bool => self::gauge()['over'] === '0');
    }

    private static function waitForGauge(): void
    {
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript('return document.querySelector("[data-want-gauge]") !== null');

            return $ready === true;
        });
    }

    private static function click(string $label): void
    {
        $by = WebDriverBy::xpath('//button[normalize-space()=' . self::quote($label) . ']');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function clickLink(string $label): void
    {
        $by = WebDriverBy::linkText($label);
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
        self::assertNotFalse(stripos($body, $text));
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
