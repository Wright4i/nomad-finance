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
        self::clickLink('Budget');
        self::clickLink('Add bucket');
        self::clickLink('Cancel');
        self::waitForText('Road cushion');
        self::waitForText('Fun money');
        self::waitForText('Next rig');
        self::waitForText('Tires');
        self::waitForTextGone('Set aside $300.00 a month');
        self::waitForTextGone('Still $500.00 to hold');
        self::waitForTextGone('Wishlist');
        self::clickLinkContaining('Fun money');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const carry = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Carry');
                return name && name.value === 'Fun money' && !carry;
            JS);

            return $ready === true;
        });
        self::clickLink('Budget');
        self::clickLink('Add bucket');
        self::clickLink('Cancel');
        self::assertSame('/budget', (string) parse_url(self::driver()->getCurrentURL(), PHP_URL_PATH));
        self::waitForTextGone('Save bucket');
        self::waitForText('Road cushion');
        self::waitForTextGone('Old gym');
        $colors = self::driver()->executeScript(<<<'JS'
            const color = (label) => {
                const heading = document.querySelector(`h2[data-group="${label}"]`);
                return heading ? getComputedStyle(heading).color : '';
            };
            return [color('needs'), color('wants'), color('savings')];
        JS);
        self::assertIsArray($colors);
        self::assertCount(3, $colors);
        self::assertNotSame('', $colors[0]);
        self::assertNotSame($colors[0], $colors[1]);
        self::assertNotSame($colors[0], $colors[2]);
        self::assertNotSame($colors[1], $colors[2]);

        self::clickLinkContaining('Tires');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const dated = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Needed by date');
                const date = document.querySelector('input[name="targetDate"]');
                const wishlist = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Wishlist');
                return name && name.value === 'Tires'
                    && !dated
                    && !date
                    && !wishlist;
            JS);

            return $ready === true;
        });
        self::clickLink('Budget');
        self::clickLink('Add bucket');
        self::clickLink('Cancel');
        self::waitForText('Next rig');

        $button = self::driver()->findElement(WebDriverBy::linkText('Add bucket'));
        $resting = $button->getCSSValue('background-color');
        self::driver()->action()->moveToElement($button)->perform();
        $hovered = self::wait()->until(function () use ($button, $resting): string|false {
            $color = $button->getCSSValue('background-color');

            return $color !== $resting ? $color : false;
        });
        self::assertIsString($hovered);

        self::click('Show archived');
        self::waitForText('Old gym');
        self::click('Hide archived');
        self::waitForTextGone('Old gym');
    }

    #[Depends('testDemoListsOneCategoryInEachGroup')]
    public function testANewGoalHasNoFuelCheckboxAndADefaultOnlyEditsTheAmount(): void
    {
        self::clickLink('Add bucket');
        $fuel = self::driver()->executeScript(
            'return document.querySelector(\'input[name="fuel"]\') === null;',
        );
        self::assertTrue($fuel);
        self::clickLink('Cancel');
        self::clickLinkContaining('Rent');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const type = document.querySelector('select[name="billType"]');
                return location.pathname === '/bills'
                    && new URLSearchParams(location.search).get('type') === 'rent'
                    && type instanceof HTMLSelectElement
                    && type.value === 'rent';
            JS);

            return $ready === true;
        });
        self::clickLink('Budget');
        self::clickLink('Add bucket');
        self::clickLink('Cancel');
        self::assertSame('/budget', (string) parse_url(self::driver()->getCurrentURL(), PHP_URL_PATH));
        self::waitForTextGone('Save bucket');
    }

    #[Depends('testDemoListsOneCategoryInEachGroup')]
    public function testANewCategoryCanBeArchivedFromAModal(): void
    {
        self::clickLink('Add bucket');
        self::type('name', 'Trip 1');
        self::click('Wants');
        self::click('Save bucket');
        self::waitForText('Trip 1');
        $placed = self::driver()->executeScript(<<<'JS'
            const section = (label) => {
                const heading = document.querySelector(`h2[data-group="${label}"]`);
                return heading && heading.closest('section') ? heading.closest('section').innerText : '';
            };
            return section('wants').includes('Trip 1') && !section('needs').includes('Trip 1');
        JS);
        self::assertTrue($placed === true);

        self::clickLinkContaining('Trip 1');
        self::click('Archive');
        self::waitForText('Archive this bucket?');
        self::click('Cancel');
        self::waitForTextGone('Archive this bucket?');
        self::click('Archive');
        self::click('Archive bucket');
        self::waitForText('Road cushion');
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
        self::waitForText('Fun money');
        self::waitForText('Trip 1');
        $category = self::driver()->executeScript(<<<'JS'
            const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes('Trip 1'));
            return link ? !link.innerText.toLowerCase().includes('archived') : false;
        JS);
        self::assertTrue($category === true);

        self::clickLink('Accounts');
        self::click('Show archived');
        self::waitForText('Closed checking');
        $cards = self::driver()->executeScript(<<<'JS'
            const tiles = [...document.querySelectorAll('a.bucket-tile')].map((tile) => {
                const rect = tile.getBoundingClientRect();
                const style = getComputedStyle(tile);
                const kids = [...tile.children];
                const content = kids.reduce((sum, child) => sum + child.getBoundingClientRect().height, 0);
                const pad = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
                const border = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
                const gap = parseFloat(style.rowGap || style.gap || '0');
                const expected = content + pad + border + (kids.length > 1 ? gap * (kids.length - 1) : 0);
                const clipped = kids.some((child) => {
                    const box = child.getBoundingClientRect();
                    return box.top < rect.top - 1
                        || box.bottom > rect.bottom + 1
                        || child.scrollHeight > child.clientHeight + 2
                        || child.scrollWidth > child.clientWidth + 2;
                });
                return {
                    top: rect.top,
                    left: rect.left,
                    width: rect.width,
                    height: rect.height,
                    expected,
                    clipped,
                    aspect: style.aspectRatio,
                    name: tile.querySelector('strong')?.innerText || '',
                    money: tile.querySelector('.money')?.innerText || '',
                    background: style.backgroundImage,
                };
            });
            const rows = [];
            for (const tile of tiles) {
                const row = rows.find((item) => Math.abs(item.top - tile.top) < 2);
                if (row) row.tiles.push(tile);
                else rows.push({ top: tile.top, tiles: [tile] });
            }
            return {
                first: tiles.slice(0, 2),
                rows: rows.map((row) => {
                    const heights = row.tiles.map((tile) => tile.height);
                    return {
                        count: row.tiles.length,
                        spread: Math.max(...heights) - Math.min(...heights),
                        clipped: row.tiles.some((tile) => tile.clipped),
                        short: row.tiles.some((tile) => tile.height + 4 < tile.expected),
                    };
                }),
            };
        JS);
        self::assertIsArray($cards);
        $first = $cards['first'];
        self::assertIsArray($first);
        self::assertCount(2, $first);
        self::assertEqualsWithDelta($first[0]['top'], $first[1]['top'], 2);
        self::assertGreaterThan($first[0]['left'], $first[1]['left']);
        self::assertSame('auto', $first[0]['aspect']);
        self::assertGreaterThanOrEqual($first[0]['expected'] - 4, $first[0]['height']);
        self::assertNotSame('', $first[0]['name']);
        self::assertNotSame('', $first[0]['money']);
        self::assertIsString($first[0]['background']);
        self::assertStringContainsString('linear-gradient', $first[0]['background']);
        $matchedRow = false;
        self::assertIsArray($cards['rows']);
        foreach ($cards['rows'] as $row) {
            self::assertIsArray($row);
            self::assertFalse($row['clipped']);
            self::assertFalse($row['short']);
            if ($row['count'] >= 2) {
                $matchedRow = true;
                self::assertLessThan(2, $row['spread']);
            }
        }
        self::assertTrue($matchedRow);
        self::clickLinkContaining('Closed checking');
        self::click('Unarchive');
        self::waitForText('Everyday checking');
        self::waitForText('Closed checking');
        $accountColors = self::driver()->executeScript(<<<'JS'
            const linkFor = (name) => [...document.querySelectorAll('a.bucket-tile')].find((node) => node.textContent.includes(name));
            const color = (name) => {
                const link = linkFor(name);
                const mark = link ? link.querySelector('.glyph') : null;
                return mark ? getComputedStyle(mark).color : '';
            };
            const wash = (name) => {
                const link = linkFor(name);
                return link ? getComputedStyle(link).backgroundImage : '';
            };
            const money = (name) => {
                const link = linkFor(name);
                const amount = link ? link.querySelector('.money') : null;
                return amount ? getComputedStyle(amount).color : '';
            };
            return {
                glyphs: [color('Everyday checking'), color('Emergency fund'), color('Travel card'), color('Wallet'), color('Solar loan')],
                washes: [wash('Everyday checking'), wash('Emergency fund'), wash('Travel card'), wash('Wallet'), wash('Solar loan')],
                asset: money('Everyday checking'),
                card: money('Travel card'),
                loan: money('Solar loan'),
            };
        JS);
        self::assertIsArray($accountColors);
        self::assertCount(5, $accountColors['glyphs']);
        self::assertSame(5, count(array_unique($accountColors['glyphs'])));
        self::assertCount(5, $accountColors['washes']);
        self::assertSame(5, count(array_unique($accountColors['washes'])));
        foreach ($accountColors['washes'] as $wash) {
            self::assertIsString($wash);
            self::assertStringContainsString('linear-gradient', $wash);
        }
        self::assertNotSame($accountColors['asset'], $accountColors['card']);
        self::assertSame($accountColors['card'], $accountColors['loan']);
        $account = self::driver()->executeScript(<<<'JS'
            const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes('Closed checking'));
            return link ? !link.innerText.toLowerCase().includes('archived') : false;
        JS);
        self::assertTrue($account === true);
    }

    #[Depends('testUnarchivePutsTheNameBack')]
    public function testABlankNameStaysOnTheForm(): void
    {
        self::driver()->get(self::$base . '/buckets/new');
        self::click('Save bucket');
        self::waitForText('Use a name of 1 to 80 characters.');
    }

    #[Depends('testABlankNameStaysOnTheForm')]
    public function testUnpayRestoresAOneOffAndPauseRemains(): void
    {
        self::driver()->get(self::$base . '/bills');
        self::waitForText('Active');
        Reveal::bill(self::driver(), 'Urgent care', 'paid');
        self::waitForText('Urgent care');
        Reveal::bill(self::driver(), 'Estimated tax', 'active');
        self::waitForTextGone('Stop reminding');
        $billColors = self::driver()->executeScript(<<<'JS'
            const heading = (label) => {
                const node = document.querySelector(`[role="tab"][data-section="${label.toLowerCase()}"]`);
                return node ? getComputedStyle(node).color : '';
            };
            const mark = (name) => {
                const link = [...document.querySelectorAll('a')].find((node) => node.textContent.includes(name));
                const glyph = link ? link.querySelector('.glyph') : null;
                return glyph ? getComputedStyle(glyph).color : '';
            };
            return [heading('Active'), heading('Paused'), heading('Paid'), mark('Estimated tax'), mark('Streaming'), mark('Health share')];
        JS);
        self::assertIsArray($billColors);
        self::assertCount(6, $billColors);
        self::assertSame(6, count(array_unique($billColors)));
        Reveal::bill(self::driver(), 'Urgent care', 'paid');
        self::openBill('Urgent care');
        self::wait()->until(
            WebDriverExpectedCondition::elementToBeClickable(
                WebDriverBy::xpath('//article[contains(., "Urgent care")]//button[normalize-space()="Unpay"]'),
            ),
        )->click();
        $moved = self::wait()->until(function (): bool {
            $placed = self::driver()->executeScript(<<<'JS'
                const section = (label) => {
                    const node = document.querySelector(`section[data-section="${label.toLowerCase()}"]`);
                    return node ? node.textContent : '';
                };
                return section('Active').includes('Urgent care') && !section('Paid').includes('Urgent care');
            JS);

            return $placed === true;
        });
        self::assertTrue($moved);
        self::driver()->executeScript('document.querySelector(\'[role="tab"][data-section="active"]\')?.click()');
        self::openBill('Urgent care');
        self::wait()->until(
            WebDriverExpectedCondition::elementToBeClickable(
                WebDriverBy::xpath('//article[contains(., "Urgent care")]//button[normalize-space()="Pause"]'),
            ),
        )->click();
        $paused = self::wait()->until(function (): bool {
            $placed = self::driver()->executeScript(<<<'JS'
                const section = document.querySelector('section[data-section="paused"]');
                return section ? section.textContent.includes('Urgent care') : false;
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
        self::clickLink('Budget');
        self::clickLink('Add bucket');
        self::clickLink('Cancel');
        self::waitForText('Rent');
        self::waitForTextGone('Road cushion');
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

    private static function openBill(string $name): void
    {
        self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const article = [...document.querySelectorAll('article')].find((node) => node.textContent.includes(name));
            const opener = article ? article.querySelector('button.bill-open') : null;
            if (opener && opener.getAttribute('aria-expanded') !== 'true') opener.click();
        JS, [$name]);
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
