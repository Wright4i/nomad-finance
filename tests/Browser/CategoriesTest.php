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

final class CategoriesTest extends TestCase
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

    public function testDemoListsOneCategoryInEachGroup(): void
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
        self::clickLink('Envelopes');
        self::clickLink('Add Bucket');
        self::clickLink('Cancel');
        self::waitForText('Needs 1');
        self::waitForText('Wants 1');
        self::waitForText('Savings 1');
        self::waitForText('Cover now');
        self::waitForText('Carry');
        self::waitForText('Needed By Date 1');
        self::waitForText('Set aside $300.00 a month · 4 months until Feb 1, 2027');
        self::waitForText('Still $500.00 to hold');
        self::waitForTextGone('Wishlist');
        self::clickLinkContaining('Wants 1');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const button = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Carry');
                return name && name.value === 'Wants 1' && button && button.getAttribute('aria-pressed') === 'true';
            JS);

            return $ready === true;
        });
        self::clickLink('Envelopes');
        self::clickLink('Add Bucket');
        self::clickLink('Cancel');
        self::waitForText('Buckets');
        self::waitForText('Needs 1');
        self::waitForTextGone('Archived Category');
        $colors = self::driver()->executeScript(<<<'JS'
            const color = (label) => {
                const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === label);
                return heading ? getComputedStyle(heading).color : '';
            };
            return [color('Needs'), color('Wants'), color('Savings')];
        JS);
        self::assertIsArray($colors);
        self::assertCount(3, $colors);
        self::assertNotSame('', $colors[0]);
        self::assertNotSame($colors[0], $colors[1]);
        self::assertNotSame($colors[0], $colors[2]);
        self::assertNotSame($colors[1], $colors[2]);

        self::clickLinkContaining('Needed By Date 1');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const button = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Needed by date');
                const date = document.querySelector('input[name="targetDate"]');
                const amount = document.querySelector('input[name="targetAmount"]');
                const cycle = document.querySelector('select[name="payCycleId"]');
                const selected = cycle && cycle.selectedOptions[0] ? cycle.selectedOptions[0].textContent : '';
                const wishlist = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Wishlist');
                return name && name.value === 'Needed By Date 1'
                    && button && button.getAttribute('aria-pressed') === 'true'
                    && !wishlist
                    && date && date.value === '02 / 01 / 2027'
                    && amount && amount.value === '1200.00'
                    && selected.includes('Monthly Payor');
            JS);

            return $ready === true;
        });
        self::clickLink('Envelopes');
        self::clickLink('Add Bucket');
        self::clickLink('Cancel');
        self::waitForText('Savings 1');

        $button = self::driver()->findElement(WebDriverBy::linkText('New bucket'));
        $resting = $button->getCSSValue('background-color');
        self::driver()->action()->moveToElement($button)->perform();
        $hovered = self::wait()->until(function () use ($button, $resting): string|false {
            $color = $button->getCSSValue('background-color');

            return $color !== $resting ? $color : false;
        });
        self::assertIsString($hovered);

        self::click('Show archived');
        self::waitForText('Archived Category');
        self::click('Hide archived');
        self::waitForTextGone('Archived Category');
    }

    #[Depends('testDemoListsOneCategoryInEachGroup')]
    public function testANewGoalHasNoFuelCheckboxAndADefaultOnlyEditsTheAmount(): void
    {
        self::clickLink('New bucket');
        $fuel = self::driver()->executeScript(
            'return document.querySelector(\'input[name="fuel"]\') === null;',
        );
        self::assertTrue($fuel);
        self::clickLink('Cancel');
        self::clickLinkContaining('Rent');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const amount = document.querySelector('input[name="targetAmount"]');
                const archive = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Archive');
                return !name && !amount && !archive;
            JS);

            return $ready === true;
        });
        self::clickLink('Envelopes');
        self::clickLink('Add Bucket');
        self::clickLink('Cancel');
        self::waitForText('Buckets');
    }

    #[Depends('testDemoListsOneCategoryInEachGroup')]
    public function testANewCategoryCanBeArchivedFromAModal(): void
    {
        self::clickLink('New bucket');
        self::type('name', 'Trip 1');
        self::click('Wants');
        self::click('Save bucket');
        self::waitForText('Trip 1');
        $placed = self::driver()->executeScript(<<<'JS'
            const section = (label) => {
                const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === label);
                return heading ? heading.closest('section').innerText : '';
            };
            return section('Wants').includes('Trip 1') && !section('Needs').includes('Trip 1');
        JS);
        self::assertTrue($placed === true);

        self::clickLinkContaining('Trip 1');
        self::click('Archive');
        self::waitForText('Archive this bucket?');
        self::click('Cancel');
        self::waitForTextGone('Archive this bucket?');
        self::click('Archive');
        self::click('Archive bucket');
        self::waitForText('Needs 1');
        self::waitForTextGone('Trip 1');
        self::click('Show archived');
        self::waitForText('Trip 1');
        $marked = self::driver()->executeScript(<<<'JS'
            const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes('Trip 1'));
            return link ? link.innerText.toLowerCase().includes('archived') : false;
        JS);
        self::assertTrue($marked === true);
    }

    #[Depends('testANewCategoryCanBeArchivedFromAModal')]
    public function testUnarchivePutsTheNameBack(): void
    {
        self::clickLinkContaining('Trip 1');
        self::click('Unarchive');
        self::waitForText('Wants 1');
        self::waitForText('Trip 1');
        $category = self::driver()->executeScript(<<<'JS'
            const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes('Trip 1'));
            return link ? !link.innerText.toLowerCase().includes('archived') : false;
        JS);
        self::assertTrue($category === true);

        self::clickLink('Accounts');
        self::click('Show archived');
        self::waitForText('Checking 2');
        self::clickLinkContaining('Checking 2');
        self::click('Unarchive');
        self::waitForText('Checking 1');
        self::waitForText('Checking 2');
        $accountColors = self::driver()->executeScript(<<<'JS'
            const color = (name) => {
                const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes(name));
                const mark = link ? link.querySelector('.glyph') : null;
                return mark ? getComputedStyle(mark).color : '';
            };
            return [color('Checking 1'), color('Savings 1'), color('Card 1'), color('Cash 1'), color('Loan 1')];
        JS);
        self::assertIsArray($accountColors);
        self::assertCount(5, $accountColors);
        self::assertSame(5, count(array_unique($accountColors)));
        $account = self::driver()->executeScript(<<<'JS'
            const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes('Checking 2'));
            return link ? !link.innerText.toLowerCase().includes('archived') : false;
        JS);
        self::assertTrue($account === true);
    }

    #[Depends('testUnarchivePutsTheNameBack')]
    public function testABlankNameStaysOnTheForm(): void
    {
        self::driver()->get(self::$base . '/categories/new');
        self::click('Save bucket');
        self::waitForText('Use a name of 1 to 80 characters.');
    }

    #[Depends('testABlankNameStaysOnTheForm')]
    public function testUnpayRestoresAOneOffAndPauseRemains(): void
    {
        self::driver()->get(self::$base . '/bills');
        self::waitForText('Pause');
        self::waitForText('Paid One-Time Bill');
        self::waitForTextGone('Stop reminding');
        $billColors = self::driver()->executeScript(<<<'JS'
            const heading = (label) => {
                const node = [...document.querySelectorAll('h2')].find((item) => item.textContent.trim() === label);
                return node ? getComputedStyle(node).color : '';
            };
            const mark = (name) => {
                const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes(name));
                const glyph = link ? link.querySelector('.glyph') : null;
                return glyph ? getComputedStyle(glyph).color : '';
            };
            return [heading('Active'), heading('Paused'), heading('Paid'), mark('Tax Bill'), mark('Recurring Monthly Bill'), mark('Recurring Yearly Bill')];
        JS);
        self::assertIsArray($billColors);
        self::assertCount(6, $billColors);
        self::assertSame(6, count(array_unique($billColors)));
        self::driver()->findElement(WebDriverBy::xpath('//article[contains(., "Paid One-Time Bill")]//button[normalize-space()="Unpay"]'))->click();
        $moved = self::wait()->until(function (): bool {
            $placed = self::driver()->executeScript(<<<'JS'
                const section = (label) => {
                    const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === label);
                    return heading && heading.closest('section') ? heading.closest('section').innerText : '';
                };
                return section('Active').includes('Paid One-Time Bill') && !section('Paid').includes('Paid One-Time Bill');
            JS);

            return $placed === true;
        });
        self::assertTrue($moved);
        self::driver()->findElement(WebDriverBy::xpath('//article[contains(., "Paid One-Time Bill")]//button[normalize-space()="Pause"]'))->click();
        $paused = self::wait()->until(function (): bool {
            $placed = self::driver()->executeScript(<<<'JS'
                const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === 'Paused');
                const section = heading && heading.closest('section') ? heading.closest('section').innerText : '';
                return section.includes('Paid One-Time Bill');
            JS);

            return $placed === true;
        });
        self::assertTrue($paused);
    }

    #[Depends('testUnpayRestoresAOneOffAndPauseRemains')]
    public function testTheOwnerDoesNotSeeDemoCategories(): void
    {
        self::click('Log out');
        self::waitForText('Unlock');
        self::type('name', 'Ada');
        self::type('password', self::PASSWORD);
        self::click('Unlock');
        self::waitForText('Net worth');
        self::clickLink('Envelopes');
        self::clickLink('Add Bucket');
        self::clickLink('Cancel');
        self::waitForText('Rent');
        self::waitForTextGone('Needs 1');
        self::waitForTextGone('Trip 1');
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
