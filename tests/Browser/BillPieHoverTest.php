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
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BillPieHoverTest extends TestCase
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

    public function testHoveringALegendRowPullsItsSliceAndDimsTheOthers(): void
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
        self::waitForText('Still coming in');
        self::wait()->until(function (): bool {
            $count = self::driver()->executeScript(
                'return document.querySelectorAll("[data-section=\\"bills\\"] .bill-legend li").length',
            );

            return is_numeric($count) && (int) $count >= 2;
        });

        $types = self::driver()->executeScript(<<<'JS'
            const chart = document.querySelector('[data-section="bills"] .bill-chart');
            if (!chart) return [];
            return [...chart.querySelectorAll('.bill-legend li')].map((li) => li.getAttribute('data-type'));
        JS);
        self::assertIsArray($types);
        self::assertGreaterThanOrEqual(2, count($types));
        $type = (string) $types[0];
        $other = (string) $types[1];
        self::assertMatchesRegularExpression('/^[a-z]+$/', $type);
        self::assertMatchesRegularExpression('/^[a-z]+$/', $other);

        $rest = self::pair($type, $other);
        self::assertIsArray($rest);
        self::assertSame($type, $rest['sliceType']);
        self::assertSame($type, $rest['legendType']);
        self::assertStringContainsString('transform', (string) $rest['transition']);
        self::assertStringContainsString('opacity', (string) $rest['transition']);
        self::assertStringContainsString('0.23', (string) $rest['timing']);
        self::assertIsArray($rest['durations']);
        self::assertNotEmpty($rest['durations']);
        foreach ($rest['durations'] as $seconds) {
            self::assertGreaterThan(0, (float) $seconds);
            self::assertLessThanOrEqual(0.3, (float) $seconds);
        }

        if ($rest['fine'] !== true) {
            $css = self::styleText();
            self::assertStringContainsString('(hover: hover) and (pointer: fine)', $css);
            self::assertStringContainsString('translate(var(--pull-x), var(--pull-y))', $css);
            self::assertStringContainsString('prefers-reduced-motion', $css);
            self::assertStringContainsString('[data-pulled]', $css);

            return;
        }

        $legend = '[data-section="bills"] .bill-legend li[data-type="' . $type . '"]';
        $slice = '[data-section="bills"] .bill-pie [data-type="' . $other . '"]';
        self::driver()->executeScript(
            'document.querySelector("[data-section=\\"bills\\"]")?.scrollIntoView({block:"center"})',
        );
        $row = self::driver()->findElement(WebDriverBy::cssSelector($legend));
        self::driver()->action()->moveToElement($row)->perform();
        self::fire($legend, 'mouseenter');

        self::wait()->until(function () use ($type, $other): bool {
            $state = self::pair($type, $other);

            return is_array($state)
                && $state['pulled'] === 'true'
                && (float) $state['shift'] > 2
                && (float) $state['otherOpacity'] < 0.6
                && (float) $state['sliceOpacity'] > 0.9
                && $state['legendOn'] === 'true'
                && (float) $state['otherLegendOpacity'] < 0.6
                && $state['otherPulled'] === null;
        });
        $hot = self::pair($type, $other);
        self::assertIsArray($hot);
        self::assertSame('true', $hot['pulled']);
        self::assertGreaterThan(2, (float) $hot['shift']);
        self::assertLessThan(0.6, (float) $hot['otherOpacity']);
        self::assertSame($type, $hot['sliceType']);
        self::assertSame($type, $hot['legendType']);

        self::fire($legend, 'mouseleave');
        self::driver()->action()->moveToElement(
            self::driver()->findElement(WebDriverBy::cssSelector('.planner-net')),
        )->perform();
        self::wait()->until(function () use ($type, $other): bool {
            $state = self::pair($type, $other);

            return is_array($state)
                && $state['pulled'] === null
                && (float) $state['shift'] < 0.5
                && (float) $state['otherOpacity'] > 0.95
                && $state['sliceOn'] === null
                && $state['legendOn'] === null;
        });

        self::fire($slice, 'mouseenter');
        self::wait()->until(function () use ($type, $other): bool {
            $state = self::pair($other, $type);

            return is_array($state)
                && $state['pulled'] === null
                && (float) $state['shift'] < 0.5
                && $state['sliceOn'] === 'true'
                && $state['legendOn'] === 'true'
                && (float) $state['otherOpacity'] < 0.6
                && (float) $state['legendOpacity'] > 0.9
                && (float) $state['otherLegendOpacity'] < 0.6;
        });

        self::fire($slice, 'mouseleave');
        self::wait()->until(function () use ($type, $other): bool {
            $state = self::pair($other, $type);

            return is_array($state)
                && $state['sliceOn'] === null
                && $state['legendOn'] === null
                && (float) $state['otherOpacity'] > 0.95;
        });
    }

    /** @return array<string, mixed>|null */
    private static function pair(string $type, string $other): ?array
    {
        $value = self::driver()->executeScript(<<<'JS'
            const type = arguments[0];
            const other = arguments[1];
            const chart = document.querySelector('[data-section="bills"] .bill-chart');
            const pick = (name) => chart ? chart.querySelector(`[data-type="${CSS.escape(name)}"]`) : null;
            const slice = chart ? chart.querySelector(`.bill-pie [data-type="${CSS.escape(type)}"]`) : null;
            const otherSlice = chart ? chart.querySelector(`.bill-pie [data-type="${CSS.escape(other)}"]`) : null;
            const legend = chart ? chart.querySelector(`.bill-legend li[data-type="${CSS.escape(type)}"]`) : null;
            const otherLegend = chart ? chart.querySelector(`.bill-legend li[data-type="${CSS.escape(other)}"]`) : null;
            if (!slice || !otherSlice || !legend || !otherLegend || !pick(type)) return null;
            const transform = getComputedStyle(slice).transform;
            let shift = 0;
            const matrix = /matrix\(([^)]+)\)/.exec(transform);
            if (matrix) {
                const parts = matrix[1].split(',').map((part) => Number(part.trim()));
                shift = Math.hypot(parts[4] || 0, parts[5] || 0);
            }
            const durations = getComputedStyle(slice).transitionDuration.split(',').map((part) => {
                const token = part.trim();
                const amount = Number.parseFloat(token);
                return token.endsWith('ms') ? amount / 1000 : amount;
            });
            return {
                pulled: slice.getAttribute('data-pulled'),
                legendOn: legend.getAttribute('data-on'),
                sliceOn: slice.getAttribute('data-on'),
                sliceType: slice.getAttribute('data-type'),
                legendType: legend.getAttribute('data-type'),
                otherPulled: otherSlice.getAttribute('data-pulled'),
                shift,
                transition: getComputedStyle(slice).transitionProperty,
                durations,
                timing: getComputedStyle(slice).transitionTimingFunction,
                sliceOpacity: Number(getComputedStyle(slice).opacity),
                otherOpacity: Number(getComputedStyle(otherSlice).opacity),
                legendOpacity: Number(getComputedStyle(legend).opacity),
                otherLegendOpacity: Number(getComputedStyle(otherLegend).opacity),
                fine: window.matchMedia('(hover: hover) and (pointer: fine)').matches,
            };
        JS, [$type, $other]);

        return is_array($value) ? $value : null;
    }

    private static function fire(string $selector, string $event): void
    {
        $ok = self::driver()->executeScript(<<<'JS'
            const node = document.querySelector(arguments[0]);
            if (!node) return false;
            node.dispatchEvent(new MouseEvent(arguments[1], { bubbles: false }));
            return true;
        JS, [$selector, $event]);
        self::assertTrue($ok === true);
    }

    private static function styleText(): string
    {
        $text = self::driver()->executeScript(<<<'JS'
            let text = '';
            for (const sheet of document.styleSheets) {
                let rules;
                try { rules = sheet.cssRules; } catch (err) { continue; }
                for (const rule of rules) text += rule.cssText + '\n';
            }
            return text;
        JS);

        return is_string($text) ? $text : '';
    }

    private static function click(string $label): void
    {
        $by = WebDriverBy::xpath('//button[normalize-space()=' . self::quote($label) . ']');
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
