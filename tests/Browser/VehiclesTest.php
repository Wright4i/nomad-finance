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

final class VehiclesTest extends TestCase
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

    public function testAVehicleFormHasNoColorAndStillSaves(): void
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
        self::clickLink('Vehicles');
        self::waitForText('Pickup');
        self::waitForText('Side-by-side');
        self::waitForText('Quad');
        self::waitForText('Golf cart');
        self::waitForText('Dirt bike');
        self::waitForText('utv');
        self::waitForText('atv');
        self::waitForText('10,500 mi');
        self::waitForText('8.0 mpg');
        $kindLabels = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('.vehicle-columns article .muted')].map((node) => node.textContent.trim());
        JS);
        self::assertIsArray($kindLabels);
        self::assertContains('rv', $kindLabels);
        self::assertContains('utv', $kindLabels);
        self::assertContains('atv', $kindLabels);
        self::assertContains('golf cart', $kindLabels);
        self::assertContains('dirt bike', $kindLabels);
        $vehicle = self::wait()->until(function (): array|false {
            $measured = self::driver()->executeScript(<<<'JS'
                const columns = document.querySelector('.vehicle-columns');
                const card = columns ? columns.querySelector('article') : null;
                const odometer = card ? card.querySelector('[data-chip="odometer"]') : null;
                const mpg = card ? card.querySelector('[data-chip="mpg"]') : null;
                if (!columns || !card || !odometer || !mpg) return null;
                const columnBox = columns.getBoundingClientRect();
                const cardBox = card.getBoundingClientRect();
                const inside = (node) => {
                    const box = node.getBoundingClientRect();
                    return box.width > 0 && box.height > 0
                        && box.left >= cardBox.left - 1 && box.right <= cardBox.right + 1
                        && box.top >= cardBox.top - 1 && box.bottom <= cardBox.bottom + 1;
                };
                return {
                    columns: columnBox.width,
                    card: cardBox.width,
                    odometer: odometer.textContent.trim(),
                    mpg: mpg.textContent.trim(),
                    inside: inside(odometer) && inside(mpg),
                };
            JS);

            return is_array($measured) ? $measured : false;
        });
        self::assertSame('10,500 mi', $vehicle['odometer']);
        self::assertSame('8.0 mpg', $vehicle['mpg']);
        self::assertTrue($vehicle['inside']);
        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        try {
            $phone = self::wait()->until(function (): array|false {
                $measured = self::driver()->executeScript(<<<'JS'
                    const columns = document.querySelector('.vehicle-columns');
                    const card = columns ? columns.querySelector('article') : null;
                    const odometer = card ? card.querySelector('[data-chip="odometer"]') : null;
                    const mpg = card ? card.querySelector('[data-chip="mpg"]') : null;
                    if (!columns || !card || !odometer || !mpg) return null;
                    const columnBox = columns.getBoundingClientRect();
                    const cardBox = card.getBoundingClientRect();
                    const chips = [odometer, mpg].map((node) => node.getBoundingClientRect());
                    return {
                        columns: columnBox.width,
                        card: cardBox.width,
                        cardLeft: cardBox.left,
                        cardRight: cardBox.right,
                        viewport: window.innerWidth,
                        overflow: document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
                        inside: chips.every((box) => box.left >= cardBox.left - 1 && box.right <= cardBox.right + 1 && box.top >= cardBox.top - 1 && box.bottom <= cardBox.bottom + 1),
                        count: columns.querySelectorAll('article').length,
                    };
                JS);

                return is_array($measured) && $measured['card'] > 0 ? $measured : false;
            });
            self::assertSame(5, $phone['count']);
            self::assertTrue($phone['inside']);
            self::assertTrue($phone['overflow']);
            self::assertGreaterThanOrEqual(-1, $phone['cardLeft']);
            self::assertLessThanOrEqual($phone['viewport'] + 1, $phone['cardRight']);
            self::assertEqualsWithDelta($phone['columns'], $phone['card'], 2);
        } finally {
            self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        }
        self::clickLink('Bills');
        Reveal::bill(self::driver(), 'Site week', 'active');
        self::waitForText('Site week');
        $bills = self::wait()->until(function (): array|false {
            $measured = self::driver()->executeScript(<<<'JS'
                const columns = document.querySelector('.bill-columns');
                const card = columns ? columns.querySelector('.card') : null;
                if (!columns || !card) return null;
                return {
                    columns: columns.getBoundingClientRect().width,
                    card: card.getBoundingClientRect().width,
                };
            JS);

            return is_array($measured) && $measured['columns'] > 0 ? $measured : false;
        });
        self::clickLink('Vehicles');
        self::waitForText('10,500 mi');
        self::assertEqualsWithDelta($bills['columns'], $vehicle['columns'], 4);
        self::assertGreaterThan(200, $vehicle['card']);
        self::assertLessThanOrEqual($vehicle['columns'] + 1, $vehicle['card']);
        self::clickLinkContaining('Pickup');
        self::waitForText('Minimum mpg');
        self::assertFalse(self::colorFieldPresent());

        self::clickLink('Back');
        self::waitForText('New vehicle');
        self::clickLink('New vehicle');
        self::waitForText('Kind');
        self::waitForText('utv');
        self::waitForText('atv');
        self::waitForText('golf cart');
        self::waitForText('dirt bike');
        self::assertFalse(self::colorFieldPresent());
        self::type('name', 'Vehicle 2');
        self::click('utv');
        self::assertSame('true', self::driver()->findElement(WebDriverBy::xpath('//button[normalize-space()="utv"]'))->getAttribute('aria-pressed'));
        self::click('atv');
        self::click('golf cart');
        self::click('dirt bike');
        self::click('truck');
        self::type('currentOdometer', '0');
        self::click('Save vehicle');
        self::waitForText('Fuel');
        self::assertFalse(self::colorFieldPresent());
        $saved = self::wait()->until(function (): string|false {
            $value = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="name"]');
                return input ? input.value : '';
            JS);

            return $value === 'Vehicle 2' ? $value : false;
        });
        self::assertSame('Vehicle 2', $saved);
        self::clickLink('Back');
        self::waitForText('Vehicle 2');
        self::clickLinkContaining('Pickup');
        self::waitForText('Stretch mpg');
        $dates = self::wait()->until(function (): array|false {
            $dates = self::driver()->executeScript(<<<'JS'
                return [...document.querySelectorAll('table tbody tr td:first-child')].map((cell) => cell.textContent.trim());
            JS);
            if (!is_array($dates) || ($dates[0] ?? '') !== 'Nov 18, 2026') {
                return false;
            }

            return $dates;
        });
        self::assertSame([
            'Nov 18, 2026',
            'Nov 8, 2026',
            'Nov 1, 2026',
            'Oct 1, 2026',
            'Oct 1, 2026',
            'Sep 15, 2026',
        ], $dates);

        $labels = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('nav[aria-label="Primary"] a')].map((node) => node.textContent.trim());
        JS);
        self::assertIsArray($labels);
        self::assertNotContains('Settings', $labels);
        self::assertContains('Vehicles', $labels);
        self::waitForText('Fallback price per gallon');
        self::assertSame('$', self::driver()->findElement(WebDriverBy::cssSelector('.money-input .money-mark'))->getText());
        self::assertSame('4.00', (string) self::driver()->findElement(WebDriverBy::name('fuelPrice'))->getAttribute('value'));
        self::type('fuelPrice', '3.50');
        self::click('Save vehicle');
        self::waitForText('Saved.');
        self::assertSame('3.50', (string) self::driver()->findElement(WebDriverBy::name('fuelPrice'))->getAttribute('value'));

        self::assertThemePill(false);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('.theme-pill button[aria-label="Day"]'),
        ))->click();
        self::wait()->until(function (): bool {
            $light = self::driver()->executeScript('return document.documentElement.classList.contains("light")');

            return $light === true;
        });
        self::assertThemePill(true);
        self::driver()->navigate()->refresh();
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('.theme-pill button[aria-label="Night"]'),
        ));
        self::assertTrue(self::driver()->executeScript('return document.documentElement.classList.contains("light")') === true);
        self::assertThemePill(true);
        self::driver()->findElement(WebDriverBy::cssSelector('.theme-pill button[aria-label="Night"]'))->click();
        self::wait()->until(function (): bool {
            $light = self::driver()->executeScript('return document.documentElement.classList.contains("light")');

            return $light === false;
        });
        self::assertThemePill(false);
    }

    #[Depends('testAVehicleFormHasNoColorAndStillSaves')]
    public function testAnArchivedVehicleCanBeDeleted(): void
    {
        self::driver()->get(self::$base . '/vehicles');
        self::waitForText('Pickup');
        self::waitForTextGone('Old truck');
        self::click('Show archived');
        self::waitForText('Old truck');
        self::waitForText('archived');
        self::click('Hide archived');
        self::waitForTextGone('Old truck');
        self::clickLink('New vehicle');
        self::type('name', 'Gone rig');
        self::type('currentOdometer', '0');
        self::click('Save vehicle');
        self::waitForText('Fuel');
        self::click('Archive');
        self::waitForText('Archive this vehicle?');
        self::closeOpenDialog();
        self::waitForTextGone('Archive this vehicle?');
        self::click('Archive');
        self::click('Archive vehicle');
        self::waitForText('New vehicle');
        self::waitForTextGone('Gone rig');
        self::click('Show archived');
        self::waitForText('Gone rig');
        self::waitForText('Old truck');
        self::driver()->executeScript(<<<'JS'
            const card = [...document.querySelectorAll('article')].find((node) => node.innerText.includes('Gone rig'));
            const button = card ? [...card.querySelectorAll('button')].find((node) => node.innerText.trim() === 'Delete') : null;
            button?.click();
        JS);
        self::waitForText('Delete this vehicle?');
        self::closeOpenDialog();
        self::waitForTextGone('Delete this vehicle?');
        self::waitForText('Gone rig');
        self::clickLinkContaining('Gone rig');
        self::waitForText('Unarchive');
        self::click('Delete');
        self::waitForText('Delete this vehicle?');
        self::closeOpenDialog();
        self::waitForTextGone('Delete this vehicle?');
        self::click('Delete');
        self::click('Delete vehicle');
        self::waitForText('Pickup');
        self::waitForTextGone('Gone rig');
        self::click('Show archived');
        self::waitForText('Old truck');
        self::waitForTextGone('Gone rig');
        self::click('Hide archived');
    }

    private static function closeOpenDialog(): void
    {
        self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog[open]');
            const cancel = dialog ? [...dialog.querySelectorAll('button')].find((node) => node.innerText.trim() === 'Cancel') : null;
            cancel?.click();
        JS);
    }

    private static function assertThemePill(bool $day): void
    {
        $label = $day ? 'Day' : 'Night';
        self::wait()->until(function () use ($label): bool {
            $opacity = self::driver()->executeScript(
                'const icon = document.querySelector(arguments[0]); return icon ? getComputedStyle(icon).opacity : "";',
                ['.theme-pill button[aria-label="' . $label . '"] .glyph'],
            );

            return $opacity === '1';
        });
        $paint = self::driver()->executeScript(<<<'JS'
            const pill = document.querySelector('.theme-pill');
            const buttons = pill ? [...pill.querySelectorAll('button')] : [];
            const moon = pill?.querySelector('button[aria-label="Night"]') ?? null;
            const sun = pill?.querySelector('button[aria-label="Day"]') ?? null;
            const night = pill?.querySelector('.theme-slant.night') ?? null;
            const dayFill = pill?.querySelector('.theme-slant.light') ?? null;
            const logout = [...document.querySelectorAll('.nav-foot button')].find((node) => node.textContent.trim() === 'Log out');
            const probe = document.createElement('div');
            probe.style.backgroundColor = 'var(--teal)';
            document.body.appendChild(probe);
            const teal = getComputedStyle(probe).backgroundColor;
            probe.remove();
            const slant = (node) => {
                if (!node) return 0;
                const nums = [...getComputedStyle(node).clipPath.matchAll(/(-?[\d.]+)(?:px|%)/g)].map((match) => Number(match[1]));
                const points = [];
                for (let i = 0; i + 1 < nums.length; i += 2) points.push([nums[i], nums[i + 1]]);
                let best = 0;
                for (let i = 0; i < points.length; i += 1) {
                    const next = points[(i + 1) % points.length];
                    if (points[i][1] !== next[1]) best = Math.max(best, Math.abs(points[i][0] - next[0]));
                }
                return best;
            };
            const centered = (button) => {
                if (!button) return 99;
                const icon = button.querySelector('.glyph');
                if (!icon) return 99;
                const half = button.getBoundingClientRect();
                const mark = icon.getBoundingClientRect();
                return Math.abs((mark.left + mark.width / 2) - (half.left + half.width / 2));
            };
            const pillBox = pill ? pill.getBoundingClientRect() : null;
            const outBox = logout ? logout.getBoundingClientRect() : null;
            const moonBox = moon ? moon.getBoundingClientRect() : null;
            const sunBox = sun ? sun.getBoundingClientRect() : null;
            const edge = pill ? getComputedStyle(pill, '::before') : null;
            const drawn = (style) => style && style.content !== 'none' && style.content !== 'normal';
            const nightStyle = night ? getComputedStyle(night) : null;
            const read = (button) => {
                if (!button) return null;
                const icon = button.querySelector('.glyph');
                return {
                    pressed: button.getAttribute('aria-pressed') === 'true',
                    glyph: button.querySelector('svg') !== null,
                    color: icon ? getComputedStyle(icon).color : '',
                    opacity: icon ? getComputedStyle(icon).opacity : '',
                    center: centered(button),
                };
            };
            return {
                teal,
                aligned: pillBox && outBox
                    ? Math.abs(pillBox.width - outBox.width) < 1.5 && Math.abs(pillBox.left - outBox.left) < 1.5
                    : false,
                sideBySide: moonBox && sunBox
                    ? Math.abs(moonBox.top - sunBox.top) < 2 && sunBox.left >= moonBox.right - 2
                    : false,
                nightSlant: slant(night),
                daySlant: slant(dayFill),
                nightBg: night ? getComputedStyle(night).backgroundColor : '',
                dayBg: dayFill ? getComputedStyle(dayFill).backgroundColor : '',
                edgeBg: drawn(edge) ? edge.backgroundColor : 'none',
                nightOutline: nightStyle ? nightStyle.outlineStyle : '',
                nightShadow: nightStyle ? nightStyle.boxShadow : '',
                nightBorder: nightStyle ? nightStyle.borderTopWidth : '',
                nightClip: nightStyle ? nightStyle.clipPath : '',
                order: buttons[0] === moon && buttons[1] === sun,
                moon: read(moon),
                sun: read(sun),
            };
        JS);
        self::assertIsArray($paint);
        self::assertTrue($paint['aligned']);
        self::assertTrue($paint['sideBySide']);
        self::assertGreaterThan(8, $paint['nightSlant']);
        self::assertGreaterThan(8, $paint['daySlant']);
        self::assertTrue($paint['order']);
        self::assertIsArray($paint['moon']);
        self::assertIsArray($paint['sun']);
        self::assertTrue($paint['moon']['glyph']);
        self::assertTrue($paint['sun']['glyph']);
        self::assertLessThan(2, $paint['moon']['center']);
        self::assertLessThan(2, $paint['sun']['center']);
        $active = $day ? $paint['sun'] : $paint['moon'];
        $idle = $day ? $paint['moon'] : $paint['sun'];
        self::assertTrue($active['pressed']);
        self::assertFalse($idle['pressed']);
        if ($day) {
            self::assertSame($paint['teal'], $paint['dayBg']);
            self::assertSame('rgb(255, 255, 255)', $paint['sun']['color']);
            self::assertSame('1', $paint['sun']['opacity']);
            self::assertSame('rgb(0, 0, 0)', $paint['moon']['color']);
            self::assertSame('1', $paint['moon']['opacity']);
            self::assertSame('rgb(255, 255, 255)', $paint['nightBg']);
            self::assertSame('none', $paint['edgeBg']);
            self::assertSame('none', $paint['nightOutline']);
            self::assertSame('none', $paint['nightShadow']);
            self::assertSame('0px', $paint['nightBorder']);
            self::assertMatchesRegularExpression('/polygon\\(\\s*0px 0px,\\s*64%/', (string) $paint['nightClip']);
        } else {
            self::assertSame($paint['teal'], $paint['nightBg']);
            self::assertSame('rgb(0, 0, 0)', $paint['moon']['color']);
            self::assertSame('rgb(0, 0, 0)', $paint['dayBg']);
            self::assertSame('rgb(255, 255, 255)', $paint['sun']['color']);
            self::assertSame('none', $paint['edgeBg']);
            self::assertSame('none', $paint['nightOutline']);
            self::assertSame('none', $paint['nightShadow']);
            self::assertSame('0px', $paint['nightBorder']);
        }
    }

    private static function colorFieldPresent(): bool
    {
        $present = self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="color"], input[type="color"]');
            const label = [...document.querySelectorAll('label.field > span, .field > span')].some((node) => node.textContent.trim() === 'Color');
            return input !== null || label;
        JS);

        return $present === true;
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
