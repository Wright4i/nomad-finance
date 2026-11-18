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

final class SelectOnFocusTest extends TestCase
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

    public function testClickingAFilledAmountSelectsItsText(): void
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

        self::clickLink('Accounts');
        self::waitForText('Checking');
        $account = WebDriverBy::partialLinkText('Checking');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($account))->click();
        self::waitForText('Balance');
        self::wait()->until(function (): bool {
            $value = self::fieldValue('input[name="balance"]');

            return $value !== '';
        });

        $balance = WebDriverBy::cssSelector('input[name="balance"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($balance))->click();
        $selected = self::wait()->until(function (): array|false {
            $state = self::selection('input[name="balance"]');
            if ($state === null || $state['failure'] !== '') {
                return false;
            }
            if ($state['value'] === '' || $state['start'] !== 0 || $state['end'] !== $state['length']) {
                return false;
            }

            return $state;
        });
        self::assertTrue($selected['money']);
        self::assertTrue($selected['active']);
        self::assertSame('', $selected['failure']);
        self::assertGreaterThan(0, $selected['length']);
        self::assertSame(0, $selected['start']);
        self::assertSame($selected['length'], $selected['end']);

        $institution = WebDriverBy::cssSelector('input[name="institution"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($institution))->click();
        $empty = self::selection('input[name="institution"]');
        self::assertIsArray($empty);
        self::assertSame('', $empty['failure']);
        self::assertSame('', $empty['value']);
        self::assertTrue($empty['active']);
        self::assertSame(0, $empty['start']);
        self::assertSame(0, $empty['end']);
    }

    /** @return array{value: string, start: int, end: int, length: int, active: bool, money: bool, failure: string}|null */
    private static function selection(string $selector): ?array
    {
        $state = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector(arguments[0]);
            if (!(input instanceof HTMLInputElement)) return null;
            let start = null;
            let end = null;
            let failure = '';
            try {
                start = input.selectionStart;
                end = input.selectionEnd;
            } catch (err) {
                failure = err instanceof Error ? err.message : String(err);
            }
            return {
                value: input.value,
                start,
                end,
                length: input.value.length,
                active: document.activeElement === input,
                money: input.closest('.money-input') !== null,
                failure,
            };
        JS, [$selector]);
        if (!is_array($state)) {
            return null;
        }

        return [
            'value' => is_string($state['value'] ?? null) ? $state['value'] : '',
            'start' => is_int($state['start'] ?? null) ? $state['start'] : -1,
            'end' => is_int($state['end'] ?? null) ? $state['end'] : -1,
            'length' => is_int($state['length'] ?? null) ? $state['length'] : -1,
            'active' => ($state['active'] ?? false) === true,
            'money' => ($state['money'] ?? false) === true,
            'failure' => is_string($state['failure'] ?? null) ? $state['failure'] : '',
        ];
    }

    private static function fieldValue(string $selector): string
    {
        $value = self::driver()->executeScript(
            'const input = document.querySelector(arguments[0]); return input ? input.value : "";',
            [$selector],
        );

        return is_string($value) ? $value : '';
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
