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
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogTest extends TestCase
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

    public function testAFuelLogOpensOnTheCurrentReadingAndShowsMpgBeforeSave(): void
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

        $seeded = self::driver()->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            const accept = { Accept: 'application/json' };
            fetch('/api/session', { headers: accept, credentials: 'same-origin' })
                .then((response) => response.json())
                .then((session) => {
                    const headers = {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': session.csrfToken,
                    };
                    return fetch('/api/vehicles', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers,
                        body: JSON.stringify({
                            name: 'Vehicle 2',
                            kind: 'car',
                            mpgMinTenths: 60,
                            mpgMaxTenths: 120,
                            starred: false,
                            currentOdometerMiles: 2500,
                        }),
                    }).then(async (response) => ({ headers, today, vehicleStatus: response.status, vehicle: await response.json() }));
                })
                .then(async (step) => {
                    const accounts = await (await fetch('/api/accounts', { headers: accept, credentials: 'same-origin' })).json();
                    const fuel = accounts.accounts.find((item) => item.type === 'fuel');
                    const logged = await fetch('/api/logs', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: step.headers,
                        body: JSON.stringify({
                            kind: 'fuel',
                            accountId: fuel.id,
                            amountMinor: 1000,
                            vehicleId: step.vehicle.vehicle.id,
                            odometerMiles: 2500,
                            pricePerGallonMinor: 525,
                            date: step.today,
                            today: step.today,
                        }),
                    });
                    const logBody = await logged.json();
                    const vehicles = await (await fetch('/api/vehicles', { headers: accept, credentials: 'same-origin' })).json();
                    const first = vehicles.vehicles.find((item) => item.name === 'Vehicle 1');
                    const detail = await (await fetch('/api/vehicles/' + first.id, { headers: accept, credentials: 'same-origin' })).json();
                    done({
                        vehicleStatus: step.vehicleStatus,
                        logStatus: logged.status,
                        message: (step.vehicle.message || logBody.message || ''),
                        vehicle1: first.id,
                        readings: detail.vehicle.readings.length,
                        balance: fuel.balanceMinor,
                    });
                })
                .catch((err) => done({
                    vehicleStatus: 0,
                    logStatus: 0,
                    message: String(err),
                    vehicle1: 0,
                    readings: -1,
                    balance: -1,
                }));
        JS);
        self::assertIsArray($seeded);
        self::assertSame(201, $seeded['vehicleStatus'], (string) ($seeded['message'] ?? ''));
        self::assertSame(201, $seeded['logStatus'], (string) ($seeded['message'] ?? ''));

        self::clickLink('New Log');
        self::waitForText('New log');
        self::waitForField('odometer', '10500');
        self::waitForField('price', '4.00');
        self::assertSame('0', self::attr('[data-miles]', 'data-miles'));
        self::assertSame('', self::attr('[data-mpg]', 'data-mpg'));
        self::type('amount', '10');
        self::waitForAttr('[data-mpg]', 'data-mpg', '0.0');
        self::assertSame('0.0 mpg', self::text('[data-mpg]'));
        self::replace('odometer', '10600');
        self::waitForAttr('[data-miles]', 'data-miles', '100');
        self::waitForAttr('[data-mpg]', 'data-mpg', '40.0');
        self::assertSame('40.0 mpg', self::text('[data-mpg]'));
        self::assertSame('100 miles traveled', self::text('[data-miles]'));
        self::assertStringContainsString('/log', self::driver()->getCurrentURL());
        self::assertNotEmpty(self::driver()->findElements(WebDriverBy::xpath('//button[normalize-space()="Save log"]')));

        $after = self::driver()->executeAsyncScript(<<<'JS'
            const id = arguments[0];
            const done = arguments[arguments.length - 1];
            const accept = { Accept: 'application/json' };
            Promise.all([
                fetch('/api/vehicles/' + id, { headers: accept, credentials: 'same-origin' }).then((response) => response.json()),
                fetch('/api/accounts', { headers: accept, credentials: 'same-origin' }).then((response) => response.json()),
            ]).then(([detail, accounts]) => {
                const fuel = accounts.accounts.find((item) => item.type === 'fuel');
                done({ readings: detail.vehicle.readings.length, balance: fuel.balanceMinor });
            }).catch((err) => done({ readings: -1, balance: -1, message: String(err) }));
        JS, [(int) $seeded['vehicle1']]);
        self::assertIsArray($after);
        self::assertSame($seeded['readings'], $after['readings']);
        self::assertSame($seeded['balance'], $after['balance']);

        self::driver()->navigate()->refresh();
        self::waitForField('odometer', '10500');
        self::waitForField('price', '4.00');
        self::chooseVehicle('Vehicle 2');
        self::waitForField('odometer', '2500');
        self::waitForField('price', '5.25');
        self::chooseVehicle('Vehicle 1');
        self::waitForField('odometer', '10500');
        self::waitForField('price', '4.00');
        self::replace('odometer', '10800');
        self::replace('price', '3.50');
        self::chooseVehicle('Vehicle 2');
        self::waitForField('odometer', '10800');
        self::waitForField('price', '3.50');
    }

    private static function chooseVehicle(string $label): void
    {
        self::driver()->executeScript(<<<'JS'
            const label = arguments[0];
            const select = document.querySelector('select[name="vehicleId"]');
            const option = [...select.options].find((item) => item.textContent.trim() === label);
            const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
            setter.call(select, option.value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        JS, [$label]);
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
        $by = WebDriverBy::name($name);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        $element->clear();
        $element->sendKeys($value);
    }

    private static function replace(string $name, string $value): void
    {
        $by = WebDriverBy::name($name);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        self::driver()->executeScript(
            <<<'JS'
                const input = arguments[0];
                const value = arguments[1];
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
                setter.call(input, value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            JS,
            [$element, $value],
        );
    }

    private static function waitForField(string $name, string $value): void
    {
        self::wait()->until(function () use ($name, $value): bool {
            return self::field($name) === $value;
        });
    }

    private static function waitForAttr(string $selector, string $name, string $value): void
    {
        self::wait()->until(function () use ($selector, $name, $value): bool {
            return self::attr($selector, $name) === $value;
        });
    }

    private static function field(string $name): string
    {
        $value = self::driver()->executeScript(
            'const input = document.querySelector(arguments[0]); return input ? input.value : "";',
            ['[name="' . $name . '"]'],
        );

        return is_string($value) ? $value : '';
    }

    private static function attr(string $selector, string $name): string
    {
        $value = self::driver()->executeScript(
            'const node = document.querySelector(arguments[0]); return node ? node.getAttribute(arguments[1]) : "";',
            [$selector, $name],
        );

        return is_string($value) ? $value : '';
    }

    private static function text(string $selector): string
    {
        $value = self::driver()->executeScript(
            'const node = document.querySelector(arguments[0]); return node ? node.textContent.trim() : "";',
            [$selector],
        );

        return is_string($value) ? $value : '';
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
