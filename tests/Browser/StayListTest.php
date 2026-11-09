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

final class StayListTest extends TestCase
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
            '--use-angle=swiftshader',
            '--enable-unsafe-swiftshader',
            '--ignore-gpu-blocklist',
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

    public function testTheStayListDropsTheYearAndChipsTheFacts(): void
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
        self::clickLink('Trips');
        self::waitForText('Previous month');
        self::openTripMonth('2026-11');
        self::waitForText('Lost Dutchman');

        $lost = self::stayRow('Lost Dutchman');
        self::assertSame('Nov 20 – Nov 24', $lost['dates']);
        self::assertFalse($lost['dateIsChip']);
        self::assertSame('Lost Dutchman', $lost['place']);
        self::assertSame('Site 18', $lost['note']);
        self::assertSame('Apache Junction, AZ', $lost['locality']);
        self::assertSame('', $lost['status']);
        self::assertSame('balance due Nov 22', $lost['due']);
        self::assertSame([], $lost['stray']);
        self::assertStringNotContainsString('2026', $lost['text']);
        self::assertStringNotContainsString('2027', $lost['text']);
        self::assertTrue($lost['moneyInherits'], json_encode($lost['money']));
        if ($lost['miles'] !== '') {
            self::assertMatchesRegularExpression('/^\d+ miles$/', $lost['miles']);
        }

        self::openTripMonth('2026-12');
        self::waitForText('La Posa South');
        $year = self::stayRow('La Posa South');
        self::assertSame('Dec 12 – Jan 8', $year['dates']);
        self::assertFalse($year['dateIsChip']);
        self::assertSame('La Posa South', $year['place']);
        self::assertSame('South row', $year['note']);
        self::assertSame('Quartzsite, AZ', $year['locality']);
        self::assertSame([], $year['stray']);
        self::assertStringNotContainsString('2026', $year['text']);
        self::assertStringNotContainsString('2027', $year['text']);
        self::assertTrue($year['moneyInherits'], json_encode($year['money']));

        self::openTripMonth('2026-09');
        self::waitForText('Devils Garden');
        $fly = self::stayRow('Devils Garden');
        self::assertSame('Flight', $fly['flight']);
    }

    /** @return array<string, mixed> */
    private static function stayRow(string $name): array
    {
        $row = self::driver()->executeScript(<<<'JS'
            const row = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(arguments[0]));
            if (!row) return null;
            const dates = row.querySelector('[data-stay-dates]');
            const chip = (key) => {
                const node = row.querySelector(`[data-chip="${key}"]`);
                return node ? node.textContent.replace(/\s+/g, ' ').trim() : '';
            };
            const group = row.querySelector('.bill-detail-chips');
            const children = group ? [...group.children] : [];
            const stray = children
                .filter((node) => node !== dates && !node.classList.contains('bill-detail-chip') && !node.classList.contains('stay-chip'))
                .map((node) => node.textContent.trim());
            const money = [...row.querySelectorAll('.money')].map((node) => {
                const host = node.closest('.bill-detail-chip, .stay-chip, .stay-amount-chip');
                if (!host) return { inChip: false, inherits: false };
                const ink = getComputedStyle(node);
                const chipInk = getComputedStyle(host);
                return {
                    inChip: true,
                    inherits: ink.color === chipInk.color && ink.fontSize === chipInk.fontSize && ink.fontFamily === chipInk.fontFamily,
                };
            });
            return {
                dates: dates ? dates.textContent.replace(/\s+/g, ' ').trim() : '',
                dateIsChip: Boolean(dates && (dates.classList.contains('bill-detail-chip') || dates.classList.contains('stay-chip'))),
                place: chip('place'),
                note: chip('note'),
                locality: chip('locality'),
                status: chip('status'),
                due: chip('due'),
                flight: chip('flight'),
                miles: chip('miles'),
                stray,
                money,
                moneyInherits: money.every((item) => item.inChip && item.inherits),
                text: (row.innerText || '').replace(/\s+/g, ' ').trim(),
            };
        JS, [$name]);
        self::assertIsArray($row, $name . ' is missing from the stay list');

        return $row;
    }

    private static function openTripMonth(string $month): void
    {
        for ($step = 0; $step < 36; $step++) {
            $current = self::driver()->executeScript(<<<'JS'
                const node = document.querySelector('[data-month]');
                return node ? node.getAttribute('data-month') : '';
            JS);
            if ($current === $month) {
                return;
            }
            if (!is_string($current) || $current === '') {
                throw new RuntimeException('The trip month is missing.');
            }
            self::click($current > $month ? 'Previous month' : 'Next month');
        }

        throw new RuntimeException('Could not reach ' . $month);
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

    #[Depends('testTheStayListDropsTheYearAndChipsTheFacts')]
    public function testANewStayStartsOnTheShoppingFavorite(): void
    {
        self::clickLink('Add a stay');
        self::assertSame('Travel card', self::selectedStayAccount());
    }

    private static function selectedStayAccount(): string
    {
        $value = self::wait()->until(function (): string|false {
            $text = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector('select[name="accountId"]');
                const option = select && select.selectedOptions[0];
                const label = option ? option.textContent.trim() : '';
                return label !== '' && label !== 'None' ? label : '';
            JS);

            return is_string($text) && $text !== '' ? $text : false;
        });
        self::assertIsString($value);

        return $value;
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
