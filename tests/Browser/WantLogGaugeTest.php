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

    public function testAWantLogBucketShowsEnvelopeWaterAndAfterAmount(): void
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
        self::waitForBucket();

        $restaurants = self::bucket();
        $books = self::lockedBooks();
        self::assertSame($books['restaurants']['name'], $restaurants['bucket']);
        self::assertSame($books['restaurants']['envelope'], $restaurants['envelope']);
        self::assertSame($books['restaurants']['auto'], $restaurants['auto']);
        self::assertSame($books['restaurants']['envelope'], $restaurants['after']);
        self::assertStringContainsString('Bill assignments', $restaurants['text']);
        self::assertStringContainsString('Envelope', $restaurants['text']);
        self::assertStringContainsString('After', $restaurants['text']);
        self::assertEquals(3, $restaurants['waves']);
        if ($restaurants['envelope'] > 0) {
            self::assertEqualsWithDelta(1, $restaurants['level'], 0.01);
        } else {
            self::assertEquals(0, $restaurants['level']);
        }
        self::assertSame('0', $restaurants['over']);

        self::click('shopping');
        self::wait()->until(fn (): bool => self::bucket()['bucket'] === $books['shopping']['name']);
        $shopping = self::bucket();
        self::assertSame($books['shopping']['envelope'], $shopping['envelope']);
        self::assertSame($books['shopping']['auto'], $shopping['auto']);
        self::assertNotSame($restaurants['bucket'], $shopping['bucket']);

        self::click('restaurants');
        self::wait()->until(fn (): bool => self::bucket()['bucket'] === $books['restaurants']['name']);
        if ($books['restaurants']['envelope'] < 2000) {
            $raised = 10000;
            self::raiseEnvelope($books['restaurants']['id'], $raised);
            self::driver()->navigate()->refresh();
            self::click('Want');
            self::wait()->until(fn (): bool => self::bucket()['envelope'] === $raised);
        }

        $open = self::bucket();
        self::assertGreaterThanOrEqual(2000, $open['envelope']);
        self::type('amount', '10');
        self::wait()->until(fn (): bool => self::bucket()['after'] === $open['envelope'] - 1000);
        $under = self::bucket();
        self::assertSame('0', $under['over']);
        self::assertGreaterThan(0, $under['level']);
        self::assertLessThan(1, $under['level']);

        self::type('amount', '500');
        self::wait()->until(fn (): bool => self::bucket()['over'] === '1');
        $over = self::bucket();
        self::assertLessThan(0, $over['after']);
        self::assertStringContainsString('Over by', (string) self::driver()->findElement(WebDriverBy::cssSelector('.log-bucket-cell'))->getText());

        self::click('Fuel');
        self::wait()->until(function (): bool {
            $gone = self::driver()->executeScript('return document.querySelector("[data-log-bucket]") === null');
            $odometer = self::driver()->executeScript('return document.querySelector(\'input[name="odometer"]\') !== null');

            return $gone === true && $odometer === true;
        });
    }

    /** @return array<string, array{id:int,name:string,envelope:int,auto:int}> */
    private static function lockedBooks(): array
    {
        /** @var array<string, array{id:int,name:string,envelope:int,auto:int}> $books */
        $books = self::driver()->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            Promise.all([
                fetch('/api/budget?today=' + today + '&acctPeriod=1', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((response) => response.json()),
                fetch('/api/buckets', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((response) => response.json()),
            ]).then(([sheet, listed]) => {
                const read = (type) => {
                    const cat = listed.categories.find((item) => item.locked && item.billType === type);
                    const row = cat && sheet.categories.find((item) => item.id === cat.id);
                    if (!row) return null;
                    const auto = row.billMinor != null ? row.billMinor : 0;
                    const envelope = Math.max(0, (row.amountMinor != null ? row.amountMinor : 0) - auto);
                    return { id: row.id, name: row.name, envelope, auto };
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

    private static function raiseEnvelope(int $categoryId, int $envelopeMinor): void
    {
        $saved = self::driver()->executeAsyncScript(<<<'JS'
            const categoryId = arguments[0];
            const envelopeMinor = arguments[1];
            const done = arguments[arguments.length - 1];
            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            fetch('/api/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((session) => fetch('/api/budget?today=' + today + '&acctPeriod=1', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then((response) => response.json())
                    .then((sheet) => {
                        const row = sheet.categories.find((item) => item.id === categoryId);
                        const auto = row && row.billMinor != null ? row.billMinor : 0;
                        return fetch('/api/budget', {
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
                                amounts: sheet.categories.map((cat) => ({
                                    categoryId: cat.id,
                                    amountMinor: cat.id === categoryId ? auto + envelopeMinor : cat.amountMinor,
                                })),
                            }),
                        });
                    }))
                .then(async (response) => {
                    const data = await response.json();
                    const row = (data.categories || []).find((item) => item.id === categoryId);
                    const auto = row && row.billMinor != null ? row.billMinor : 0;
                    done({
                        ok: response.status,
                        envelope: row ? Math.max(0, row.amountMinor - auto) : null,
                        message: data.error || '',
                    });
                })
                .catch((err) => done({ ok: 0, envelope: null, message: String(err) }));
        JS, [$categoryId, $envelopeMinor]);
        self::assertIsArray($saved);
        self::assertSame(200, $saved['ok'], (string) ($saved['message'] ?? ''));
        self::assertSame($envelopeMinor, $saved['envelope']);
    }

    /** @return array{bucket:string,envelope:int,auto:int,after:int,over:string,text:string,level:float,waves:int} */
    private static function bucket(): array
    {
        /** @var array{bucket:string,envelope:int,auto:int,after:int,over:string,text:string,level:float,waves:int} $bucket */
        $bucket = self::driver()->executeScript(<<<'JS'
            const tile = document.querySelector('[data-log-bucket]');
            if (!tile) return null;
            const level = getComputedStyle(tile).getPropertyValue('--level').trim();
            return {
                bucket: tile.dataset.bucket,
                envelope: Number(tile.dataset.envelopeMinor),
                auto: Number(tile.dataset.autoMinor),
                after: Number(tile.dataset.afterMinor),
                over: tile.dataset.over,
                text: tile.textContent,
                level: level === '' ? 0 : Number(level),
                waves: tile.querySelectorAll('.bucket-wave').length,
            };
        JS);
        if (!is_array($bucket)) {
            throw new NoSuchElementException('The log bucket is not on screen.');
        }

        return $bucket;
    }

    private static function waitForBucket(): void
    {
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript('return document.querySelector("[data-log-bucket]") !== null');

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
        return new WebDriverWait(self::$driver, 15);
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
