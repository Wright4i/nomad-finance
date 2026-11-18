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
        self::waitForText('Day job');
        self::waitForText('Side job');
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
        self::assertSame(2, $cards['count']);
        self::assertEqualsWithDelta($cards['first']['top'], $cards['second']['top'], 2);
        self::assertGreaterThan($cards['first']['left'], $cards['second']['left']);
        $card = self::driver()->executeScript(<<<'JS'
            const card = [...document.querySelectorAll('.payor-columns article')].find((node) => node.textContent.includes('Day job'));
            const weekly = [...document.querySelectorAll('.payor-columns article')].find((node) => node.textContent.includes('Side job'));
            const range = card.querySelector('.pay-period-range');
            const name = card.querySelector('.pay-card-head strong');
            const button = card.querySelector('.pay-card-head button');
            const weeklyRange = weekly.querySelector('.pay-period-range');
            const nameBox = name.getBoundingClientRect();
            const buttonBox = button.getBoundingClientRect();
            return {
                range: range.textContent.trim(),
                weeklyRange: weeklyRange.textContent.trim(),
                italic: getComputedStyle(range).fontStyle,
                button: button.textContent.trim(),
                background: getComputedStyle(button).backgroundColor,
                buttonRightOfName: buttonBox.left >= nameBox.right - 1,
                sameRow: Math.abs((buttonBox.top + buttonBox.bottom) / 2 - (nameBox.top + nameBox.bottom) / 2) < 24,
                basedOnPayday: card.textContent.includes('Based on payday'),
                chips: [...card.querySelectorAll('.pay-chip')].map((node) => node.textContent.trim()),
                weeklyChips: [...weekly.querySelectorAll('.pay-chip')].map((node) => node.textContent.trim()),
            };
        JS);
        self::assertIsArray($card);
        self::assertSame('Nov 1 - Nov 30 | $8,333.33', $card['range']);
        self::assertSame('Nov 16 - Nov 30 | $440.00', $card['weeklyRange']);
        self::assertSame('normal', $card['italic']);
        self::assertSame('Log Payroll', $card['button']);
        self::assertSame('rgba(0, 0, 0, 0)', $card['background']);
        self::assertTrue($card['buttonRightOfName']);
        self::assertTrue($card['sameRow']);
        self::assertFalse($card['basedOnPayday']);
        self::assertSame(
            ['Roll Dec 1', 'Salary', 'Monthly $8,333.33'],
            $card['chips'],
        );
        self::assertNotContains('Check Amount $192.00', $card['weeklyChips']);
        self::assertContains('Monthly $880.00', $card['weeklyChips']);
        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        try {
            self::wait()->until(function (): bool {
                $stacked = self::driver()->executeScript(<<<'JS'
                    const cards = [...document.querySelectorAll('.payor-columns article')];
                    if (cards.length < 2) return false;
                    const first = cards[0].getBoundingClientRect();
                    const second = cards[1].getBoundingClientRect();
                    return Math.abs(first.left - second.left) < 2 && second.top >= first.bottom - 1;
                JS);

                return $stacked === true;
            });
            $phone = self::driver()->executeScript(<<<'JS'
                const columns = document.querySelector('.payor-columns').getBoundingClientRect();
                const cards = [...document.querySelectorAll('.payor-columns article')].map((card) => {
                    const rect = card.getBoundingClientRect();
                    const name = card.querySelector('.pay-card-head strong').getBoundingClientRect();
                    const button = card.querySelector('.pay-card-head button').getBoundingClientRect();
                    const range = card.querySelector('.pay-period-range').getBoundingClientRect();
                    const chips = [...card.querySelectorAll('.pay-chip')].map((node) => node.getBoundingClientRect());
                    const nameMid = (name.top + name.bottom) / 2;
                    const buttonMid = (button.top + button.bottom) / 2;
                    const contents = [name, button, range, ...chips];
                    return {
                        top: rect.top,
                        bottom: rect.bottom,
                        left: rect.left,
                        right: rect.right,
                        width: rect.width,
                        scrollWidth: card.scrollWidth,
                        clientWidth: card.clientWidth,
                        buttonRight: button.right,
                        buttonLeft: button.left,
                        buttonTop: button.top,
                        buttonBottom: button.bottom,
                        beside: button.left >= name.right - 1 && Math.abs(buttonMid - nameMid) < 24,
                        below: button.top >= name.bottom - 1,
                        inside: contents.every((box) => box.left >= rect.left - 1 && box.right <= rect.right + 1 && box.top >= rect.top - 1 && box.bottom <= rect.bottom + 1),
                    };
                });
                return { columnsWidth: columns.width, cards };
            JS);
            self::assertIsArray($phone);
            self::assertIsArray($phone['cards']);
            self::assertGreaterThan(1, count($phone['cards']));
            $previousBottom = null;
            foreach ($phone['cards'] as $index => $row) {
                self::assertIsArray($row);
                self::assertEqualsWithDelta($phone['cards'][0]['left'], $row['left'], 2);
                self::assertEqualsWithDelta($phone['columnsWidth'], $row['width'], 2);
                if ($previousBottom !== null) {
                    self::assertGreaterThanOrEqual($previousBottom - 1, $row['top']);
                }
                $previousBottom = $row['bottom'];
                self::assertLessThanOrEqual($row['clientWidth'] + 1, $row['scrollWidth']);
                self::assertLessThanOrEqual($row['right'] + 1, $row['buttonRight']);
                self::assertGreaterThanOrEqual($row['left'], $row['buttonLeft']);
                self::assertLessThanOrEqual($row['bottom'] + 1, $row['buttonBottom']);
                self::assertGreaterThanOrEqual($row['top'], $row['buttonTop']);
                self::assertTrue($row['beside'] || $row['below']);
                self::assertTrue($row['inside']);
            }
        } finally {
            self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        }
        $open = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();
        self::assertStringNotContainsString('Shop days', $open);
        self::assertStringNotContainsString('Warehouse shift', $open);
        self::click('Show archived');
        self::waitForText('Warehouse shift');
        self::driver()->findElement(WebDriverBy::xpath('//article[contains(., "Month average")]//button[normalize-space()="Log Payroll"]'))->click();
        self::waitForText('Payroll · Month average');
        self::waitForText('$950.00');
        self::click('Close');
        self::waitForText('Shop days');
        self::clickLinkContaining('Shop days');
        self::waitForText('22 weekdays in Oct 2026');
        self::waitForText('66.00 hours');
        self::waitForText('Paycheck this period $1,320.00');
        self::waitForText('Hours per weekday');
        $hours = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="hours"]');
            return input ? input.value : '';
        JS);
        self::assertSame('3.00', $hours);
        $body = self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText();
        self::assertStringNotContainsString('2026-10', $body);
        self::assertStringNotContainsString('2026-11-01', $body);
        self::clickLink('Income');
        self::waitForText('Day job');
        self::clickLink('Day job');
        self::waitForText('Based on payday Nov 1, 2026');
        self::waitForText('Nov 1, 2026 - Nov 30, 2026');
        self::waitForText('Rolls on Dec 1, 2026.');
        self::waitForText('Paycheck this period $8,333.33');
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
        self::waitForText('Side job');
        self::clickLink('Scratch Payor');
        self::click('Archive');
        self::waitForText('Archive this pay cycle?');
        self::click('Cancel');
        self::waitForText('Scratch Payor');
        self::click('Archive');
        self::click('Archive pay cycle');
        self::waitForText('Side job');
        self::waitForTextGone('Scratch Payor');
        self::click('Show archived');
        self::waitForText('Scratch Payor');
    }

    #[Depends('testANewPayorGetsItsOwnCycle')]
    public function testAnArchivedPayCycleCanBeDeleted(): void
    {
        self::clickLink('Income');
        self::clickLink('New pay cycle');
        self::type('payor', 'Side stipend');
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="payday"]');
            input.value = '11 / 01 / 2026';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        JS);
        self::click('Fixed');
        self::type('rate', '640.00');
        self::click('Save pay cycle');
        self::waitForText('Side stipend');
        self::clickLink('Side stipend');
        self::click('Archive');
        self::click('Archive pay cycle');
        self::waitForText('Side job');
        self::waitForTextGone('Side stipend');
        self::click('Show archived');
        self::waitForText('Side stipend');
        self::waitForText('Warehouse shift');
        self::waitForText('Scratch Payor');
        $hasDelete = self::driver()->executeScript(<<<'JS'
            const card = [...document.querySelectorAll('.payor-columns article')].find((node) => node.innerText.includes('Side stipend'));
            const button = card ? [...card.querySelectorAll('.pay-card-actions button')].find((node) => node.innerText.trim() === 'Delete') : null;
            const link = card ? card.querySelector('a') : null;
            return button !== null && link !== null && !link.contains(button);
        JS);
        self::assertTrue($hasDelete === true);
        self::driver()->executeScript(<<<'JS'
            const card = [...document.querySelectorAll('.payor-columns article')].find((node) => node.innerText.includes('Side stipend'));
            const button = [...card.querySelectorAll('.pay-card-actions button')].find((node) => node.innerText.trim() === 'Delete');
            button.click();
        JS);
        self::waitForText('Delete this pay cycle?');
        self::click('Cancel');
        self::waitForTextGone('Delete this pay cycle?');
        self::waitForText('Side stipend');
        self::clickLinkContaining('Side stipend');
        self::waitForText('Unarchive');
        self::click('Delete');
        self::waitForText('Delete this pay cycle?');
        self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog[open]');
            const cancel = dialog ? [...dialog.querySelectorAll('button')].find((node) => node.innerText.trim() === 'Cancel') : null;
            cancel?.click();
        JS);
        self::waitForTextGone('Delete this pay cycle?');
        self::waitForText('Unarchive');
        self::click('Delete');
        self::click('Delete pay cycle');
        self::waitForText('Side job');
        self::waitForTextGone('Side stipend');
        self::click('Show archived');
        self::waitForText('Warehouse shift');
        self::waitForText('Scratch Payor');
        self::waitForTextGone('Side stipend');
    }

    #[Depends('testAnArchivedPayCycleCanBeDeleted')]
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
        self::waitForTextGone('Side job');
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
