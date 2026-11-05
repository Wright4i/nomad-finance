<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Dashboard\PlannerHeading;
use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlannerHeadingTest extends TestCase
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

    public function testTheHeadingNamesTheCityOfTheOpenStay(): void
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

        $plain = self::heading();
        self::assertSame('Planner', $plain['text']);
        self::assertSame('normal', $plain['style']);
        self::assertStringContainsString('Outfit', $plain['family']);
        $worth = self::netWorth();
        $menu = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('nav a')].map((link) => link.textContent.trim());
        JS);
        self::assertContains('Planner', is_array($menu) ? $menu : []);

        $created = self::driver()->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            fetch('/api/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((session) => fetch('/api/trips', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': session.csrfToken,
                    },
                    body: JSON.stringify({
                        placeName: 'Stay 3',
                        arriveOn: today,
                        departOn: today,
                        latitude: '47.6062',
                        longitude: '-122.3321',
                        city: 'Seattle',
                        region: 'WA',
                        totalMinor: 0,
                        amountDueMinor: 0,
                        today,
                    }),
                }))
                .then(async (response) => {
                    const data = await response.json();
                    done({
                        ok: response.status,
                        id: data.trip ? data.trip.id : 0,
                        city: data.trip ? data.trip.city : '',
                        today,
                        message: data.message || '',
                    });
                })
                .catch((err) => done({ ok: 0, id: 0, city: '', today, message: String(err) }));
        JS);
        self::assertIsArray($created);
        self::assertSame(201, $created['ok'], (string) ($created['message'] ?? ''));
        self::assertSame('Seattle', $created['city']);
        $expected = PlannerHeading::phrase((int) $created['id'], 'Seattle');

        self::driver()->navigate()->refresh();
        self::waitForText('Available to assign');
        self::wait()->until(function () use ($expected): bool {
            return self::heading()['text'] === $expected;
        });
        $phrase = self::heading();
        self::assertSame($expected, $phrase['text']);
        self::assertSame('italic', $phrase['style']);
        self::assertSame('600', (string) $phrase['weight']);
        self::assertStringContainsString('Fraunces', $phrase['family']);
        self::assertSame($worth, self::netWorth());

        $cleared = self::driver()->executeAsyncScript(<<<'JS'
            const id = arguments[0];
            const today = arguments[1];
            const done = arguments[arguments.length - 1];
            fetch('/api/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((session) => fetch('/api/trips/' + id, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': session.csrfToken,
                    },
                    body: JSON.stringify({
                        placeName: 'Stay 3',
                        arriveOn: today,
                        departOn: today,
                        latitude: '47.6062',
                        longitude: '-122.3321',
                        city: null,
                        region: 'WA',
                        totalMinor: 0,
                        amountDueMinor: 0,
                        today,
                    }),
                }))
                .then(async (response) => {
                    const data = await response.json();
                    done({ ok: response.status, city: data.trip ? data.trip.city : 'missing', message: data.message || '' });
                })
                .catch((err) => done({ ok: 0, city: '', message: String(err) }));
        JS, [(int) $created['id'], (string) $created['today']]);
        self::assertIsArray($cleared);
        self::assertSame(200, $cleared['ok'], (string) ($cleared['message'] ?? ''));
        self::assertNull($cleared['city']);

        self::driver()->navigate()->refresh();
        self::waitForText('Available to assign');
        self::wait()->until(function (): bool {
            return self::heading()['text'] === 'Planner';
        });
        $again = self::heading();
        self::assertSame('Planner', $again['text']);
        self::assertSame('normal', $again['style']);
        self::assertStringContainsString('Outfit', $again['family']);
        self::assertSame($worth, self::netWorth());
    }

    /** @return array{text:string,style:string,family:string,weight:string} */
    private static function heading(): array
    {
        $value = self::driver()->executeScript(<<<'JS'
            const node = document.querySelector('#main h1');
            if (!node) return null;
            const style = getComputedStyle(node);
            return {
                text: node.textContent.trim(),
                style: style.fontStyle,
                family: style.fontFamily,
                weight: String(style.fontWeight),
            };
        JS);
        self::assertIsArray($value);

        return [
            'text' => (string) $value['text'],
            'style' => (string) $value['style'],
            'family' => (string) $value['family'],
            'weight' => (string) $value['weight'],
        ];
    }

    private static function netWorth(): string
    {
        return self::driver()->findElement(WebDriverBy::cssSelector('p.figure'))->getText();
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
