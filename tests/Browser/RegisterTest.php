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
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegisterTest extends TestCase
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

    public function testSetupShowsAnEmptyNetWorth(): void
    {
        self::driver()->get(self::$base . '/');
        self::waitForText('Vehicle name');
        self::waitForText('Minimum mpg');
        self::waitForText('Maximum mpg');
        self::waitForText('Fallback price per gallon');
        self::waitForText('motorcycle');
        $color = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="color"], input[type="color"]');
            const label = [...document.querySelectorAll('label.field > span, .field > span')].some((node) => node.textContent.trim() === 'Color');
            return input !== null || label;
        JS);
        self::assertNotSame(true, $color);
        self::type('name', 'Ada');
        self::type('password', self::PASSWORD);
        self::setValue('vehicleName', 'Rig 1');
        self::click('truck');
        self::setValue('mpgMin', '8.0');
        self::setValue('mpgMax', '16.0');
        self::setValue('fuelPrice', '3.99');
        self::setValue('currentOdometer', '12000');
        self::click('Create my login');
        self::waitForText('Net worth');
        self::waitForMoney('$0.00');
        self::clickLink('Settings');
        $price = self::wait()->until(function (): string|false {
            $value = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="fuelPrice"]');
                return input ? input.value : '';
            JS);

            return $value === '3.99' ? $value : false;
        });
        self::assertSame('3.99', $price);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::partialLinkText('Vehicles'),
        ))->click();
        self::waitForText('Rig 1');
        $star = self::wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(
            WebDriverBy::cssSelector('button[aria-label="Rig 1 is the default"]'),
        ));
        self::assertSame('true', $star->getAttribute('aria-pressed'));
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::partialLinkText('Rig 1'),
        ))->click();
        self::waitForText("8.0\u{2013}16.0 mpg");
    }

    #[Depends('testSetupShowsAnEmptyNetWorth')]
    public function testDemoOpensTheSampleBooks(): void
    {
        self::click('Log out');
        self::waitForText('Use demo');
        self::click('Use demo');
        self::waitForText('Available to assign');
        self::waitForText('rent');
        self::waitForText('Reset demo data');
        self::waitForMoney('$8,945.00');
        $reset = self::driver()->findElement(WebDriverBy::xpath('//button[normalize-space()="Reset demo data"]'));
        $resting = $reset->getCSSValue('background-color');
        self::driver()->action()->moveToElement($reset)->perform();
        $hovered = self::wait()->until(function () use ($reset, $resting): string|false {
            $color = $reset->getCSSValue('background-color');

            return $color !== $resting ? $color : false;
        });
        self::assertIsString($hovered);
        self::assertNotSame($resting, $hovered);
        self::clickLink('Accounts');
        self::waitForText('Checking 1');
        self::clickLink('Bills');
        self::waitForText('One-Time Bill');
    }

    #[Depends('testDemoOpensTheSampleBooks')]
    public function testExchangeRatesLiveUnderSettings(): void
    {
        self::clickLink('Settings');
        self::waitForText('Fallback price per gallon');
        $body = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();
        self::assertStringNotContainsString('Exchange rates', $body);
        self::assertStringNotContainsString('USD', $body);
        self::driver()->get(self::$base . '/accounts/new');
        self::waitForText('Save account');
        $currency = self::driver()->findElements(WebDriverBy::name('currency'));
        self::assertCount(0, $currency);
    }

    #[Depends('testExchangeRatesLiveUnderSettings')]
    public function testResetDropsScratchAndKeepsTheSample(): void
    {
        self::driver()->get(self::$base . '/accounts/new');
        self::type('name', 'Scratch');
        self::type('balance', '1.00');
        self::click('Save account');
        self::waitForText('Scratch');
        self::click('Reset demo data');
        self::click('Reset the sample books');
        self::waitForText('Checking 1');
        self::waitForTextGone('Scratch');
    }

    #[Depends('testResetDropsScratchAndKeepsTheSample')]
    public function testNarrowWindowOpensTheMenu(): void
    {
        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        self::driver()->get(self::$base . '/');
        $menu = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('button[aria-label="Open menu"]'),
        ));
        $delta = self::driver()->executeScript(<<<'JS'
            const button = arguments[0];
            const icon = button.querySelector('svg');
            const box = button.getBoundingClientRect();
            const mark = icon.getBoundingClientRect();
            return [
                Math.abs((mark.left + mark.width / 2) - (box.left + box.width / 2)),
                Math.abs((mark.top + mark.height / 2) - (box.top + box.height / 2)),
            ];
        JS, [$menu]);
        self::assertIsArray($delta);
        self::assertLessThan(1, (float) $delta[0]);
        self::assertLessThan(1, (float) $delta[1]);
        $menu->click();
        $close = self::wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(
            WebDriverBy::cssSelector('button[aria-label="Close menu"]'),
        ));
        self::assertTrue($close->isDisplayed());
        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
    }

    #[Depends('testNarrowWindowOpensTheMenu')]
    public function testOwnerBooksStayEmpty(): void
    {
        self::click('Log out');
        self::waitForText('Unlock');
        self::type('name', 'Ada');
        self::type('password', self::PASSWORD);
        self::click('Unlock');
        self::waitForText('Net worth');
        self::waitForMoney('$0.00');
        self::waitForTextGone('Checking 1');
        self::waitForTextGone('Scratch');
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

    private static function setValue(string $name, string $value): void
    {
        $element = self::wait()->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::name($name)),
        );
        self::driver()->executeScript(<<<'JS'
            const el = arguments[0];
            const value = arguments[1];
            const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        JS, [$element, $value]);
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

    private static function waitForMoney(string $amount): void
    {
        $body = self::wait()->until(function () use ($amount): string|false {
            $flat = str_replace(["\u{00A0}", "\u{202F}"], '', self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText());
            if (str_contains($flat, $amount) || str_contains($flat, str_replace('$', 'US$', $amount))) {
                return $flat;
            }

            return false;
        });
        self::assertIsString($body);
        self::assertTrue(
            str_contains($body, $amount) || str_contains($body, str_replace('$', 'US$', $amount)),
        );
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
