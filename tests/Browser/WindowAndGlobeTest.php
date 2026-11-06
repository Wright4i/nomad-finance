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

final class WindowAndGlobeTest extends TestCase
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

    public function testBillSectionsHaveScrollbarsAndGrowTowardLaterDates(): void
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
        self::clickLink('Bills');
        self::waitForText('October site');

        $bars = self::driver()->executeScript(<<<'JS'
            return ['active', 'paused', 'paid', 'archived'].map((key) => {
                const scroller = document.querySelector(`[data-section="${key}"] .bill-scroll`);
                const style = scroller ? getComputedStyle(scroller) : null;
                return {
                    key,
                    overflowY: style ? style.overflowY : '',
                    scrollbar: scroller ? scroller.offsetWidth - scroller.clientWidth : 0,
                    scrolls: scroller ? scroller.scrollHeight > scroller.clientHeight + 1 : false,
                };
            });
        JS);
        self::assertIsArray($bars);
        self::assertCount(4, $bars);
        foreach ($bars as $bar) {
            self::assertIsArray($bar);
            self::assertSame('scroll', $bar['overflowY']);
            self::assertGreaterThanOrEqual(0, $bar['scrollbar']);
        }

        $paidBefore = self::dates('paid');
        self::assertGreaterThan(1, count($paidBefore));
        self::assertSame($paidBefore, self::sorted($paidBefore));
        self::assertNotSame('2026-09-15', $paidBefore[0]);

        $activeBefore = self::dates('active');
        self::assertGreaterThan(1, count($activeBefore));
        $activeCount = count($activeBefore);
        $grown = self::wait()->until(function () use ($activeCount): array|false {
            $state = self::driver()->executeScript(<<<'JS'
                const scroller = document.querySelector('[data-section="active"] .bill-scroll');
                if (!scroller) return null;
                scroller.scrollTop = scroller.scrollHeight;
                scroller.dispatchEvent(new WheelEvent('wheel', { deltaY: 320, bubbles: true }));
                return [...document.querySelectorAll('[data-section="active"] .bill-day')].map((node) => node.dataset.date);
            JS);
            if (!is_array($state)) {
                return false;
            }
            $dates = array_values(array_filter($state, 'is_string'));
            if (count($dates) <= $activeCount) {
                return false;
            }

            return $dates;
        });
        self::assertIsArray($grown);
        self::assertSame($grown, self::sorted($grown));
        self::assertSame($activeBefore[0], $grown[0]);
        self::assertGreaterThan($activeBefore[array_key_last($activeBefore)], $grown[array_key_last($grown)]);

        $paidAfter = self::wait()->until(function () use ($paidBefore): array|false {
            $state = self::driver()->executeScript(<<<'JS'
                const scroller = document.querySelector('[data-section="paid"] .bill-scroll');
                if (!scroller) return null;
                scroller.scrollTop = scroller.scrollHeight;
                scroller.dispatchEvent(new WheelEvent('wheel', { deltaY: 320, bubbles: true }));
                return [...document.querySelectorAll('[data-section="paid"] .bill-day')].map((node) => node.dataset.date);
            JS);
            if (!is_array($state)) {
                return false;
            }
            $dates = array_values(array_filter($state, 'is_string'));
            if (count($dates) <= count($paidBefore)) {
                return false;
            }

            return $dates;
        });
        self::assertSame($paidAfter, self::sorted($paidAfter));
        self::assertLessThan($paidBefore[0], $paidAfter[0]);
        self::assertSame($paidBefore[array_key_last($paidBefore)], $paidAfter[array_key_last($paidAfter)]);
    }

    #[Depends('testBillSectionsHaveScrollbarsAndGrowTowardLaterDates')]
    public function testTheGlobePastMonthsAndBasemapAndADashedFlight(): void
    {
        self::clickLink('Trips');
        self::waitForText('Usery Mountain');
        $books = self::moneyText();
        $choice = self::wait(40)->until(function (): array|false {
            $value = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector('select[name="pastMonths"]');
                const labels = [...document.querySelectorAll('.globe button.map-pin')].map((node) => node.getAttribute('aria-label') || '');
                if (!select || labels.length === 0) return null;
                return {
                    value: select.value,
                    options: [...select.options].map((option) => option.value),
                    labels,
                    basemap: document.querySelector('.globe')?.dataset.basemap || '',
                };
            JS);
            if (!is_array($value) || !is_array($value['labels']) || !is_array($value['options'])) {
                return false;
            }

            return $value;
        });
        self::assertSame('0', $choice['value']);
        self::assertSame(array_map('strval', range(0, 12)), $choice['options']);
        self::assertSame('positron', $choice['basemap']);
        self::assertFalse(self::labeled($choice['labels'], 'Devils Garden'));
        self::assertFalse(self::labeled($choice['labels'], 'Catalina'));
        self::assertTrue(self::labeled($choice['labels'], 'Lost Dutchman'));

        self::chooseMonths('1');
        self::waitForPin('Catalina', true);
        self::waitForPin('Devils Garden', false);

        self::chooseMonths('12');
        self::waitForPin('Devils Garden', true);
        self::assertSame($books, self::moneyText());

        self::chooseMonths('0');
        self::waitForPin('Devils Garden', false);

        self::theme('Day');
        self::wait()->until(function (): bool {
            $basemap = self::driver()->executeScript(
                'return document.querySelector(".globe")?.dataset.basemap || "";',
            );

            return $basemap === 'voyager';
        });
        self::theme('Night');
        self::wait()->until(function (): bool {
            $basemap = self::driver()->executeScript(
                'return document.querySelector(".globe")?.dataset.basemap || "";',
            );

            return $basemap === 'positron';
        });

        self::addStay('Open sea', '0', '-160', '02 / 01 / 2027', '02 / 02 / 2027');
        self::addStay('Far sea', '0', '-140', '02 / 02 / 2027', '02 / 03 / 2027');
        $flight = self::wait(40)->until(function (): array|false {
            $value = self::driver()->executeScript(<<<'JS'
                const globe = document.querySelector('.globe');
                const flight = document.querySelector('.globe .map-flight');
                const road = document.querySelector('.globe .map-leg');
                if (!globe || !flight || !road) return null;
                const style = getComputedStyle(flight);
                return {
                    label: flight.getAttribute('aria-label') || '',
                    line: flight.dataset.line || '',
                    text: flight.textContent || '',
                    color: style.color,
                    miles: Boolean(flight.querySelector('.map-leg')) || /\bmi\b/.test(flight.textContent || ''),
                    flights: globe.dataset.flights || '0',
                };
            JS);

            return is_array($value) ? $value : false;
        });
        self::assertSame('Flight', $flight['label']);
        self::assertSame('dashed', $flight['line']);
        self::assertSame('', trim((string) $flight['text']));
        self::assertFalse($flight['miles']);
        self::assertGreaterThan(0, (int) $flight['flights']);
        self::assertSame('rgb(15, 118, 110)', $flight['color']);
        self::assertSame($books, self::moneyText());
    }

    #[Depends('testBillSectionsHaveScrollbarsAndGrowTowardLaterDates')]
    public function testAStayDueShowsABillAndAnEditChangesThatBill(): void
    {
        self::driver()->get(self::$base . '/trips');
        self::waitForText('Add a stay');
        self::clickLink('Add a stay');
        self::waitForText('Save stay');
        self::type('placeName', 'Due camp');
        self::type('latitude', '33.5');
        self::type('longitude', '-114.2');
        self::replace('arriveOn', '03 / 01 / 2027');
        self::replace('departOn', '03 / 06 / 2027');
        self::replace('balanceDueOn', '10 / 16 / 2026');
        self::chooseAccount('Everyday checking');
        self::type('total', '80');
        self::type('amountDue', '40');
        self::click('Save stay');
        self::wait(40)->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const labels = [...document.querySelectorAll('.globe button.map-pin')].map((node) => node.getAttribute('aria-label') || '');
                return labels.some((label) => label.includes('Due camp'));
            JS);

            return $ready === true;
        });

        self::clickLink('Bills');
        Reveal::bill(self::driver(), 'Due camp', 'active');
        $first = self::billOnScreen('Due camp');
        self::assertSame('2026-10-16', $first['date']);
        self::assertStringContainsString('$40.00', $first['money']);
        self::assertSame(1, $first['count']);

        self::clickLink('Trips');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const pin = [...document.querySelectorAll('.globe button.map-pin')].find((node) => (node.getAttribute('aria-label') || '').includes('Due camp'));
                if (!pin) return false;
                pin.click();
                const card = document.querySelector('.stay-card');
                return Boolean(card && card.innerText.includes('Due camp'));
            JS);

            return $ready === true;
        });
        self::driver()->findElement(WebDriverBy::cssSelector('.stay-card button[aria-label="Edit stay"]'))->click();
        self::waitForText('Save stay');
        self::type('amountDue', '25');
        self::replace('balanceDueOn', '10 / 18 / 2026');
        self::click('Save stay');
        self::wait(40)->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                return [...document.querySelectorAll('.globe button.map-pin')].some((node) => (node.getAttribute('aria-label') || '').includes('Due camp'));
            JS);

            return $ready === true;
        });

        self::clickLink('Bills');
        Reveal::bill(self::driver(), 'Due camp', 'active');
        $edited = self::billOnScreen('Due camp');
        self::assertSame('2026-10-18', $edited['date']);
        self::assertStringContainsString('$25.00', $edited['money']);
        self::assertSame(1, $edited['count']);
    }

    /** @return array{date: string, money: string, count: int} */
    private static function billOnScreen(string $name): array
    {
        $value = self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const articles = [...document.querySelectorAll('[data-section="active"] article')].filter((node) => {
                const strong = node.querySelector('strong');
                return strong && strong.textContent.trim() === name;
            });
            const article = articles[0];
            const day = article ? article.closest('.bill-day') : null;
            const money = article ? article.querySelector('.money') : null;
            return {
                date: day ? day.dataset.date || '' : '',
                money: money ? money.textContent || '' : '',
                count: articles.length,
            };
        JS, [$name]);
        self::assertIsArray($value);

        return [
            'date' => (string) ($value['date'] ?? ''),
            'money' => (string) ($value['money'] ?? ''),
            'count' => (int) ($value['count'] ?? 0),
        ];
    }

    private static function chooseAccount(string $label): void
    {
        self::wait()->until(function () use ($label): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector('select[name="accountId"]');
                const option = select && [...select.options].find((item) => item.textContent.trim() === arguments[0]);
                if (!select || !option) return false;
                const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
                setter.call(select, option.value);
                select.dispatchEvent(new Event('change', { bubbles: true }));
                return select.value === option.value;
            JS, [$label]);

            return $ready === true;
        });
    }

    /** @param list<string> $labels */
    private static function labeled(array $labels, string $name): bool
    {
        foreach ($labels as $label) {
            if (str_contains($label, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function chooseMonths(string $months): void
    {
        self::driver()->executeScript(<<<'JS'
            const select = document.querySelector('select[name="pastMonths"]');
            const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
            setter.call(select, arguments[0]);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        JS, [$months]);
        self::wait()->until(function () use ($months): bool {
            $value = self::driver()->executeScript(
                'return document.querySelector(\'select[name="pastMonths"]\')?.value || "";',
            );

            return $value === $months;
        });
    }

    /** Lodging totals stay put when the globe filter or a dashed leg changes. */
    private static function moneyText(): string
    {
        $text = self::driver()->executeScript(<<<'JS'
            const lodging = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === 'Lodging');
            const section = lodging ? lodging.parentElement : null;
            return section ? section.innerText : '';
        JS);
        self::assertIsString($text);
        self::assertNotSame('', $text);

        return $text;
    }

    private static function theme(string $label): void
    {
        $by = WebDriverBy::cssSelector('.theme-pill button[aria-label="' . $label . '"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function waitForPin(string $name, bool $present): void
    {
        self::wait()->until(function () use ($name, $present): bool {
            $labels = self::driver()->executeScript(<<<'JS'
                return [...document.querySelectorAll('.globe button.map-pin')].map((node) => node.getAttribute('aria-label') || '');
            JS);
            if (!is_array($labels)) {
                return false;
            }
            $names = array_values(array_filter($labels, 'is_string'));

            return self::labeled($names, $name) === $present;
        });
    }

    private static function addStay(string $place, string $latitude, string $longitude, string $arrive, string $depart): void
    {
        self::clickLink('Add a stay');
        self::waitForText('Save stay');
        self::type('placeName', $place);
        self::type('latitude', $latitude);
        self::type('longitude', $longitude);
        self::replace('arriveOn', $arrive);
        self::replace('departOn', $depart);
        self::replace('balanceDueOn', $arrive);
        self::click('Save stay');
        self::wait()->until(function () use ($place): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const labels = [...document.querySelectorAll('.globe button.map-pin')].map((node) => node.getAttribute('aria-label') || '');
                return labels.some((label) => label.includes(arguments[0]));
            JS, [$place]);

            return $ready === true;
        });
    }

    /** @return list<string> */
    private static function dates(string $section): array
    {
        $dates = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll(`[data-section="${arguments[0]}"] .bill-day`)].map((node) => node.dataset.date || '');
        JS, [$section]);
        self::assertIsArray($dates);

        return array_values(array_filter($dates, 'is_string'));
    }

    /** @param list<string> $dates
     *  @return list<string>
     */
    private static function sorted(array $dates): array
    {
        $copy = $dates;
        sort($copy);

        return $copy;
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

    private static function replace(string $name, string $value): void
    {
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector(`input[name="${arguments[0]}"]`);
            const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
            setter.call(input, arguments[1]);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS, [$name, $value]);
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
