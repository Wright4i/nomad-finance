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

final class ChipSizingTest extends TestCase
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

    public function testAnAmountChipMatchesTheAccountChipMetrics(): void
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
        self::waitForText('Travel card');

        $account = self::chipMetrics('Travel card', 'limit');
        self::assertNotSame('', $account['chipFontSize']);
        self::assertGreaterThan(16, $account['chipHeight']);

        self::clickLink('Trips');
        self::waitForText('Previous month');
        self::driver()->executeScript(<<<'JS'
            const heading = document.querySelector('[data-month]');
            if (heading && heading.getAttribute('data-month') !== '2026-11') {
                const step = heading.getAttribute('data-month') > '2026-11' ? 'Previous month' : 'Next month';
                const button = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === step);
                if (button) button.click();
            }
        JS);
        self::waitForText('Lost Dutchman');

        $bill = self::chipHostMetrics('Lost Dutchman', 'status');
        self::assertTrue($account['inherits'], json_encode($account));
        self::assertEqualsWithDelta((float) $account['chipFontSize'], (float) $bill['chipFontSize'], 0.5);
        self::assertEqualsWithDelta((float) $account['chipPaddingTop'], (float) $bill['chipPaddingTop'], 1.5);
        self::assertEqualsWithDelta((float) $account['chipHeight'], (float) $bill['chipHeight'], 4);
    }

    /** @return array{chipFontSize:string,fontFamily:string,chipPaddingTop:string,chipHeight:float,inherits:bool} */
    private static function chipMetrics(string $rowLabel, string $chipKey): array
    {
        $row = self::wait()->until(function () use ($rowLabel, $chipKey): array|false {
            $value = self::driver()->executeScript(
                <<<'JS'
                    let money = null;
                    const tile = [...document.querySelectorAll('a.bucket-tile')].find((node) => node.textContent.includes(arguments[0]));
                    money = tile ? tile.querySelector(`[data-chip="${arguments[1]}"] .money`) : null;
                    if (!money) return null;
                    const chip = money.closest('.bill-detail-chip');
                    if (!chip) return null;
                    const moneyStyle = getComputedStyle(money);
                    const chipStyle = getComputedStyle(chip);
                    const chipBox = chip.getBoundingClientRect();
                    return {
                        chipFontSize: chipStyle.fontSize,
                        fontFamily: moneyStyle.fontFamily,
                        chipPaddingTop: chipStyle.paddingTop,
                        chipHeight: chipBox.height,
                        inherits: moneyStyle.color === chipStyle.color
                            && moneyStyle.fontSize === chipStyle.fontSize
                            && moneyStyle.fontFamily === chipStyle.fontFamily,
                    };
                JS,
                [$rowLabel, $chipKey],
            );

            return is_array($value) ? $value : false;
        });
        self::assertIsArray($row);

        return [
            'chipFontSize' => (string) $row['chipFontSize'],
            'fontFamily' => (string) $row['fontFamily'],
            'chipPaddingTop' => (string) $row['chipPaddingTop'],
            'chipHeight' => (float) $row['chipHeight'],
            'inherits' => (bool) $row['inherits'],
        ];
    }

    /** @return array{chipFontSize:string,chipPaddingTop:string,chipHeight:float} */
    private static function chipHostMetrics(string $rowLabel, string $chipKey): array
    {
        $row = self::wait()->until(function () use ($rowLabel, $chipKey): array|false {
            $value = self::driver()->executeScript(
                <<<'JS'
                    const stay = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(arguments[0]));
                    const chip = stay ? stay.querySelector(`[data-chip="${arguments[1]}"]`) : null;
                    if (!chip) return null;
                    const chipStyle = getComputedStyle(chip);
                    const chipBox = chip.getBoundingClientRect();
                    return {
                        chipFontSize: chipStyle.fontSize,
                        chipPaddingTop: chipStyle.paddingTop,
                        chipHeight: chipBox.height,
                    };
                JS,
                [$rowLabel, $chipKey],
            );

            return is_array($value) ? $value : false;
        });
        self::assertIsArray($row);

        return [
            'chipFontSize' => (string) $row['chipFontSize'],
            'chipPaddingTop' => (string) $row['chipPaddingTop'],
            'chipHeight' => (float) $row['chipHeight'],
        ];
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
    }

    private static function wait(int $seconds = 15): WebDriverWait
    {
        return new WebDriverWait(self::driver(), $seconds);
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
