<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvelopesTest extends TestCase
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

    public function testDemoAssignsInTheOpenPeriodAndKeepsTheClosedOne(): void
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
        self::waitForText('$13,295.00');
        self::waitForText('Needs');
        self::waitForText('50%');
        self::waitForText('$500.00 · 100%');
        self::waitForText('Wants');
        self::waitForText('30%');
        self::waitForText('Savings');
        self::waitForText('20%');
        $gauges = self::driver()->executeScript(<<<'JS'
            const read = (name) => {
                const meter = document.querySelector(`[aria-label^="${name} is"]`);
                const gauge = meter ? meter.closest('.gauge') : null;
                const over = meter ? meter.querySelector('.gauge-over') : null;
                return {
                    text: gauge ? gauge.innerText : '',
                    over: over ? over.style.transform : '',
                };
            };
            return { needs: read('Needs'), wants: read('Wants'), savings: read('Savings') };
        JS);
        self::assertIsArray($gauges);
        self::assertStringContainsString('Over', $gauges['needs']['text']);
        self::assertSame('scaleX(1)', $gauges['needs']['over']);
        self::assertStringNotContainsString('Over', $gauges['wants']['text']);
        self::assertSame('scaleX(0)', $gauges['wants']['over']);
        self::assertSame('scaleX(0)', $gauges['savings']['over']);
        $planner = self::driver()->executeScript(<<<'JS'
            const envelopes = document.querySelector('[data-section="envelopes"]');
            const bills = document.querySelector('[data-section="bills"]');
            if (!envelopes || !bills) return null;
            const envelopeText = envelopes.innerText;
            const folded = envelopeText.toLowerCase();
            const rent = bills.querySelector('.bill-legend [data-type="rent"]');
            const slice = bills.querySelector('svg.bill-pie [data-type="rent"]');
            const swatch = bills.querySelector('.bill-legend [data-type="rent"] .bill-swatch');
            const teal = document.querySelector('.teal');
            const monthButtons = [...bills.querySelectorAll('.month-square')].map((el) => {
                const box = el.getBoundingClientRect();
                return { text: el.textContent.trim(), width: box.width, height: box.height, top: box.top, left: box.left };
            });
            const pieBox = bills.querySelector('svg.bill-pie')?.getBoundingClientRect() ?? null;
            const stackBox = bills.querySelector('.month-squares')?.getBoundingClientRect() ?? null;
            return {
                envelopeText,
                needsBeforeAvailable: folded.indexOf('needs') >= 0 && folded.indexOf('available to assign') > folded.indexOf('needs'),
                cards: envelopes.querySelectorAll('.card').length,
                rentText: rent ? rent.innerText : '',
                billText: bills.innerText,
                pies: bills.querySelectorAll('svg.bill-pie').length,
                rentFill: slice ? getComputedStyle(slice).fill : '',
                teal: teal ? getComputedStyle(teal).color : '',
                swatch: swatch ? getComputedStyle(swatch).backgroundColor : '',
                assignInCard: Boolean(envelopes.querySelector('article.card a[href="/envelopes"]')),
                squares: monthButtons.length,
                monthButtons,
                pieBox: pieBox ? { top: pieBox.top, bottom: pieBox.bottom } : null,
                stackBox: stackBox ? { top: stackBox.top, bottom: stackBox.bottom } : null,
            };
        JS);
        self::assertIsArray($planner);
        self::assertTrue($planner['needsBeforeAvailable']);
        self::assertGreaterThanOrEqual(4, $planner['cards']);
        self::assertStringNotContainsString('Previous period', $planner['envelopeText']);
        self::assertStringNotContainsString('Next period', $planner['envelopeText']);
        self::assertStringNotContainsString('Open period', $planner['envelopeText']);
        self::assertStringNotContainsString('Cash 3', $planner['envelopeText']);
        self::assertStringContainsString('$13,295.00', $planner['envelopeText']);
        self::assertStringContainsString('assigned of', $planner['envelopeText']);
        self::assertSame(1, $planner['pies']);
        self::assertStringContainsString('rent', $planner['rentText']);
        self::assertStringContainsString('$380.00', $planner['rentText']);
        self::assertStringContainsString('$174.04', $planner['billText']);
        self::assertStringNotContainsString('$65.00', $planner['billText']);
        self::assertStringContainsString('$1,275.00', $planner['billText']);
        self::assertStringContainsString('debt', $planner['billText']);
        self::assertStringNotContainsString('entertainment', $planner['billText']);
        self::assertStringNotContainsString('One-Time Bill', $planner['billText']);
        self::assertStringNotContainsString('Paid', $planner['billText']);
        self::assertStringNotContainsString('No bills due this month.', $planner['billText']);
        self::assertStringNotContainsString('No bills this month.', $planner['billText']);
        self::assertSame($planner['teal'], $planner['rentFill']);
        self::assertSame($planner['teal'], $planner['swatch']);
        self::assertTrue($planner['assignInCard']);
        self::assertSame(2, $planner['squares']);
        self::assertSame('Current Month', $planner['monthButtons'][0]['text']);
        self::assertSame('Next Month', $planner['monthButtons'][1]['text']);
        self::assertEqualsWithDelta($planner['monthButtons'][0]['left'], $planner['monthButtons'][1]['left'], 1);
        self::assertGreaterThan(
            $planner['monthButtons'][0]['top'] + $planner['monthButtons'][0]['height'],
            $planner['monthButtons'][1]['top'],
        );
        foreach ($planner['monthButtons'] as $button) {
            self::assertGreaterThan(120, $button['width']);
            self::assertEqualsWithDelta($button['width'] / 2, $button['height'], 1);
        }
        self::assertEqualsWithDelta(
            ($planner['pieBox']['top'] + $planner['pieBox']['bottom']) / 2,
            ($planner['stackBox']['top'] + $planner['stackBox']['bottom']) / 2,
            2,
        );
        self::click('Next Month');
        self::wait()->until(function (): bool {
            $legend = self::driver()->executeScript(<<<'JS'
                const list = document.querySelector('[data-section="bills"] .bill-legend');
                return list ? list.innerText : '';
            JS);

            return is_string($legend)
                && str_contains($legend, 'debt')
                && str_contains($legend, 'entertainment')
                && str_contains($legend, '$500.00')
                && str_contains($legend, 'rent');
        });
        self::click('Current Month');
        self::waitForText('$380.00');
        self::clickLink('Envelopes');
        self::waitForText('Available to assign');
        self::waitForText('$13,295.00');
        self::waitForText('Needs 1');
        self::waitForText('$500.00');
        $bars = self::driver()->executeScript(<<<'JS'
            const tile = (name) => [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes(name));
            const read = (name) => {
                const row = tile(name);
                return {
                    found: Boolean(row),
                    input: row ? row.querySelector('input') !== null : false,
                    water: row ? row.style.getPropertyValue('--level') : '',
                };
            };
            return { needs: read('Needs 1'), wants: read('Wants 1'), rent: read('Rent') };
        JS);
        self::assertIsArray($bars);
        self::assertTrue($bars['needs']['found']);
        self::assertTrue($bars['needs']['input']);
        self::assertTrue($bars['wants']['found']);
        self::assertTrue($bars['rent']['found']);
        self::assertFalse($bars['rent']['input']);
        self::waitForText('Nov 2026');
        self::click('Next month');
        self::waitForText('Dec 2026');
        self::waitForText('when this month opens');
        self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
            const input = tile.querySelector('input');
            input.value = '700.00';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        $started = microtime(true);
        self::wait()->until(function () use ($started): bool {
            if (microtime(true) - $started < 1.3) {
                return false;
            }
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
                const input = tile ? tile.querySelector('input') : null;
                return input ? input.value : '';
            JS);

            return $value === '700.00';
        });
        self::click('Previous month');
        self::waitForText('Nov 2026');
        self::waitForText('$500.00');
        self::click('Previous month');
        self::waitForText('Carry');
        self::waitForText('A closed month can be read.');
        $closed = self::driver()->executeScript(<<<'JS'
            const value = (name) => {
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes(name));
                const input = tile ? tile.querySelector('input') : null;
                return input ? input.value : '';
            };
            return { needs: value('Needs 1'), wants: value('Wants 1') };
        JS);
        self::assertSame('200.00', $closed['needs']);
        self::assertSame('-150.00', $closed['wants']);
        $disabled = self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
            const input = tile ? tile.querySelector('input') : null;
            return input ? input.disabled : false;
        JS);
        self::assertTrue($disabled);
        self::click('Next month');
        self::waitForText('$500.00');
        self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
            const input = tile.querySelector('input');
            input.value = '600.00';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::waitForText('$600.00');
        self::waitForText('$13,195.00');
        self::clickLink('Planner');
        self::clickLink('Bills');
        self::clickLinkContaining('One-Time Bill');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const select = document.querySelector('select[name="categoryId"]');
                const label = select && select.selectedOptions[0] ? select.selectedOptions[0].textContent.trim() : '';
                return name && name.value === 'One-Time Bill' && label === 'Needs 1';
            JS);

            return $ready === true;
        });
    }

    private static function choose(string $name, string $label): void
    {
        self::wait()->until(function () use ($name, $label): bool {
            $ready = self::driver()->executeScript(
                'const select = document.querySelector(arguments[0]); if (!select) return false; return [...select.options].some((option) => option.textContent.trim() === arguments[1]);',
                ['select[name="' . $name . '"]', $label],
            );

            return $ready === true;
        });
        $element = self::driver()->findElement(WebDriverBy::name($name));
        (new WebDriverSelect($element))->selectByVisibleText($label);
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
