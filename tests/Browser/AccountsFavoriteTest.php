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

final class AccountsFavoriteTest extends TestCase
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

    public function testFavoritesSitOnTheTopRowAndStartThePayFromLists(): void
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
        self::waitForText('Fuel card');
        $board = self::board();
        self::assertTrue($board['rule']);
        self::assertSame(['Fuel card', 'Travel card', 'Emergency fund'], $board['top']);
        self::assertContains('Wallet', $board['below']);
        self::assertContains('Everyday checking', $board['below']);
        self::assertSame('true', self::pressed('Fuel card', 'gas'));
        self::assertSame('true', self::pressed('Travel card', 'shopping'));
        self::assertSame('true', self::pressed('Emergency fund', 'asset'));
        self::assertNull(self::mark('Wallet', 'asset'));
        self::assertFavoriteMarks();

        self::clickLink('Transfers');
        self::click('Between accounts');
        self::waitForText('From');
        self::wait()->until(function (): bool {
            return self::selected('moveSource') === 'Emergency fund | $8,600.00';
        });

        self::clickLink('Accounts');
        self::waitForText('Emergency fund');
        self::clickMark('Emergency fund', 'asset');
        self::wait()->until(function (): bool {
            $board = self::board();

            return !in_array('Emergency fund', $board['top'], true) && self::mark('Wallet', 'asset') !== null;
        });
        self::clickMark('Wallet', 'asset');
        self::wait()->until(function (): bool {
            $board = self::board();

            return in_array('Wallet', $board['top'], true)
                && !in_array('Emergency fund', $board['top'], true)
                && self::pressed('Wallet', 'asset') === 'true';
        });
        self::assertNull(self::mark('Emergency fund', 'asset'));

        self::clickLink('New log');
        self::waitForText('New log');
        self::waitForAccount('Fuel card');
        self::click('Want');
        self::waitForAccount('Travel card');
        self::assertSame(
            [],
            self::driver()->findElements(WebDriverBy::xpath('//button[normalize-space()="Saving"]')),
        );

        self::clickLink('Transfers');
        self::click('Between accounts');
        self::waitForText('From');
        self::wait()->until(function (): bool {
            return self::selected('moveSource') === 'Wallet | $86.00';
        });
    }

    #[Depends('testFavoritesSitOnTheTopRowAndStartThePayFromLists')]
    public function testAnArchivedAccountCanBeDeleted(): void
    {
        self::driver()->get(self::$base . '/accounts/new');
        self::waitForText('Save account');
        self::type('name', 'Scratch jar');
        self::type('balance', '1.00');
        self::click('Save account');
        self::waitForText('Scratch jar');
        self::clickAccount('Scratch jar');
        self::waitForText('Archive');
        self::click('Archive');
        self::waitForText('Everyday checking');
        self::waitForTextGone('Scratch jar');
        self::click('Show archived');
        self::waitForText('Scratch jar');
        self::waitForText('Closed checking');
        self::clickAccountDelete('Scratch jar');
        self::waitForText('Delete this account?');
        self::assertSame('/accounts', (string) parse_url((string) self::driver()->getCurrentURL(), PHP_URL_PATH));
        self::click('Cancel');
        self::waitForTextGone('Delete this account?');
        self::waitForText('Scratch jar');
        self::waitForText('Closed checking');
        self::clickAccount('Scratch jar');
        self::waitForText('Unarchive');
        self::click('Delete');
        self::waitForText('Delete this account?');
        self::click('Cancel');
        self::waitForTextGone('Delete this account?');
        self::waitForText('Unarchive');
        self::click('Delete');
        self::click('Delete account');
        self::waitForText('Everyday checking');
        self::waitForTextGone('Scratch jar');
        self::click('Show archived');
        self::waitForText('Closed checking');
        self::waitForTextGone('Scratch jar');
        self::assertSame('/accounts', (string) parse_url((string) self::driver()->getCurrentURL(), PHP_URL_PATH));
    }

    #[Depends('testAnArchivedAccountCanBeDeleted')]
    public function testLoanEditShowsDebtAndVehicleBuckets(): void
    {
        self::driver()->get(self::$base . '/accounts/new');
        self::waitForText('Save account');
        self::click('loan');
        self::waitForText('Bucket');
        $select = self::driver()->findElements(WebDriverBy::name('liabilityBillType'));
        self::assertCount(1, $select);
        $options = $select[0]->findElements(WebDriverBy::cssSelector('option'));
        self::assertCount(2, $options);
        self::assertSame('Debt', trim($options[0]->getText()));
        self::assertSame('Vehicle', trim($options[1]->getText()));

        self::click('credit');
        self::waitForText('Autopay is on at the bank');
        self::assertCount(0, self::driver()->findElements(WebDriverBy::name('liabilityBillType')));
    }

    private static function assertFavoriteMarks(): void
    {
        $marks = self::driver()->executeScript(<<<'JS'
            const tile = (name) => [...document.querySelectorAll('.account-tile')].find((node) => {
                const title = node.querySelector('strong');
                return title && title.textContent.trim() === name;
            });
            const icon = (name, kind) => {
                const button = tile(name)?.querySelector(`[data-favorite="${kind}"]`);
                const path = button?.querySelector('path');
                const glyph = button?.querySelector('.glyph');
                const color = glyph ? getComputedStyle(glyph).color : '';
                return { path: path ? path.getAttribute('d') : '', color };
            };
            const budget = tile('Everyday checking')?.querySelector('.account-budget path');
            return {
                gas: icon('Fuel card', 'gas'),
                shopping: icon('Travel card', 'shopping'),
                asset: icon('Emergency fund', 'asset'),
                budget: budget ? budget.getAttribute('d') : '',
            };
        JS);
        self::assertIsArray($marks);
        self::assertStringContainsString('M12.5 12h2.4', (string) ($marks['asset']['path'] ?? ''));
        self::assertStringContainsString('M4.5 7.5h15', (string) ($marks['budget'] ?? ''));
        self::assertSame('rgb(255, 255, 255)', $marks['gas']['color'] ?? null);
        self::assertSame('rgb(255, 255, 255)', $marks['shopping']['color'] ?? null);
        self::assertSame('rgb(255, 255, 255)', $marks['asset']['color'] ?? null);

        foreach (['gas' => 'Default Fuel', 'shopping' => 'Default Shopping', 'asset' => 'Default Asset'] as $kind => $label) {
            $name = $kind === 'gas' ? 'Fuel card' : ($kind === 'shopping' ? 'Travel card' : 'Emergency fund');
            self::driver()->executeScript(<<<'JS'
                const name = arguments[0];
                const kind = arguments[1];
                const tile = [...document.querySelectorAll('.account-tile')].find((node) => {
                    const title = node.querySelector('strong');
                    return title && title.textContent.trim() === name;
                });
                document.querySelectorAll('[data-favorite]').forEach((button) => {
                    button.dispatchEvent(new MouseEvent('mouseleave'));
                });
                tile.querySelector(`[data-favorite="${kind}"]`).dispatchEvent(new MouseEvent('mouseenter'));
            JS, [$name, $kind]);
            self::wait()->until(function () use ($label): bool {
                $text = self::driver()->executeScript(<<<'JS'
                    const tip = document.querySelector('.nav-tip');
                    return tip ? tip.textContent : '';
                JS);

                return is_string($text) && trim($text) === $label;
            });
        }
    }

    /** @return array{top:list<string>,below:list<string>,rule:bool} */
    private static function board(): array
    {
        $board = self::driver()->executeScript(<<<'JS'
            const names = (board) => [...board.querySelectorAll('.account-id-text strong')].map((node) => node.textContent.trim());
            const boards = [...document.querySelectorAll('.account-board')];
            return {
                top: boards[0] ? names(boards[0]) : [],
                below: boards[1] ? names(boards[1]) : [],
                rule: document.querySelector('.account-star-rule') !== null,
            };
        JS);
        self::assertIsArray($board);

        return [
            'top' => array_values(array_filter($board['top'] ?? [], 'is_string')),
            'below' => array_values(array_filter($board['below'] ?? [], 'is_string')),
            'rule' => ($board['rule'] ?? false) === true,
        ];
    }

    private static function pressed(string $name, string $kind): string
    {
        $pressed = self::mark($name, $kind);
        self::assertIsString($pressed);

        return $pressed;
    }

    private static function mark(string $name, string $kind): ?string
    {
        $pressed = self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const kind = arguments[1];
            const tile = [...document.querySelectorAll('.account-tile')].find((node) => {
                const title = node.querySelector('strong');
                return title && title.textContent.trim() === name;
            });
            const button = tile ? tile.querySelector(`[data-favorite="${kind}"]`) : null;
            return button ? button.getAttribute('aria-pressed') : null;
        JS, [$name, $kind]);

        return is_string($pressed) ? $pressed : null;
    }

    private static function clickAccount(string $name): void
    {
        self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const tile = [...document.querySelectorAll('.account-tile')].find((node) => {
                const title = node.querySelector('strong');
                return title && title.textContent.trim() === name;
            });
            tile.querySelector('a').click();
        JS, [$name]);
    }

    private static function clickAccountDelete(string $name): void
    {
        self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const tile = [...document.querySelectorAll('.account-tile')].find((node) => {
                const title = node.querySelector('strong');
                return title && title.textContent.trim() === name;
            });
            const button = [...tile.querySelectorAll('button')].find((node) => node.innerText.trim() === 'Delete');
            button.click();
        JS, [$name]);
    }

    private static function clickMark(string $name, string $kind): void
    {
        self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const kind = arguments[1];
            const tile = [...document.querySelectorAll('.account-tile')].find((node) => {
                const title = node.querySelector('strong');
                return title && title.textContent.trim() === name;
            });
            tile.querySelector(`[data-favorite="${kind}"]`).click();
        JS, [$name, $kind]);
    }

    private static function waitForAccount(string $label): void
    {
        self::wait()->until(function () use ($label): bool {
            return self::selected('accountId') === $label;
        });
    }

    private static function selected(string $name): string
    {
        $value = self::driver()->executeScript(<<<'JS'
            const select = document.querySelector(`select[name="${arguments[0]}"]`);
            if (!select || select.selectedIndex < 0) return '';
            return select.options[select.selectedIndex].textContent.trim();
        JS, [$name]);

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
