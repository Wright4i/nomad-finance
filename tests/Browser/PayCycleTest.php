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

final class PayCycleTest extends TestCase
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

    public function testDemoListsACycleForEachPayor(): void
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
        self::clickLink('Income');
        self::waitForText('Weekly Payor');
        self::waitForText('Averaged Payor');
        $cards = self::driver()->executeScript(<<<'JS'
            const cards = [...document.querySelectorAll('.payor-columns article')].map((card) => {
                const rect = card.getBoundingClientRect();
                return { top: rect.top, left: rect.left, width: rect.width };
            });
            return {
                count: cards.length,
                first: cards[0] ?? null,
                second: cards[1] ?? null,
                third: cards[2] ?? null,
                last: cards[cards.length - 1] ?? null,
            };
        JS);
        self::assertIsArray($cards);
        self::assertGreaterThan(2, $cards['count']);
        self::assertEqualsWithDelta($cards['first']['top'], $cards['second']['top'], 2);
        self::assertGreaterThan($cards['first']['left'], $cards['second']['left']);
        self::assertGreaterThan($cards['first']['top'], $cards['third']['top']);
        self::assertEqualsWithDelta($cards['first']['left'], $cards['third']['left'], 2);
        if ($cards['count'] % 2 === 1) {
            self::assertEqualsWithDelta($cards['first']['left'], $cards['last']['left'], 2);
            self::assertEqualsWithDelta($cards['first']['width'], $cards['last']['width'], 2);
        }
        self::driver()->findElement(WebDriverBy::xpath('//article[contains(., "Averaged Payor")]//button[normalize-space()="Payroll"]'))->click();
        self::waitForText('Payroll · Averaged Payor');
        self::waitForText('$4,000.00');
        self::click('Close');
        self::waitForText('Biweekly Payor');
        self::waitForText('Semimonthly Payor');
        self::waitForText('Monthly Payor');
        self::waitForText('$7,000.00');
        self::waitForText('Monthly Weekday Payor');
        self::waitForText('$19,360.00');
        self::clickLink('Monthly Weekday Payor');
        self::waitForText('22 weekdays in Oct 2026');
        self::waitForText('176.00 hours');
        self::waitForText('Paycheck this period $19,360.00');
        self::waitForText('Hours per weekday');
        $hours = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="hours"]');
            return input ? input.value : '';
        JS);
        self::assertSame('8.00', $hours);
        $body = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();
        self::assertStringNotContainsString('2026-10', $body);
        self::assertStringNotContainsString('2026-11-01', $body);
        self::clickLink('Income');
        self::waitForText('Salary');
        self::waitForText('Irregular Payor');
        self::waitForTextGone('Archived Payor');
        self::click('Show archived');
        self::waitForText('Archived Payor');
        self::clickLink('Monthly Payor');
        self::waitForText('Based on payday Nov 1, 2026');
        self::waitForText('Nov 1, 2026 – Nov 30, 2026');
        self::waitForText('Rolls on Dec 1, 2026.');
        self::waitForText('Paycheck this period $7,000.00');
        $payday = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="payday"]');
            return input ? input.value : '';
        JS);
        self::assertSame('11 / 01 / 2026', $payday);
    }

    #[Depends('testDemoListsACycleForEachPayor')]
    public function testANewPayorGetsItsOwnCycle(): void
    {
        self::clickLink('Income');
        self::clickLink('New pay cycle');
        self::click('Save pay cycle');
        self::waitForText('Use a name of 1 to 80 characters.');
        self::type('payor', 'Scratch Payor');
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="payday"]');
            input.value = '11 / 01 / 2026';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        JS);
        self::click('Semimonthly');
        self::waitForText('Second day');
        self::click('Save pay cycle');
        self::waitForText('Use a day from 1 to 31.');
        self::type('secondDay', '15');
        self::click('Fixed');
        self::type('rate', '1750.00');
        self::click('Save pay cycle');
        self::waitForText('Scratch Payor');
        self::waitForText('$1,750.00');
        self::waitForText('Weekly Payor');
        self::clickLink('Scratch Payor');
        self::click('Archive');
        self::waitForText('Archive this pay cycle?');
        self::click('Cancel');
        self::waitForText('Scratch Payor');
        self::click('Archive');
        self::click('Archive pay cycle');
        self::waitForText('Weekly Payor');
        self::waitForTextGone('Scratch Payor');
        self::click('Show archived');
        self::waitForText('Scratch Payor');
    }

    #[Depends('testANewPayorGetsItsOwnCycle')]
    public function testTheOwnerDoesNotSeeDemoPayors(): void
    {
        self::click('Log out');
        self::waitForText('Unlock');
        self::type('name', 'Ada');
        self::type('password', self::PASSWORD);
        self::click('Unlock');
        self::waitForText('Net worth');
        self::clickLink('Income');
        self::waitForText('No pay cycles yet.');
        self::waitForTextGone('Weekly Payor');
        self::waitForTextGone('Scratch Payor');
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

    private static function clickLinkContaining(string $label): void
    {
        $by = WebDriverBy::xpath('//a[contains(normalize-space(.), ' . self::quote($label) . ')]');
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
