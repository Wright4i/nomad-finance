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

final class BillsTest extends TestCase
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

    public function testDemoShowsEachBillKindAndTheOtherName(): void
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
        self::clickLink('Planner');
        self::clickLink('Bills');
        foreach (['Phone', 'Clinic visit', 'DEF fluid', 'Camp store', 'Shower tokens'] as $name) {
            Reveal::bill(self::driver(), $name, 'active');
            self::waitForText($name);
        }
        self::driver()->executeScript(<<<'JS'
            const article = [...document.querySelectorAll('article')].find((node) => {
                const strong = node.querySelector('strong');
                return strong && strong.textContent.trim() === 'Phone';
            });
            const opener = article ? article.querySelector('button.bill-slim') : null;
            if (opener) opener.click();
        JS);
        $opened = self::wait()->until(function (): array|false {
            $value = self::driver()->executeScript(<<<'JS'
                const article = [...document.querySelectorAll('article')].find((node) => {
                    const strong = node.querySelector('strong');
                    return strong && strong.textContent.trim() === 'Phone';
                });
                if (!article || article.dataset.open !== 'true') return null;
                const chip = (name) => article.querySelector(`.bill-detail-chip[data-chip="${name}"]`);
                const paint = (node) => node ? getComputedStyle(node).color : '';
                return {
                    path: location.pathname,
                    open: article.dataset.open,
                    holder: chip('holder') ? chip('holder').textContent.trim() : '',
                    account: paint(chip('account')),
                    holderColor: paint(chip('holder')),
                    bucket: paint(chip('bucket')),
                    edit: [...article.querySelectorAll('button')].some((button) => button.textContent.trim() === 'Edit'),
                };
            JS);

            return is_array($value) ? $value : false;
        });
        self::assertIsArray($opened);
        self::assertSame('/bills', $opened['path']);
        self::assertSame('true', $opened['open']);
        self::assertSame('Demo', $opened['holder']);
        self::assertNotSame('', $opened['account']);
        self::assertNotSame($opened['account'], $opened['holderColor']);
        self::assertNotSame($opened['account'], $opened['bucket']);
        self::assertTrue($opened['edit']);
        self::click('Expand all');
        self::wait()->until(function (): bool {
            $count = self::driver()->executeScript(<<<'JS'
                return document.querySelectorAll('section[data-section="active"] article[data-open="true"]').length;
            JS);

            return is_int($count) && $count > 1;
        });
        self::click('Collapse');
        self::wait()->until(function (): bool {
            $count = self::driver()->executeScript(<<<'JS'
                return document.querySelectorAll('section[data-section="active"] article[data-open="true"]').length;
            JS);

            return $count === 0;
        });
        self::driver()->executeScript(<<<'JS'
            const article = [...document.querySelectorAll('article')].find((node) => {
                const strong = node.querySelector('strong');
                return strong && strong.textContent.trim() === 'Phone';
            });
            const edit = article ? [...article.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Edit') : null;
            if (edit) edit.click();
        JS);
        self::waitForText('Save bill');
        self::assertStringContainsString('/bills/', self::driver()->getCurrentURL());
        self::clickLink('Back');
        self::waitForText('Expand all');
        Reveal::bill(self::driver(), 'Shower tokens', 'active');
        self::waitForText('Shower tokens');
        $once = self::driver()->executeScript(<<<'JS'
            const article = [...document.querySelectorAll('article')].find((node) => {
                const strong = node.querySelector('strong');
                return strong ? strong.textContent.trim().startsWith('Shower tokens') : false;
            });
            const has = (label) => article ? [...article.querySelectorAll('button')].some((button) => button.textContent.trim() === label) : false;
            return { archive: has('Archive'), pause: has('Pause') };
        JS);
        self::assertIsArray($once);
        self::assertTrue($once['archive']);
        self::assertFalse($once['pause']);
        self::driver()->findElement(WebDriverBy::cssSelector('button[aria-label="Previous"]'))->click();
        self::waitForText('Oct 1');
        Reveal::bill(self::driver(), 'Old meal kit', 'archived');
        self::waitForText('Old meal kit');
        $archived = self::driver()->executeScript(<<<'JS'
            const article = [...document.querySelectorAll('article')].find((node) => {
                const strong = node.querySelector('strong');
                return strong ? strong.textContent.trim().startsWith('Old meal kit') : false;
            });
            const has = (label) => article ? [...article.querySelectorAll('button')].some((button) => button.textContent.trim() === label) : false;
            return { restore: has('Unarchive'), delete: has('Delete') };
        JS);
        self::assertIsArray($archived);
        self::assertTrue($archived['restore']);
        self::assertTrue($archived['delete']);
        self::driver()->findElement(WebDriverBy::cssSelector('button[aria-label="Next"]'))->click();
        self::waitForText('Nov 1');
        Reveal::bill(self::driver(), 'Pedestal', 'paused');
        Reveal::bill(self::driver(), 'Travel card', 'active');
        $actions = self::driver()->executeScript(<<<'JS'
            const article = (name) => [...document.querySelectorAll('article')].find((node) => {
                const strong = node.querySelector('strong');
                return strong ? strong.textContent.trim().startsWith(name) : false;
            });
            const has = (node, label) => node ? [...node.querySelectorAll('button')].some((button) => button.textContent.trim() === label) : false;
            return {
                pausedArchive: has(article('Pedestal'), 'Archive'),
                cardPause: has(article('Travel card'), 'Pause'),
            };
        JS);
        self::assertTrue($actions['pausedArchive']);
        self::assertFalse($actions['cardPause']);
        Reveal::bill(self::driver(), 'Health share', 'active');
        $day = self::driver()->executeScript(<<<'JS'
            const group = [...document.querySelectorAll('.bill-day')].find((node) => {
                const label = node.querySelector('.bill-date');
                return label && label.textContent.includes('Nov 20, 2026');
            });
            if (!group) return null;
            const date = group.querySelector('.bill-date').getBoundingClientRect();
            const rule = group.querySelector('.bill-date-rule').getBoundingClientRect();
            const cards = [...group.querySelectorAll('article')].slice(0, 2).map((card) => {
                const box = card.getBoundingClientRect();
                const money = card.querySelector('.money');
                return { top: box.top, left: box.left, money: money ? money.textContent.trim() : '' };
            });
            return {
                dateBottom: date.bottom,
                ruleTop: rule.top,
                ruleWidth: rule.width,
                cards,
            };
        JS);
        self::assertIsArray($day);
        self::assertGreaterThan($day['dateBottom'] - 1, $day['ruleTop']);
        self::assertGreaterThan(80, $day['ruleWidth']);
        self::assertCount(2, $day['cards']);
        self::assertGreaterThan($day['ruleTop'], $day['cards'][0]['top']);
        self::assertEqualsWithDelta($day['cards'][0]['top'], $day['cards'][1]['top'], 2);
        self::assertGreaterThan($day['cards'][0]['left'], $day['cards'][1]['left']);
        self::assertMatchesRegularExpression('/^-?\$[\d,]+\.\d{2}$/', $day['cards'][0]['money']);
        self::assertMatchesRegularExpression('/^-?\$[\d,]+\.\d{2}$/', $day['cards'][1]['money']);
        Reveal::bill(self::driver(), 'Shower tokens', 'active');
        self::openBill('Shower tokens');
        $kept = self::wait()->until(function (): string|false {
            $value = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="otherLabel"]');
                return input ? input.value : '';
            JS);

            return $value === 'Shower tokens' ? $value : false;
        });
        self::assertSame('Shower tokens', $kept);
    }

    #[Depends('testDemoShowsEachBillKindAndTheOtherName')]
    public function testOtherAsksForAName(): void
    {
        self::clickLink('Planner');
        self::clickLink('Bills');
        self::clickLink('New bill');
        $amount = self::driver()->findElement(WebDriverBy::xpath('//label[.//input[@name="amount"]]/span'));
        self::assertSame('help', $amount->getCssValue('cursor'));
        self::driver()->action()->moveToElement($amount)->perform();
        self::wait()->until(function (): bool {
            $text = self::driver()->executeScript(<<<'JS'
                const tip = document.querySelector('.nav-tip');
                return tip ? tip.textContent : '';
            JS);

            return is_string($text) && str_contains($text, 'Adds this to the bucket');
        });
        $amountField = self::driver()->findElement(WebDriverBy::name('amount'));
        $amountField->clear();
        $amountField->sendKeys('(20+5)*(20/2)');
        $amountField->sendKeys("\t");
        self::wait()->until(function (): bool {
            $value = self::driver()->findElement(WebDriverBy::name('amount'))->getAttribute('value');

            return $value === '250.00';
        });
        self::click('other');
        self::waitForText('Other name');
        self::click('cellular');
        self::waitForTextGone('Other name');
        self::click('other');
        self::waitForText('Other name');
        self::type('name', 'Scratch Bill');
        self::type('amount', '12');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(
                'const select = document.querySelector(\'select[name="accountId"]\'); return select ? select.value : "";',
            );

            return is_string($value) && $value !== '';
        });
        self::click('Save bill');
        self::waitForText('Use a name of 1 to 80 characters.');
        self::type('otherLabel', 'Parking');
        self::click('Save bill');
        Reveal::bill(self::driver(), 'Scratch Bill', 'active');
        self::waitForText('Parking');
        self::waitForText('Scratch Bill');
    }

    #[Depends('testOtherAsksForAName')]
    public function testTheBucketPickerFollowsTheTypeAndFuelIsLocked(): void
    {
        self::clickLink('New bill');
        $heads = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('.group-title')].map((node) => node.textContent.trim());
        JS);
        self::assertContains('Needs', $heads);
        self::assertContains('Wants', $heads);
        self::assertContains('Savings', $heads);
        self::click('rent');
        $options = self::wait()->until(function (): array|false {
            $names = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector('select[name="categoryId"]');
                if (!select) return [];
                return [...select.options].map((option) => option.textContent.trim());
            JS);

            return is_array($names) && $names !== [] ? $names : false;
        });
        self::assertContains('Rent', $options);
        self::assertContains('Road cushion', $options);
        self::assertNotContains('Restaurants', $options);
        self::click('fuel');
        self::waitForText('Fuel');
        $locked = self::driver()->executeScript(
            'return document.querySelector(\'select[name="categoryId"]\') === null;',
        );
        self::assertTrue($locked);
        self::click('mortgage');
        $mark = self::driver()->executeScript(<<<'JS'
            const button = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'mortgage');
            return Boolean(button && button.querySelector('svg'));
        JS);
        self::assertTrue($mark);
        self::click('misc');
        self::waitForText('Misc name');
    }

    #[Depends('testTheBucketPickerFollowsTheTypeAndFuelIsLocked')]
    public function testTheAssetFavoriteIsTheDrawFromForANewBillAndALockedBill(): void
    {
        self::clickLink('Accounts');
        self::waitForText('Checking');
        $favorite = self::driver()->executeScript(<<<'JS'
            const button = document.querySelector('button[aria-label="Checking is the asset favorite"]');
            return Boolean(button && button.getAttribute('aria-pressed') === 'true');
        JS);
        self::assertTrue($favorite === true);
        self::clickLink('Bills');
        self::clickLink('New bill');
        self::assertSame('Checking', self::selectedAccount());
        self::clickLink('Bills');
        Reveal::bill(self::driver(), 'Travel card', 'active');
        self::openBill('Travel card');
        self::assertSame('Checking', self::selectedAccount());

        self::clickLink('Accounts');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('button[aria-label="Checking is the asset favorite"]'),
        ))->click();
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('button[aria-label="Make Center console the asset favorite"]'),
        ))->click();
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(
                'return document.querySelector(\'button[aria-label="Center console is the asset favorite"]\') !== null;',
            );

            return $ready === true;
        });
        self::clickLink('Bills');
        self::clickLink('New bill');
        self::assertSame('Center console', self::selectedAccount());
        self::clickLink('Bills');
        Reveal::bill(self::driver(), 'Travel card', 'active');
        self::openBill('Travel card');
        self::assertSame('Center console', self::selectedAccount());
    }

    private static function selectedAccount(): string
    {
        $value = self::wait()->until(function (): string|false {
            $text = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector('select[name="accountId"]');
                const option = select && select.selectedOptions[0];
                const label = option ? option.textContent.trim() : '';
                return label !== '' && label !== 'Choose an account' ? label : '';
            JS);

            return is_string($text) && $text !== '' ? $text : false;
        });
        self::assertIsString($value);

        return $value;
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

    private static function openBill(string $name): void
    {
        $quoted = json_encode($name, JSON_THROW_ON_ERROR);
        self::driver()->executeScript(<<<JS
            const name = {$quoted};
            const article = [...document.querySelectorAll('article')].find((node) => {
                const strong = node.querySelector('strong');
                return strong && strong.textContent.trim().startsWith(name);
            });
            const edit = article ? [...article.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Edit') : null;
            if (edit) edit.click();
        JS);
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
