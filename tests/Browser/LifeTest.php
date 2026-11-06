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
use Facebook\WebDriver\WebDriverSelect;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LifeTest extends TestCase
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

    public function testAStayKeepsItsNoteAndTheNextPinIsTheOtherStay(): void
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
        self::clickLink('Trips');
        self::waitForText('Stay 1');
        self::assertStayRating();
        self::assertStayColors();
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const globe = document.querySelector('.globe');
                const canvas = globe && globe.querySelector('canvas');
                const failed = document.body.innerText.includes('The globe did not start');
                return Boolean(canvas) && !failed && globe.dataset.basemap === 'dark-matter';
            JS);

            return $ready === true;
        });
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const labels = [...document.querySelectorAll('.globe button.map-pin')].map((node) => node.getAttribute('aria-label') || '');
                const route = document.querySelector('.globe .map-leg');
                return Boolean(route)
                    && labels.some((label) => label.includes('Stay 1') && label.includes('Las Vegas, NV'))
                    && labels.some((label) => label.includes('Stay 2') && label.includes('Los Angeles, CA'));
            JS);

            return $ready === true;
        });
        self::assertStayCardAndList();
        $balanceColor = self::assertBalanceChipOnStayOne();
        self::click('Next');
        self::waitForText('Stay 2');
        self::waitForText('Dec 2026');
        self::assertSame('2026-12', self::tripMonth());
        self::click('Previous');
        self::waitForText('Stay 1');
        self::waitForText('Nov 2026');
        self::assertSame('2026-11', self::tripMonth());
        self::click('Next');
        self::waitForText('Stay 2');
        self::waitForText('Site 34');
        self::assertFuelChipOnStayTwo($balanceColor);
        self::clickLink('Add a stay');
        self::type('placeName', 'Park 1');
        self::type('note', 'SITE #34');
        self::type('latitude', '36.5');
        self::type('longitude', '-115.2');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const globe = document.querySelector('.globe');
                if (!globe || globe.dataset.basemap !== 'satellite') return false;
                const zoom = Number(globe.dataset.zoom);
                return zoom >= 14.5 && zoom < 15.5;
            JS);

            return $ready === true;
        });
        self::choose('accountId', 'Checking 1');
        self::type('total', '20');
        self::type('amountDue', '10');
        self::click('Save stay');
        self::waitForText('Park 1');
        self::waitForText('SITE #34');
        self::assertStringNotContainsString('Charge the deposit?', self::driver()->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('body'))->getText());
    }

    #[Depends('testAStayKeepsItsNoteAndTheNextPinIsTheOtherStay')]
    public function testLogShowsTheBalanceBeforeItMovesAndAFuelStretch(): void
    {
        self::clickLink('New log');
        self::waitForText('Fuel');
        self::assertSame([], self::driver()->findElements(WebDriverBy::xpath('//*[normalize-space()="Recent"]')));
        self::assertSame([], self::driver()->findElements(WebDriverBy::xpath('//button[normalize-space()="Out"]')));
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector('select[name="vehicleId"]');
                const label = select && select.selectedOptions[0] ? select.selectedOptions[0].textContent : '';
                return label.includes('Vehicle 1');
            JS);

            return $ready === true;
        });
        self::type('amount', '4');
        self::replace('odometer', '10400');
        self::click('Save log');
        self::waitForText('That reading is below the current odometer.');
        self::replace('odometer', '10600');
        self::waitForText('100 mi.');
        self::click('Save log');
        self::wait()->until(function (): bool {
            return str_contains(self::driver()->getCurrentURL(), '/calendar');
        });
        self::clickLink('Accounts');
        self::waitForText('$2,490.00');
        self::clickLink('New log');
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="date"]');
            input.value = '01 / 01 / 2099';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::type('amount', '1');
        self::replace('odometer', '10600');
        self::click('Save log');
        self::waitForText('The date cannot be after today.');
    }

    #[Depends('testLogShowsTheBalanceBeforeItMovesAndAFuelStretch')]
    public function testTheNavHasFiveHubsAndTheHorizonSaves(): void
    {
        $labels = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('nav[aria-label="Primary"] a')].map((node) => node.textContent.trim());
        JS);
        self::assertSame(['New log', 'Planner', 'Calendar', 'Trips', 'Income', 'Bills', 'Envelopes', 'Accounts', 'Vehicles'], $labels);
        $rules = self::driver()->executeScript(<<<'JS'
            const nav = document.querySelector('nav[aria-label="Primary"]');
            const betweenLinks = (left, right) => {
                const nodes = [...nav.querySelectorAll('a, hr.nav-rule')];
                const start = nodes.findIndex((node) => node.tagName === 'A' && node.textContent.trim() === left);
                const end = nodes.findIndex((node) => node.tagName === 'A' && node.textContent.trim() === right);
                return start >= 0 && end > start && nodes.slice(start + 1, end).some((node) => node.tagName === 'HR');
            };
            const foot = nav.querySelector('.nav-foot');
            const kids = [...foot.children];
            const demo = kids.findIndex((node) => node.textContent.trim() === 'Demo');
            const pill = kids.findIndex((node) => node.classList.contains('theme-pill'));
            const demoRule = kids.slice(demo + 1, pill).some((node) => node.tagName === 'HR' && node.classList.contains('nav-rule'));
            return {
                logPlanner: betweenLinks('New log', 'Planner'),
                tripsIncome: betweenLinks('Trips', 'Income'),
                envelopesAccounts: betweenLinks('Envelopes', 'Accounts'),
                demoPill: demoRule,
            };
        JS);
        self::assertIsArray($rules);
        self::assertTrue($rules['logPlanner']);
        self::assertTrue($rules['tripsIncome']);
        self::assertTrue($rules['envelopesAccounts']);
        self::assertTrue($rules['demoPill']);
        $footer = self::driver()->executeScript(<<<'JS'
            const foot = document.querySelector('nav[aria-label="Primary"] .nav-foot');
            return [...foot.children].map((node) => [
                node.tagName,
                node.textContent.trim(),
                node.classList.contains('linkish'),
                node.getAttribute('href'),
            ]);
        JS);
        self::assertSame([
            ['HR', '', false, null],
            ['P', 'Demo', false, null],
            ['BUTTON', 'Reset demo data', true, null],
            ['HR', '', false, null],
            ['DIV', '', false, null],
            ['BUTTON', 'Log out', true, null],
        ], $footer);
        $marks = self::driver()->executeScript(<<<'JS'
            const nav = document.querySelector('nav[aria-label="Primary"]');
            const log = nav.querySelector('a.nav-log');
            const above = log.previousElementSibling;
            return {
                ruleAboveLog: above?.tagName === 'HR' && above.classList.contains('nav-rule'),
                icons: [...nav.querySelectorAll('a')].map((node) => [
                    node.textContent.trim(),
                    node.querySelector('[aria-hidden="true"]') !== null,
                    node.classList.contains('linkish'),
                ]),
            };
        JS);
        self::assertTrue($marks['ruleAboveLog']);
        self::assertSame([
            ['New log', true, false],
            ['Planner', true, false],
            ['Calendar', true, false],
            ['Trips', true, false],
            ['Income', true, false],
            ['Bills', true, false],
            ['Envelopes', true, false],
            ['Accounts', true, false],
            ['Vehicles', true, false],
        ], $marks['icons']);
        self::clickLink('Planner');
        self::waitForText('Current Month');
        self::click('Next Month');
        self::wait()->until(function (): bool {
            $pressed = self::driver()->executeScript(<<<'JS'
                const button = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Next Month');
                return button ? button.getAttribute('aria-pressed') : '';
            JS);

            return $pressed === 'true';
        });
        $gauge = WebDriverBy::xpath('//button[contains(@class, "gauge") and contains(., "Needs")]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($gauge))->click();
        self::waitForText('Amounts assigned in the month on screen.');
        self::click('Close');
        self::wait()->until(function (): bool {
            $gone = self::driver()->executeScript(<<<'JS'
                const dialog = document.querySelector('dialog.gauge-modal');
                if (!dialog) return true;
                return getComputedStyle(dialog).display === 'none';
            JS);

            return $gone === true;
        });
        self::clickLink('Calendar');
        self::waitForText('Sun');
        self::clickLink('Accounts');
        self::clickLink('Vehicles');
        $vehicle = WebDriverBy::xpath('//a[contains(., "Vehicle 1")]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($vehicle))->click();
        self::waitForText('Stretch mpg');
        self::waitForText('8.0');
        $help = WebDriverBy::cssSelector('button[aria-label="How the range is used"]');
        $button = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($help));
        self::assertNull($button->getAttribute('title'));
        $button->click();
        $copy = 'The min/max range is to throw out outlier mpg readings from missed odometer entries and fill-ups while not towing';
        self::wait()->until(function () use ($copy): bool {
            $text = self::driver()->executeScript(<<<'JS'
                const dialog = document.querySelector('dialog.modal[open]');
                return dialog ? dialog.innerText : '';
            JS);

            return is_string($text) && str_contains($text, $copy);
        });
        self::click('Close');
        self::wait()->until(function (): bool {
            $open = self::driver()->executeScript(<<<'JS'
                return document.querySelector('dialog.modal[open]') !== null;
            JS);

            return $open === false;
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

    private static function type(string $name, string $value): void
    {
        $by = WebDriverBy::name($name);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        self::driver()->executeScript(
            'arguments[0].scrollIntoView({block:"center", inline:"nearest"})',
            [$element],
        );
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        $element->clear();
        $element->sendKeys($value);
    }

    private static function replace(string $name, string $value): void
    {
        $by = WebDriverBy::name($name);
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        self::driver()->executeScript(
            'arguments[0].scrollIntoView({block:"center", inline:"nearest"})',
            [$element],
        );
        $element = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        $element->click();
        self::driver()->executeScript(
            <<<'JS'
                const input = arguments[0];
                const value = arguments[1];
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
                setter.call(input, value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            JS,
            [$element, $value],
        );
        self::wait()->until(function () use ($name, $value): bool {
            $current = self::driver()->executeScript(
                'const input = document.querySelector(arguments[0]); return input ? input.value : null;',
                ['input[name="' . $name . '"]'],
            );

            return $current === $value;
        });
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

    private static function assertBalanceChipOnStayOne(): string
    {
        $chip = self::chipOnCard('.balance-due-chip');
        self::assertSame('$120.00 Due Nov 22', $chip['text']);
        self::assertStringNotContainsString('2026', $chip['text']);
        self::assertTrue($chip['icon']);
        self::assertStringContainsString('M12 5v13.5', $chip['path']);
        self::assertSame('rgb(31,95,147)', $chip['color']);
        self::assertSame($chip['ink'], $chip['iconInk']);
        self::assertSame(0, $chip['stray']);
        $card = self::stayCardFacts();
        self::assertStringContainsString('Nov 20, 2026', $card['text']);
        self::assertStringNotContainsString('miles from previous stay', $card['text']);
        self::assertStringNotContainsString('Fuel ·', $card['text']);
        self::assertFalse($card['fuel']);
        self::assertFalse($card['mpg']);
        self::assertFalse($card['price']);
        self::assertFalse($card['miles']);

        return $chip['color'];
    }

    private static function assertFuelChipOnStayTwo(string $balanceColor): void
    {
        $chip = self::chipOnCard('.fuel-amount-chip');
        self::assertMatchesRegularExpression('/^Fuel \$\d[\d,]*\.\d{2}$/', $chip['text']);
        self::assertTrue($chip['icon']);
        self::assertStringContainsString('M12 19.5c2.6', $chip['path']);
        self::assertSame('rgb(154,103,9)', $chip['color']);
        self::assertNotSame($balanceColor, $chip['color']);
        self::assertSame($chip['ink'], $chip['iconInk']);
        self::assertSame(0, $chip['stray']);
        $balance = self::chipOnCard('.balance-due-chip');
        self::assertSame('$70.00 Due Dec 1', $balance['text']);
        self::assertStringNotContainsString('2026', $balance['text']);
        self::assertStringNotContainsString('$80.00', $balance['text']);
        self::assertSame('rgb(31,95,147)', $balance['color']);
        $mpg = self::chipOnCard('.mpg-chip');
        self::assertSame('8.0 mpg', $mpg['text']);
        self::assertTrue($mpg['icon']);
        self::assertStringContainsString('M6 16.5V9.5', $mpg['path']);
        self::assertSame('rgb(106,75,178)', $mpg['color']);
        self::assertSame(0, $mpg['stray']);
        $price = self::chipOnCard('.fuel-price-chip');
        self::assertSame('$4.00/gal', $price['text']);
        self::assertTrue($price['icon']);
        self::assertSame('rect', $price['drawn']);
        self::assertSame('rgb(194,65,85)', $price['color']);
        self::assertSame(0, $price['stray']);
        self::assertNotSame($chip['color'], $mpg['color']);
        self::assertNotSame($chip['color'], $price['color']);
        self::assertNotSame($mpg['color'], $price['color']);
        self::assertNotSame($balance['color'], $mpg['color']);
        self::assertNotSame($balance['color'], $price['color']);
        $miles = self::chipOnCard('.miles-chip');
        self::assertMatchesRegularExpression('/^\d+ miles$/', $miles['text']);
        self::assertStringNotContainsString('from previous stay', $miles['text']);
        self::assertTrue($miles['icon']);
        self::assertStringContainsString('M4 14.5', $miles['path']);
        self::assertSame('rgb(45,107,64)', $miles['color']);
        self::assertSame(0, $miles['stray']);
        self::assertNotSame($balance['color'], $miles['color']);
        self::assertNotSame($chip['color'], $miles['color']);
        self::assertNotSame($mpg['color'], $miles['color']);
        self::assertNotSame($price['color'], $miles['color']);
        $card = self::stayCardFacts();
        self::assertStringContainsString('Dec 1, 2026', $card['text']);
        self::assertStringNotContainsString('miles from previous stay', $card['text']);
        self::assertStringNotContainsString('Fuel ·', $card['text']);
    }

    /** @return array{text:string,color:string,ink:string,iconInk:string,icon:bool,path:string,drawn:string,stray:int} */
    private static function chipOnCard(string $selector): array
    {
        $chip = self::driver()->executeScript(
            <<<'JS'
                const card = document.querySelector('.stay-card');
                const chip = card ? card.querySelector(arguments[0]) : null;
                if (!chip) return null;
                const icon = chip.querySelector('svg');
                const box = icon ? icon.getBoundingClientRect() : null;
                const path = icon ? icon.querySelector('path') : null;
                const drawn = icon ? icon.querySelector('path, rect, circle, ellipse') : null;
                return {
                    text: chip.innerText.replace(/\s+/g, ' ').trim(),
                    color: getComputedStyle(chip).backgroundColor.replace(/\s+/g, ''),
                    ink: getComputedStyle(chip).color.replace(/\s+/g, ''),
                    iconInk: icon ? getComputedStyle(icon).color.replace(/\s+/g, '') : '',
                    icon: Boolean(icon && drawn && box && box.width >= 8 && box.height >= 8),
                    path: path ? path.getAttribute('d') || '' : '',
                    drawn: drawn ? drawn.tagName.toLowerCase() : '',
                    stray: document.querySelectorAll('button ' + arguments[0]).length,
                };
            JS,
            [$selector],
        );
        self::assertIsArray($chip);

        return $chip;
    }

    /** @return array{text:string,fuel:bool,mpg:bool,price:bool,miles:bool} */
    private static function stayCardFacts(): array
    {
        $card = self::driver()->executeScript(<<<'JS'
            const card = document.querySelector('.stay-card');
            if (!card) return null;
            return {
                text: card.innerText,
                fuel: Boolean(card.querySelector('.fuel-amount-chip')),
                mpg: Boolean(card.querySelector('.mpg-chip')),
                price: Boolean(card.querySelector('.fuel-price-chip')),
                miles: Boolean(card.querySelector('.miles-chip')),
            };
        JS);
        self::assertIsArray($card);

        return $card;
    }

    private static function assertStayRating(): void
    {
        $before = self::driver()->executeScript(<<<'JS'
            const edit = [...document.querySelectorAll('a, button')].some((node) => node.textContent.trim() === 'Edit');
            const rating = document.querySelector('[aria-label="Rating"]');
            const comment = document.querySelector('input[name="ratingComment"]');
            const zero = document.querySelector('[aria-label="0 stars"]');
            const nav = document.querySelector('.stay-toolbar-nav');
            return {
                beside: Boolean(rating && nav && rating.closest('.stay-toolbar-nav') === nav),
                edit,
                comment: comment !== null,
                zero: zero !== null,
                value: document.querySelector('.stay-rating-value') !== null,
            };
        JS);
        self::assertIsArray($before);
        self::assertTrue($before['beside']);
        self::assertFalse($before['edit']);
        self::assertFalse($before['comment']);
        self::assertFalse($before['zero']);
        self::assertFalse($before['value']);

        self::clickStar('1.0 stars');
        $whole = self::waitForRating('1.0');
        self::assertStringContainsString('1.0', $whole['text']);
        self::clickStar('4.0 stars');
        $four = self::waitForRating('4.0');
        self::assertStringContainsString('4.0', $four['text']);
        self::clickStar('1.5 stars');
        $half = self::waitForRating('1.5');
        self::assertStringContainsString('1.5', $half['text']);
        self::assertEqualsWithDelta($whole['width'], $half['width'], 1.0);
        $ready = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="ratingComment"]');
                const star = document.querySelector('button[aria-label="1.5 stars"]');
                const shape = star ? star.closest('.stay-star') : null;
                const fill = shape ? shape.querySelector('.stay-star-fill') : null;
                const line = shape ? shape.querySelector('.stay-star-line') : null;
                const clip = fill ? getComputedStyle(fill).clipPath.replace(/\s+/g, '') : '';
                const fillColor = fill ? getComputedStyle(fill).color.replace(/\s+/g, '') : '';
                const stroke = line ? getComputedStyle(line).stroke.replace(/\s+/g, '') : '';
                const lineFill = line ? getComputedStyle(line).fill : '';
                const value = document.querySelector('.stay-rating-value');
                const next = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Next');
                const bar = document.querySelector('.stay-toolbar');
                const preview = shape ? shape.querySelector('.stay-star-preview') : null;
                const previewClip = preview ? getComputedStyle(preview).clipPath.replace(/\s+/g, '') : '';
                const texts = [];
                const visit = (rules) => {
                    for (const rule of rules) {
                        if (rule.selectorText) texts.push(rule.cssText);
                        if (rule.cssRules && rule.cssRules.length) visit(rule.cssRules);
                    }
                };
                for (const sheet of document.styleSheets) {
                    try { visit(sheet.cssRules); } catch (error) {}
                }
                const hover = texts.find((text) => text.includes('.stay-star:hover') && text.includes('scale(1.03)')) || '';
                const glow = texts.some((text) => text.includes('.stay-star:hover') && text.includes('drop-shadow') && text.includes('color-mix'));
                const sameLine = input && next && Math.abs(input.getBoundingClientRect().top - next.getBoundingClientRect().top) < 24;
                const withNav = next && shape && Math.abs(next.getBoundingClientRect().top - shape.getBoundingClientRect().top) < 16;
                const save = [...document.querySelectorAll('button')].some((node) => node.textContent.trim() === 'Save comment');
                return {
                    input: Boolean(input),
                    inBar: Boolean(input && input.closest('.stay-toolbar') === bar),
                    sameLine,
                    withNav,
                    pressed: star ? star.getAttribute('aria-pressed') : null,
                    fill: shape ? shape.getAttribute('data-fill') : null,
                    fills: shape ? shape.querySelectorAll('.stay-star-fill').length : 0,
                    clip,
                    fillColor,
                    stroke,
                    lineFill,
                    rating: value ? value.getAttribute('data-rating') : null,
                    text: value ? value.textContent : null,
                    mark: Boolean(value && value.querySelector('svg')),
                    preview: Boolean(preview),
                    previewClip,
                    hover,
                    glow,
                    save,
                    zero: document.querySelector('[aria-label="0 stars"]') !== null,
                    inputTop: input ? input.getBoundingClientRect().top : null,
                    nextTop: next ? next.getBoundingClientRect().top : null,
                    starTop: shape ? shape.getBoundingClientRect().top : null,
                };
            JS);
        self::assertIsArray($ready);
        self::assertTrue(
            $ready['input']
                && $ready['inBar']
                && $ready['sameLine']
                && $ready['withNav']
                && $ready['pressed'] === 'true'
                && $ready['fill'] === '0.5'
                && $ready['fills'] === 1
                && str_contains((string) $ready['clip'], '50%')
                && $ready['fillColor'] === 'rgb(62,224,197)'
                && (
                    $ready['stroke'] === 'rgb(62,224,197)'
                    || str_starts_with((string) $ready['stroke'], 'oklab(')
                    || str_starts_with((string) $ready['stroke'], 'color(')
                )
                && $ready['lineFill'] === 'none'
                && $ready['rating'] === '1.5'
                && str_contains((string) $ready['text'], '1.5')
                && $ready['mark']
                && $ready['preview']
                && str_contains((string) $ready['hover'], 'scale(1.03)')
                && str_contains((string) $ready['hover'], 'drop-shadow')
                && str_contains((string) $ready['hover'], 'teal')
                && $ready['glow'] === true
                && $ready['save'] === false
                && $ready['zero'] === false,
            json_encode($ready, JSON_THROW_ON_ERROR),
        );

        self::hoverStar('2.5 stars');
        self::assertSame(['full', 'full', 'half', 'empty', 'empty'], self::ghostFills());
        self::hoverStar('3.0 stars');
        usleep(250000);
        $three = self::ghostState();
        self::assertSame(['full', 'full', 'full', 'empty', 'empty'], $three['fills']);
        self::assertSame('1', $three['saved']);
        self::assertSame('0.5', $three['savedHalf']);
        self::assertEqualsWithDelta(1.03, self::matrixScale($three['hoveredScale']), 0.01);
        self::assertStringContainsString('drop-shadow', $three['hoveredFilter']);
        self::assertEqualsWithDelta(1.0, self::matrixScale($three['earlierScale']), 0.01);
        self::assertSame('rgb(62,224,197)', $three['previewColor']);
        $next = self::driver()->findElement(WebDriverBy::xpath('//button[normalize-space()="Next"]'));
        self::driver()->action()->moveToElement($next)->perform();
        self::assertSame(['empty', 'empty', 'empty', 'empty', 'empty'], self::ghostFills());

        self::type('ratingComment', 'Comment 2');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="ratingComment"]');
                return Boolean(input) && input.value === 'Comment 2' && input.getAttribute('data-saved') === 'Comment 2';
            JS);

            return $ready === true;
        });

        self::driver()->navigate()->refresh();
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="ratingComment"]');
                const star = document.querySelector('button[aria-label="1.5 stars"]');
                const shape = star ? star.closest('.stay-star') : null;
                const fill = shape ? shape.querySelector('.stay-star-fill') : null;
                const clip = fill ? getComputedStyle(fill).clipPath.replace(/\s+/g, '') : '';
                const fillColor = fill ? getComputedStyle(fill).color.replace(/\s+/g, '') : '';
                const next = [...document.querySelectorAll('button')].find((node) => node.textContent.trim() === 'Next');
                const value = document.querySelector('.stay-rating-value');
                const sameLine = input && next && Math.abs(input.getBoundingClientRect().top - next.getBoundingClientRect().top) < 24;
                const edit = [...document.querySelectorAll('a, button')].some((node) => node.textContent.trim() === 'Edit');
                return Boolean(input)
                    && input.value === 'Comment 2'
                    && sameLine
                    && !edit
                    && input.closest('.stay-toolbar') === next.closest('.stay-toolbar')
                    && star
                    && star.getAttribute('aria-pressed') === 'true'
                    && shape
                    && shape.getAttribute('data-fill') === '0.5'
                    && clip.includes('50%')
                    && fillColor === 'rgb(62,224,197)'
                    && value
                    && value.getAttribute('data-rating') === '1.5'
                    && document.querySelector('[aria-label="0 stars"]') === null;
            JS);

            return $ready === true;
        });
    }

    private static function clickStar(string $label): void
    {
        $by = WebDriverBy::cssSelector('button[aria-label="' . $label . '"]');
        $star = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$star]);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function matrixScale(string $transform): float
    {
        if (preg_match('/matrix\(([-0-9.]+)/', $transform, $match) !== 1) {
            return $transform === 'none' ? 1.0 : 0.0;
        }

        return (float) $match[1];
    }

    private static function hoverStar(string $label): void
    {
        $by = WebDriverBy::cssSelector('button[aria-label="' . $label . '"]');
        $star = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$star]);
        self::driver()->action()->moveToElement($star)->perform();
    }

    /** @return list<string> */
    private static function ghostFills(): array
    {
        $marks = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('.stay-rating .stay-star')].map((star) => {
                const preview = star.querySelector('.stay-star-preview');
                const style = getComputedStyle(preview);
                const clip = style.clipPath.replace(/\s+/g, '');
                const opacity = Number(style.opacity);
                if (opacity < 0.2) return 'empty';
                return clip.includes('50%') ? 'half' : 'full';
            });
        JS);
        self::assertIsArray($marks);

        return array_map(strval(...), $marks);
    }

    /** @return array{fills: list<string>, saved: string, savedHalf: string, hoveredScale: string, hoveredFilter: string, earlierScale: string, previewColor: string} */
    private static function ghostState(): array
    {
        $state = self::driver()->executeScript(<<<'JS'
            const stars = [...document.querySelectorAll('.stay-rating .stay-star')];
            const fills = stars.map((star) => {
                const preview = star.querySelector('.stay-star-preview');
                const style = getComputedStyle(preview);
                const clip = style.clipPath.replace(/\s+/g, '');
                const opacity = Number(style.opacity);
                if (opacity < 0.2) return 'empty';
                return clip.includes('50%') ? 'half' : 'full';
            });
            const hovered = document.querySelector('button[aria-label="3.0 stars"]').closest('.stay-star');
            const preview = hovered.querySelector('.stay-star-preview');
            return {
                fills,
                saved: stars[0].getAttribute('data-fill'),
                savedHalf: stars[1].getAttribute('data-fill'),
                hoveredScale: getComputedStyle(hovered).transform,
                hoveredFilter: getComputedStyle(hovered).filter,
                earlierScale: getComputedStyle(stars[0]).transform,
                previewColor: getComputedStyle(preview).color.replace(/\s+/g, ''),
            };
        JS);
        self::assertIsArray($state);

        return [
            'fills' => array_map(strval(...), $state['fills']),
            'saved' => (string) $state['saved'],
            'savedHalf' => (string) $state['savedHalf'],
            'hoveredScale' => (string) $state['hoveredScale'],
            'hoveredFilter' => (string) $state['hoveredFilter'],
            'earlierScale' => (string) $state['earlierScale'],
            'previewColor' => (string) $state['previewColor'],
        ];
    }

    /** @return array{rating: string, text: string, width: float} */
    private static function waitForRating(string $label): array
    {
        $mark = self::wait()->until(function () use ($label): array|false {
            $mark = self::driver()->executeScript(<<<'JS'
                const value = document.querySelector('.stay-rating-value');
                if (!value || value.getAttribute('data-rating') !== arguments[0]) return null;
                const box = value.getBoundingClientRect();
                return { rating: value.getAttribute('data-rating'), text: value.textContent, width: box.width };
            JS, [$label]);

            return is_array($mark) ? $mark : false;
        });
        self::assertIsArray($mark);
        self::assertSame($label, $mark['rating']);

        return [
            'rating' => (string) $mark['rating'],
            'text' => (string) $mark['text'],
            'width' => (float) $mark['width'],
        ];
    }

    private static function assertStayColors(): void
    {
        $started = self::tripMonth();
        self::openTripMonth('2026-11');
        $paint = self::stayPaint('2026-11-20', '2026-11-22', '2026-11-24', '2026-11-19', 'Stay 1');
        self::assertSame('poppy', $paint['cardColor']);
        self::assertSame('rgb(255,157,133)', $paint['card']);
        self::assertSame('rgb(255,157,133)', $paint['row']);
        self::assertStringContainsString('Stay 1', $paint['cardText']);
        self::assertStringContainsString('Nov 20, 2026', $paint['cardText']);
        self::assertStringContainsString('Nov 24, 2026', $paint['cardText']);
        self::assertStringContainsString('$120.00 Due Nov 22', $paint['cardText']);
        self::assertStringNotContainsString('Nov 22, 2026', $paint['cardText']);
        self::assertFalse($paint['cardHasTitle']);
        self::assertSame('poppy', $paint['arriveIn']);
        self::assertSame('', $paint['arriveOut']);
        self::assertTrue($paint['arriveHalf']);
        self::assertGradient($paint['arriveImage'], 'rgba(0,0,0,0)', 'rgb(255,157,133)');
        self::assertSame('poppy', $paint['middleIn']);
        self::assertSame('poppy', $paint['middleOut']);
        self::assertTrue($paint['middleCovered']);
        self::assertGradient($paint['middleImage'], 'rgb(255,157,133)', 'rgb(255,157,133)');
        self::assertSame('', $paint['departIn']);
        self::assertSame('poppy', $paint['departOut']);
        self::assertTrue($paint['departHalf']);
        self::assertGradient($paint['departImage'], 'rgb(255,157,133)', 'rgba(0,0,0,0)');
        self::assertSame('none', $paint['emptyImage']);
        self::assertStringNotContainsString('stay-day', $paint['emptyClass']);

        self::openTripMonth('2026-12');
        $next = self::stayPaint('2026-12-01', '2026-12-03', '2026-12-05', '2026-12-06', 'Stay 2');
        self::assertSame('lagoon', $next['rowColor']);
        self::assertSame('rgb(133,230,205)', $next['row']);
        self::assertNotSame($paint['row'], $next['row']);
        self::assertSame('lagoon', $next['arriveIn']);
        self::assertSame('', $next['arriveOut']);
        self::assertGradient($next['arriveImage'], 'rgba(0,0,0,0)', 'rgb(133,230,205)');
        self::assertSame('lagoon', $next['departOut']);
        self::assertGradient($next['departImage'], 'rgb(133,230,205)', 'rgba(0,0,0,0)');

        self::openTripMonth($started);
    }

    private static function assertGradient(string $image, string $first, string $second): void
    {
        $flat = preg_replace('/\s+/', '', $image) ?? $image;
        $flat = str_replace('transparent', 'rgba(0,0,0,0)', $flat);
        self::assertMatchesRegularExpression('/linear-gradient\((?:torighttop|totopright|45deg),/', $flat, $image);
        $firstAt = strpos($flat, $first);
        self::assertNotFalse($firstAt, $image);
        $secondFrom = $first === $second ? $firstAt + strlen($first) : 0;
        $secondAt = strpos($flat, $second, $secondFrom);
        self::assertNotFalse($secondAt, $image);
        self::assertLessThan($secondAt, $firstAt, $image);
    }

    /** @return array<string, mixed> */
    private static function stayPaint(string $arrive, string $middle, string $depart, string $empty, string $name): array
    {
        $paint = self::driver()->executeScript(
            <<<'JS'
                const day = (date) => document.querySelector(`button[data-date="${date}"]`);
                const compact = (value) => String(value || '').replace(/\s+/g, '');
                const card = document.querySelector('.stay-card');
                const row = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(arguments[4]));
                const arrive = day(arguments[0]);
                const middle = day(arguments[1]);
                const depart = day(arguments[2]);
                const empty = day(arguments[3]);
                const image = (node) => (node ? getComputedStyle(node).backgroundImage : '');
                return {
                    cardColor: card ? card.getAttribute('data-stay-color') : '',
                    card: card ? compact(getComputedStyle(card).backgroundColor) : '',
                    cardText: card ? card.innerText : '',
                    cardHasTitle: Boolean(card && card.querySelector('h1')),
                    rowColor: row ? row.getAttribute('data-stay-color') : '',
                    row: row ? compact(getComputedStyle(row).backgroundColor) : '',
                    arriveImage: image(arrive),
                    arriveIn: arrive ? arrive.getAttribute('data-stay-arrive') : '',
                    arriveOut: arrive ? arrive.getAttribute('data-stay-depart') : '',
                    arriveHalf: arrive ? arrive.classList.contains('stay-half') : false,
                    middleImage: image(middle),
                    middleIn: middle ? middle.getAttribute('data-stay-arrive') : '',
                    middleOut: middle ? middle.getAttribute('data-stay-depart') : '',
                    middleCovered: middle ? middle.classList.contains('stay-covered') : false,
                    departImage: image(depart),
                    departIn: depart ? depart.getAttribute('data-stay-arrive') : '',
                    departOut: depart ? depart.getAttribute('data-stay-depart') : '',
                    departHalf: depart ? depart.classList.contains('stay-half') : false,
                    emptyImage: image(empty),
                    emptyClass: empty ? empty.className : '',
                };
            JS,
            [$arrive, $middle, $depart, $empty, $name],
        );
        self::assertIsArray($paint);

        return $paint;
    }

    private static function assertStayCardAndList(): void
    {
        self::assertSame(
            [],
            self::driver()->findElements(WebDriverBy::xpath('//a[normalize-space()="Edit"]|//button[normalize-space()="Edit"]')),
        );
        $row = self::stayListFacts('Stay 1');
        self::assertTrue(
            $row['locate'] && $row['pencil'] && $row['crosshair'] && $row['right'] && $row['inside'] && $row['plain'] && $row['compact'] && $row['card'],
            json_encode($row),
        );
        self::assertPhoneStayListFits();

        self::clickCss('.stay-card-hit');
        self::waitForPin('Stay 1', '1 Fremont St, Las Vegas, NV 89101');
        self::clickLink('Cancel');
        self::waitForText('Stay 1');

        self::wait()->until(function (): bool {
            $view = self::mapView();

            return $view !== null
                && $view['zoom'] < 8
                && abs($view['longitude'] + 115.1398) < 0.8
                && abs($view['latitude'] - 36.1699) < 0.8
                && str_contains($view['pin'], 'Stay 1');
        });
        $wide = self::mapView();
        self::assertNotNull($wide);
        self::clickCss('.stay-list [aria-label="Locate stay"]');
        self::wait()->until(function (): bool {
            $view = self::mapView();

            return $view !== null
                && self::closeZoom($view['zoom'])
                && abs($view['longitude'] + 115.1398) < 0.8
                && abs($view['latitude'] - 36.1699) < 0.8
                && str_contains($view['pin'], 'Stay 1');
        });
        $close = self::mapView();
        self::assertNotNull($close);
        self::assertGreaterThan($wide['zoom'], $close['zoom']);
        self::assertTrue(self::closeZoom($close['zoom']));

        self::click('Next month');
        self::wait()->until(function (): bool {
            return self::driver()->findElements(
                WebDriverBy::xpath('//div[contains(@class,"stay-list")]//strong[normalize-space()="Stay 2"]'),
            ) !== [];
        });
        $held = self::mapView();
        self::assertNotNull($held);
        $name = self::driver()->findElement(WebDriverBy::xpath('//div[contains(@class,"stay-list")]//strong[normalize-space()="Stay 2"]'));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$name]);
        $name->click();
        usleep(700000);
        $still = self::mapView();
        self::assertNotNull($still);
        self::assertEqualsWithDelta($held['longitude'], $still['longitude'], 0.15);
        self::assertEqualsWithDelta($held['latitude'], $still['latitude'], 0.15);
        self::assertEqualsWithDelta($held['zoom'], $still['zoom'], 0.2);
        self::assertStringContainsString('Stay 1', $still['pin']);
        $card = self::driver()->findElement(WebDriverBy::cssSelector('.stay-card'))->getText();
        self::assertStringContainsString('Stay 1', $card);
        self::assertStringNotContainsString('Stay 2', $card);

        $later = self::stayListFacts('Stay 2');
        self::assertTrue($later['locate'] && $later['pencil'] && $later['right'] && $later['plain'], json_encode($later));
        self::clickCss('.stay-list [aria-label="Locate stay"]');
        self::wait()->until(function (): bool {
            $view = self::mapView();
            if ($view === null || !self::closeZoom($view['zoom'])) {
                return false;
            }
            $text = self::driver()->findElement(WebDriverBy::cssSelector('.stay-card'))->getText();

            return abs($view['longitude'] + 118.2437) < 0.8
                && abs($view['latitude'] - 34.0522) < 0.8
                && str_contains($view['pin'], 'Stay 2')
                && str_contains($view['pin'], 'Los Angeles, CA')
                && str_contains($text, 'Stay 2');
        });
        $moved = self::mapView();
        self::assertNotNull($moved);
        self::assertTrue(self::closeZoom($moved['zoom']));
        self::clickCss('.stay-list [aria-label="Locate stay"]');
        usleep(700000);
        $again = self::mapView();
        self::assertNotNull($again);
        self::assertTrue(self::closeZoom($again['zoom']));
        self::assertEqualsWithDelta($moved['longitude'], $again['longitude'], 0.15);
        self::assertEqualsWithDelta($moved['latitude'], $again['latitude'], 0.15);
        self::assertStringContainsString('Stay 2', $again['pin']);

        self::clickCss('.stay-list [aria-label="Edit stay"]');
        self::waitForPin('Stay 2', '1 Main St, Los Angeles, CA 90012');
        self::clickLink('Cancel');
        self::waitForText('Stay 1');
    }

    private static function closeZoom(float $zoom): bool
    {
        return $zoom >= 14.5 && $zoom < 15.5;
    }

    private static function waitForPin(string $name, string $address): void
    {
        self::wait()->until(function () use ($name, $address): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const pin = document.querySelector('.globe .map-pin');
                const label = pin ? pin.getAttribute('aria-label') || '' : '';
                return label.includes(arguments[0]) && label.includes(arguments[1]);
            JS, [$name, $address]);

            return $ready === true;
        });
    }

    private static function clickCss(string $selector): void
    {
        $by = WebDriverBy::cssSelector($selector);
        $node = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$node]);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    /** @return array{longitude: float, latitude: float, zoom: float, pin: string}|null */
    private static function mapView(): ?array
    {
        $view = self::driver()->executeScript(<<<'JS'
            const globe = document.querySelector('.globe');
            const pin = document.querySelector('.globe button.map-pin.selected');
            if (!globe || globe.dataset.longitude === undefined || globe.dataset.latitude === undefined || globe.dataset.zoom === undefined) return null;
            const longitude = Number(globe.dataset.longitude);
            const latitude = Number(globe.dataset.latitude);
            const zoom = Number(globe.dataset.zoom);
            if (!Number.isFinite(longitude) || !Number.isFinite(latitude) || !Number.isFinite(zoom)) return null;
            return {
                longitude,
                latitude,
                zoom,
                pin: pin ? (pin.getAttribute('aria-label') || '') : '',
            };
        JS);
        if (
            !is_array($view)
            || !is_numeric($view['longitude'])
            || !is_numeric($view['latitude'])
            || !is_numeric($view['zoom'])
        ) {
            return null;
        }

        return [
            'longitude' => (float) $view['longitude'],
            'latitude' => (float) $view['latitude'],
            'zoom' => (float) $view['zoom'],
            'pin' => (string) $view['pin'],
        ];
    }

    /** @return array{locate: bool, pencil: bool, crosshair: bool, right: bool, inside: bool, plain: bool, compact: bool, card: bool} */
    private static function stayListFacts(string $name): array
    {
        $facts = self::driver()->executeScript(<<<'JS'
            const row = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(arguments[0]));
            const card = document.querySelector('.stay-card-hit');
            if (!row) return null;
            const locate = row.querySelector('[aria-label="Locate stay"]');
            const edit = row.querySelector('[aria-label="Edit stay"]');
            const title = row.querySelector('strong');
            const locateBox = locate ? locate.getBoundingClientRect() : null;
            const editBox = edit ? edit.getBoundingClientRect() : null;
            const titleBox = title ? title.getBoundingClientRect() : null;
            const rowBox = row.getBoundingClientRect();
            const path = edit ? edit.querySelector('path') : null;
            const mark = locate ? locate.querySelector('circle') : null;
            return {
                locate: Boolean(locate && locate.tagName === 'BUTTON' && locate.getAttribute('type') === 'button'),
                pencil: Boolean(edit && edit.tagName === 'BUTTON' && path && (path.getAttribute('d') || '').includes('M13.2 5.4')),
                crosshair: Boolean(mark),
                right: Boolean(locateBox && editBox && titleBox && locateBox.left >= titleBox.right - 1 && editBox.left >= locateBox.right - 1),
                inside: Boolean(editBox && locateBox && editBox.right <= rowBox.right + 1 && locateBox.left >= rowBox.left),
                plain: row.tagName !== 'BUTTON' && Boolean(title) && title.closest('button') === null,
                compact: Boolean(locateBox && editBox && locateBox.width <= 48 && locateBox.height <= 48 && editBox.width <= 48 && editBox.height <= 48),
                card: Boolean(card && card.tagName === 'BUTTON' && card.getAttribute('type') === 'button' && card.getAttribute('aria-label') === 'Edit stay'),
            };
        JS, [$name]);
        self::assertIsArray($facts);

        return [
            'locate' => (bool) $facts['locate'],
            'pencil' => (bool) $facts['pencil'],
            'crosshair' => (bool) $facts['crosshair'],
            'right' => (bool) $facts['right'],
            'inside' => (bool) $facts['inside'],
            'plain' => (bool) $facts['plain'],
            'compact' => (bool) $facts['compact'],
            'card' => (bool) $facts['card'],
        ];
    }

    private static function assertPhoneStayListFits(): void
    {
        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        try {
            $fit = self::driver()->executeScript(<<<'JS'
                const row = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes('Stay 1'));
                if (!row) return null;
                const box = row.getBoundingClientRect();
                const buttons = [...row.querySelectorAll('button')].map((node) => node.getBoundingClientRect());
                return {
                    row: row.scrollWidth <= row.clientWidth + 1,
                    screen: box.left >= -1 && box.right <= window.innerWidth + 1,
                    buttons: buttons.length === 2 && buttons.every((item) => item.left >= -1 && item.right <= window.innerWidth + 1),
                };
            JS);
            self::assertIsArray($fit);
            self::assertTrue($fit['row'] && $fit['screen'] && $fit['buttons'], json_encode($fit));
        } finally {
            self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        }
    }

    private static function tripMonth(): string
    {
        $current = self::driver()->executeScript(<<<'JS'
            const node = document.querySelector('[data-month]');
            return node ? node.getAttribute('data-month') : '';
        JS);
        if (!is_string($current) || preg_match('/^\d{4}-\d{2}$/', $current) !== 1) {
            throw new RuntimeException('The trip month is missing.');
        }

        return $current;
    }

    private static function openTripMonth(string $month): void
    {
        for ($step = 0; $step < 36; $step++) {
            $current = self::tripMonth();
            if ($current === $month) {
                return;
            }
            self::click($current > $month ? 'Previous month' : 'Next month');
        }

        throw new RuntimeException('Could not reach ' . $month);
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
