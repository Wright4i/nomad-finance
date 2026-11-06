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

final class MotionSettingsTest extends TestCase
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

    public function testEffectsPlayWithoutAMotionSwitch(): void
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
        self::waitForText('Net worth');

        $figure = self::wait()->until(function (): array|false {
            $state = self::driver()->executeScript(<<<'JS'
                const figure = document.querySelector('.hero .figure');
                if (!figure) return null;
                const motion = Object.keys(document.documentElement.dataset).filter((key) => key.toLowerCase().startsWith('motion'));
                return {
                    text: figure.textContent.trim(),
                    children: figure.childElementCount,
                    animation: getComputedStyle(figure).animationName,
                    motion,
                };
            JS);
            if (!is_array($state) || !preg_match('/^-?\$[\d,]+\.\d{2}$/', (string) ($state['text'] ?? ''))) {
                return false;
            }

            return $state;
        });
        self::assertSame(0, $figure['children']);
        self::assertSame('none', $figure['animation']);
        self::assertSame([], $figure['motion']);

        self::clickLink('Envelopes');
        self::waitForText('Needs');
        $cascade = self::wait()->until(function (): array|false {
            $motion = self::driver()->executeScript(<<<'JS'
                const tile = document.querySelector('.bucket-tile');
                if (!tile) return null;
                const root = document.documentElement;
                return {
                    name: getComputedStyle(tile).animationName,
                    switched: root.dataset.motionStagger ?? '',
                };
            JS);
            if (!is_array($motion) || !str_contains((string) $motion['name'], 'tile-in')) {
                return false;
            }

            return $motion;
        });
        self::assertStringContainsString('tile-in', (string) $cascade['name']);
        self::assertSame('', $cascade['switched']);

        self::assertSame([], self::driver()->findElements(WebDriverBy::linkText('Settings')));
        $settings = self::driver()->executeScript(<<<'JS'
            const labels = ['Assigned-month celebration','Success and error notes','Bucket water level','Menu scrim','Dialogs','Net worth figure','Bucket tiles','Motion'];
            const body = document.body.innerText;
            return {
                present: labels.filter((label) => body.includes(label)),
                boxes: ['motionAssigned','motionNote','motionLevel','motionScrim','motionDialog','motionTicker','motionStagger']
                    .filter((name) => document.querySelector(`input[name="${name}"]`)),
                section: document.querySelector('fieldset.motion, .motion') !== null,
            };
        JS);
        self::assertIsArray($settings);
        self::assertSame([], $settings['present']);
        self::assertSame([], $settings['boxes']);
        self::assertFalse($settings['section']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        $open = WebDriverBy::cssSelector('button[aria-label="Open menu"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($open))->click();
        $scrim = self::wait()->until(function (): array|false {
            $state = self::driver()->executeScript(<<<'JS'
                const scrim = document.querySelector('.scrim');
                if (!scrim) return null;
                const style = getComputedStyle(scrim);
                if (style.display === 'none') return null;
                return {
                    property: style.transitionProperty,
                    duration: style.transitionDuration,
                };
            JS);
            if (!is_array($state) || !str_contains((string) $state['property'], 'opacity')) {
                return false;
            }

            return $state;
        });
        self::assertStringContainsString('opacity', (string) $scrim['property']);
        self::assertNotSame('0s', $scrim['duration']);
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
        self::assertNotFalse(stripos($body, $text));
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
