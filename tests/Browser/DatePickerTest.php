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

final class DatePickerTest extends TestCase
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

    public function testAPersonChoosesADayOnALogAndInsideThePayrollModal(): void
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
        self::waitForText('New log');
        self::wait()->until(fn (): bool => self::attr('input[name="date"]', 'aria-label') === 'Date');
        self::assertSame('Choose date', self::attr('input[name="date"] ~ button[aria-label="Choose date"]', 'aria-label'));
        self::openPicker('date');
        $logDay = self::pickDay('date');
        self::assertMatchesRegularExpression('/^\d{2} \/ \d{2} \/ \d{4}$/', $logDay);
        self::assertSame($logDay, self::field('date'));
        self::assertFalse(self::pickerOpen());

        self::clickLink('Income');
        self::waitForText('Irregular Payor');
        $payroll = WebDriverBy::xpath('//article[contains(., "Irregular Payor")]//button[normalize-space()="Log Payroll"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($payroll))->click();
        self::waitForText('Add a date');
        self::assertTrue(self::payrollOpen());
        self::wait()->until(fn (): bool => self::attr('input[name="extraDate"]', 'aria-label') === 'Add a date');
        self::openPicker('extraDate');
        $modalDay = self::pickDay('extraDate');
        self::assertMatchesRegularExpression('/^\d{2} \/ \d{2} \/ \d{4}$/', $modalDay);
        self::assertSame($modalDay, self::field('extraDate'));
        self::assertFalse(self::pickerOpen());
        self::assertTrue(self::payrollOpen());
        self::waitForText('Payroll');
    }

    private static function openPicker(string $name): void
    {
        $by = WebDriverBy::cssSelector('input[name="' . $name . '"] ~ button[aria-label="Choose date"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
        self::wait()->until(fn (): bool => self::pickerOpen());
    }

    private static function pickDay(string $name): string
    {
        $day = str_contains(self::field($name), ' / 15 / ') ? '16' : '15';
        $by = WebDriverBy::cssSelector('.date-picker button[data-date$="-' . $day . '"]');
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        $iso = $element->getAttribute('data-date');
        self::assertIsString($iso);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-' . $day . '$/', $iso);
        $shown = self::shown($iso);
        $element->click();
        self::wait()->until(function () use ($name, $shown): bool {
            return self::field($name) === $shown && !self::pickerOpen();
        });

        return $shown;
    }

    private static function shown(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $match)) {
            self::fail('The calendar day was not a date.');
        }

        return $match[2] . ' / ' . $match[3] . ' / ' . $match[1];
    }

    private static function pickerOpen(): bool
    {
        return self::driver()->executeScript('return document.querySelector(".date-picker") !== null') === true;
    }

    private static function payrollOpen(): bool
    {
        return self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog[aria-labelledby="payroll-title"]');
            return Boolean(dialog && dialog.open);
        JS) === true;
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
