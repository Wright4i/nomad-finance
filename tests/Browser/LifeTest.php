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
        self::waitForText('Usery Mountain');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                return document.querySelector('[aria-label="Rating"]') !== null;
            JS);

            return $ready === true;
        });
        self::assertStayRating();
        self::assertStayColors();
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const globe = document.querySelector('.globe');
                const canvas = globe && globe.querySelector('canvas');
                const failed = document.body.innerText.includes('The globe did not start');
                return Boolean(canvas) && !failed && globe.dataset.basemap === 'positron';
            JS);

            return $ready === true;
        });
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const labels = [...document.querySelectorAll('.globe button.map-pin')].map((node) => node.getAttribute('aria-label') || '');
                const route = document.querySelector('.globe .map-leg');
                return Boolean(route)
                    && labels.some((label) => label.includes('Lost Dutchman') && label.includes('Apache Junction, AZ'))
                    && labels.some((label) => label.includes('Cottonwood') && label.includes('Joshua Tree, CA'));
            JS);

            return $ready === true;
        });
        self::assertStayCardAndList();
        self::clickStayAction('Lost Dutchman', 'Locate stay');
        self::wait()->until(function (): bool {
            $text = self::driver()->findElement(WebDriverBy::cssSelector('.stay-card'))->getText();

            return str_contains($text, 'Lost Dutchman') && str_contains($text, '-$140.00 Due Nov 22');
        });
        $balanceIcon = self::assertBalanceChipOnStayOne();
        self::click('Next');
        self::waitForText('Cattail Cove');
        self::assertSame('2026-11', self::tripMonth());
        self::click('Next');
        self::waitForText('Cottonwood');
        self::waitForText('Dec 2026');
        self::assertSame('2026-12', self::tripMonth());
        self::click('Previous');
        self::waitForText('Cattail Cove');
        self::waitForText('Nov 2026');
        self::assertSame('2026-11', self::tripMonth());
        self::click('Next');
        self::waitForText('Cottonwood');
        self::waitForText('Site 4');
        self::assertFuelChipOnStayTwo($balanceIcon);
        self::clickLink('Add a stay');
        self::type('placeName', 'Park 1');
        self::replace('note', 'SITE #34');
        self::type('latitude', '36.5');
        self::type('longitude', '-115.2');
        self::replace('arriveOn', '01 / 09 / 2027');
        self::replace('departOn', '01 / 12 / 2027');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const globe = document.querySelector('.globe');
                if (!globe || globe.dataset.basemap !== 'satellite') return false;
                const zoom = Number(globe.dataset.zoom);
                return zoom >= 14.5 && zoom < 15.5;
            JS);

            return $ready === true;
        });
        self::choose('accountId', 'Checking');
        self::type('total', '20');
        self::type('amountDue', '10');
        self::click('Save stay');
        self::waitForText('Park 1');
        self::click('Next month');
        self::waitForText('Dec 2026');
        self::click('Next month');
        self::waitForText('Jan 2027');
        self::clickStayAction('Park 1', 'Locate stay');
        self::wait()->until(function (): bool {
            $text = self::driver()->findElement(WebDriverBy::cssSelector('.stay-card'))->getText();

            return str_contains($text, 'Park 1') && str_contains($text, 'SITE #34');
        });
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
                return label.includes('Pickup');
            JS);

            return $ready === true;
        });
        self::type('amount', '4');
        self::replace('odometer', '64800');
        self::click('Save log');
        self::waitForText('That reading is below the current odometer.');
        self::replace('odometer', '65000');
        self::waitForText('100 mi.');
        self::click('Save log');
        self::waitForText('New log');
        self::assertSame('', self::driver()->findElement(WebDriverBy::name('amount'))->getAttribute('value'));
        self::clickLink('Accounts');
        self::waitForText('$50,000.00');
        self::clickLink('New log');
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('input[name="date"]');
            input.value = '01 / 01 / 2099';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::type('amount', '1');
        self::replace('odometer', '65000');
        self::click('Save log');
        self::waitForText('The date cannot be after today.');
    }

    #[Depends('testLogShowsTheBalanceBeforeItMovesAndAFuelStretch')]
    public function testTheNavHasFiveHubsAndTheHorizonSaves(): void
    {
        $labels = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('nav[aria-label="Primary"] a')].map((node) => node.textContent.trim());
        JS);
        self::assertSame(['New log', 'Planner', 'Calendar', 'Trips', 'Bills', 'Budget', 'Transfers', 'Accounts', 'Income', 'Vehicles'], $labels);
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
            const pill = kids.findIndex((node) => node.classList.contains('theme-pill'));
            const logout = kids.findIndex((node) => node.textContent.trim() === 'Log out');
            return {
                logPlanner: betweenLinks('New log', 'Planner'),
                tripsIncome: betweenLinks('Trips', 'Income'),
                envelopesAccounts: betweenLinks('Budget', 'Accounts'),
                demoPill: pill >= 0 && logout === pill + 1,
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
            ['DIV', 'Nov 1 - Nov 30', false, null],
            ['HR', '', false, null],
            ['BUTTON', 'Demo', true, null],
            ['BUTTON', 'Reset demo data', true, null],
            ['DIV', '', false, null],
            ['BUTTON', 'Log out', true, null],
        ], $footer);
        $marks = self::driver()->executeScript(<<<'JS'
            const nav = document.querySelector('nav[aria-label="Primary"]');
            const log = nav.querySelector('a.nav-log');
            const above = log.previousElementSibling;
            const transfers = [...nav.querySelectorAll('a')].find((node) => node.textContent.trim() === 'Transfers');
            return {
                ruleAboveLog: above?.tagName === 'HR' && above.classList.contains('nav-rule'),
                icons: [...nav.querySelectorAll('a')].map((node) => [
                    node.textContent.trim(),
                    node.querySelector('[aria-hidden="true"]') !== null,
                    node.classList.contains('linkish'),
                ]),
                transfers: transfers
                    ? [...transfers.querySelectorAll('.glyph path')].map((path) => path.getAttribute('d') || '')
                    : [],
            };
        JS);
        self::assertTrue($marks['ruleAboveLog']);
        self::assertSame([
            ['New log', true, false],
            ['Planner', true, false],
            ['Calendar', true, false],
            ['Trips', true, false],
            ['Bills', true, false],
            ['Budget', true, false],
            ['Transfers', true, false],
            ['Accounts', true, false],
            ['Income', true, false],
            ['Vehicles', true, false],
        ], $marks['icons']);
        self::assertSame([
            'M3 7h3a5 5 0 0 1 5 5 5 5 0 0 0 5 5h5M18 20l3-3-3-3',
            'M3 17h3a5 5 0 0 0 5-5 5 5 0 0 1 5-5h5M18 4l3 3-3 3',
        ], $marks['transfers']);
        self::assertCollapsedSidebar();
        self::clickLink('Planner');
        self::waitForText('$805.00');
        $gauge = WebDriverBy::xpath('//button[contains(@class, "gauge") and contains(., "Needs")]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($gauge))->click();
        self::waitForText('Amounts assigned in the period on screen.');
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
        $vehicle = WebDriverBy::xpath('//a[contains(., "Pickup")]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($vehicle))->click();
        self::waitForText('Stretch mpg');
        self::waitForText('8.0');
        self::assertCount(0, self::driver()->findElements(WebDriverBy::cssSelector('button[aria-label="How the range is used"]')));
        $mpg = self::driver()->findElement(WebDriverBy::xpath('//label[.//input[@name="mpgMin"]]/span'));
        self::assertSame('help', $mpg->getCssValue('cursor'));
        self::driver()->action()->moveToElement($mpg)->perform();
        self::wait()->until(function (): bool {
            $text = self::driver()->executeScript(<<<'JS'
                const tip = document.querySelector('.nav-tip');
                return tip ? tip.textContent : '';
            JS);

            return is_string($text) && str_contains($text, 'left out of the average');
        });
        $mpgTip = self::driver()->executeScript(<<<'JS'
            const label = document.querySelector('input[name="mpgMin"]')?.closest('label')?.querySelector('span');
            const tip = document.querySelector('.nav-tip');
            if (!label || !tip) return null;
            const box = label.getBoundingClientRect();
            const tipBox = tip.getBoundingClientRect();
            return {
                open: tip.matches(':popover-open'),
                left: Math.abs(tipBox.left - box.left),
                above: tipBox.bottom <= box.top + 1,
            };
        JS);
        self::assertIsArray($mpgTip);
        self::assertTrue($mpgTip['open']);
        self::assertLessThan(2, (float) $mpgTip['left']);
        self::assertTrue($mpgTip['above']);
        self::click('Demo');
        self::waitForText('Name and password');
        $period = self::driver()->findElement(WebDriverBy::xpath('//dialog[contains(@class, "modal")]//span[normalize-space()="Period starts"]'));
        self::driver()->action()->moveToElement($period)->perform();
        $settingsTip = self::wait()->until(function (): array|false {
            $placed = self::driver()->executeScript(<<<'JS'
                const label = [...document.querySelectorAll('dialog.modal span')].find((node) => node.textContent.trim() === 'Period starts');
                const tip = document.querySelector('.nav-tip');
                if (!label || !tip || !tip.textContent.includes('Calendar and trips')) return null;
                const box = label.getBoundingClientRect();
                const tipBox = tip.getBoundingClientRect();
                const dialog = label.closest('dialog');
                const panel = dialog ? dialog.getBoundingClientRect() : null;
                return {
                    open: tip.matches(':popover-open'),
                    left: Math.abs(tipBox.left - box.left),
                    above: tipBox.bottom <= box.top + 1,
                    overDialog: panel ? tipBox.bottom < panel.bottom && tipBox.top < panel.top + panel.height : false,
                    onScreen: tipBox.left >= 4 && tipBox.right <= window.innerWidth - 4 && tipBox.width > 8,
                };
            JS);

            return is_array($placed) ? $placed : false;
        });
        self::assertTrue($settingsTip['open']);
        self::assertLessThan(2, (float) $settingsTip['left']);
        self::assertTrue($settingsTip['above']);
        self::assertTrue($settingsTip['overDialog']);
        self::assertTrue($settingsTip['onScreen']);
        self::click('Cancel');
        self::assertANestedNightShowsAndCanMoveEarlier();
    }

    public function testTheTransfersNavUsesAShuffleMark(): void
    {
        self::driver()->get(self::$base . '/');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const setup = document.querySelector('input[name="name"]');
                const demo = [...document.querySelectorAll('button')].some((node) => node.textContent.trim() === 'Use demo');
                const nav = document.querySelector('nav[aria-label="Primary"] a[href="/transfers"]');
                return Boolean(setup || demo || nav);
            JS);

            return $ready === true;
        });
        $mode = self::driver()->executeScript(<<<'JS'
            if (document.querySelector('nav[aria-label="Primary"] a[href="/transfers"]')) return 'nav';
            if ([...document.querySelectorAll('button')].some((node) => node.textContent.trim() === 'Use demo')) return 'demo';
            return 'setup';
        JS);
        if ($mode === 'setup') {
            self::type('name', 'Ada');
            self::type('password', self::PASSWORD);
            self::type('currentOdometer', '0');
            self::click('Create my login');
            self::waitForText('Net worth');
            self::click('Log out');
            self::waitForText('Use demo');
            $mode = 'demo';
        }
        if ($mode === 'demo') {
            self::click('Use demo');
            self::waitForText('Available to assign');
        }
        $paths = self::driver()->executeScript(<<<'JS'
            const link = [...document.querySelectorAll('nav[aria-label="Primary"] a')].find((node) => node.getAttribute('href') === '/transfers');
            const log = document.querySelector('nav[aria-label="Primary"] a.nav-log');
            const trips = [...document.querySelectorAll('nav[aria-label="Primary"] a')].find((node) => node.getAttribute('href') === '/trips');
            const vehicles = [...document.querySelectorAll('nav[aria-label="Primary"] a')].find((node) => node.getAttribute('href') === '/vehicles');
            const drawn = (node) => node ? [...node.querySelectorAll('.glyph path')].map((path) => path.getAttribute('d') || '') : [];
            return {
                transfers: drawn(link),
                log: drawn(log),
                trips: drawn(trips),
                vehicles: drawn(vehicles),
            };
        JS);
        self::assertIsArray($paths);
        self::assertSame([
            'M3 7h3a5 5 0 0 1 5 5 5 5 0 0 0 5 5h5M18 20l3-3-3-3',
            'M3 17h3a5 5 0 0 0 5-5 5 5 0 0 1 5-5h5M18 4l3 3-3 3',
        ], $paths['transfers']);
        self::assertSame(['M12 6v12M6 12h12'], $paths['log']);
        self::assertSame([
            'M9 7.2V5.8A1.3 1.3 0 0 1 10.3 4.5h3.4A1.3 1.3 0 0 1 15 5.8V7.2',
            'M5.5 7.2h13a2 2 0 0 1 2 2v8.3a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V9.2a2 2 0 0 1 2-2z',
            'M3.5 12.2h17M12 12.2v2.2',
        ], $paths['trips']);
        self::assertSame([
            'M6 16.5V9.5h12v7M6 12.5h12M8.5 9.5 10 6.5h4l1.5 3',
        ], $paths['vehicles']);
    }

    private static function assertANestedNightShowsAndCanMoveEarlier(): void
    {
        $created = self::driver()->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            fetch('/api/session', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((session) => fetch('/api/trips', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': session.csrfToken,
                    },
                    body: JSON.stringify({
                        placeName: 'Guest house',
                        arriveOn: '2026-11-22',
                        departOn: '2026-11-23',
                        latitude: '33.40',
                        longitude: '-111.80',
                        totalMinor: 0,
                        amountDueMinor: 0,
                        travel: 'drive',
                        today: '2026-11-06',
                    }),
                }))
                .then(async (response) => {
                    const data = await response.json();
                    done({
                        ok: response.status,
                        message: data.message || '',
                        earlier: data.trip ? data.trip.moveEarlier : null,
                    });
                })
                .catch((err) => done({ ok: 0, message: String(err), earlier: null }));
        JS);
        self::assertIsArray($created);
        self::assertSame(201, $created['ok'], (string) ($created['message'] ?? ''));
        self::assertTrue($created['earlier']);

        self::clickLink('Trips');
        self::waitForText('Previous month');
        self::openTripMonth('2026-11');
        self::waitForText('Guest house');
        $night = self::driver()->executeScript(<<<'JS'
            const row = (name) => [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(name));
            const day = document.querySelector('button[data-date="2026-11-22"]');
            const guest = row('Guest house');
            const lost = row('Lost Dutchman');
            const cattail = row('Cattail Cove');
            const names = [...document.querySelectorAll('.stay-list strong')].map((node) => node.textContent.trim());
            const miles = (node) => {
                const chip = node && node.querySelector('[data-chip="miles"]');
                const match = chip && chip.textContent ? chip.textContent.match(/(\d+)\s+miles/) : null;
                return match ? match[1] : '';
            };
            return {
                arrive: day ? day.getAttribute('data-stay-arrive') : '',
                depart: day ? day.getAttribute('data-stay-depart') : '',
                guest: guest ? guest.getAttribute('data-stay-color') : '',
                lost: lost ? lost.getAttribute('data-stay-color') : '',
                guestEarlier: Boolean(guest && guest.querySelector('[aria-label="Earlier on the route"]')),
                guestLater: Boolean(guest && guest.querySelector('[aria-label="Later on the route"]')),
                lostLater: Boolean(lost && lost.querySelector('[aria-label="Later on the route"]')),
                lostEarlier: Boolean(lost && lost.querySelector('[aria-label="Earlier on the route"]')),
                cattailEarlier: Boolean(cattail && cattail.querySelector('[aria-label="Earlier on the route"]')),
                cattailLater: Boolean(cattail && cattail.querySelector('[aria-label="Later on the route"]')),
                guestAfterLost: names.indexOf('Guest house') > names.indexOf('Lost Dutchman'),
                miles: miles(guest),
            };
        JS);
        self::assertIsArray($night);
        self::assertNotSame('', $night['guest']);
        self::assertSame($night['guest'], $night['arrive']);
        self::assertSame($night['lost'], $night['depart']);
        self::assertNotSame($night['arrive'], $night['depart']);
        self::assertTrue($night['guestEarlier']);
        self::assertFalse($night['guestLater']);
        self::assertTrue($night['lostLater']);
        self::assertFalse($night['lostEarlier']);
        self::assertFalse($night['cattailEarlier']);
        self::assertFalse($night['cattailLater']);
        self::assertTrue($night['guestAfterLost']);

        self::clickStayAction('Guest house', 'Earlier on the route');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const names = [...document.querySelectorAll('.stay-list strong')].map((node) => node.textContent.trim());
                const guest = names.indexOf('Guest house');
                const lost = names.indexOf('Lost Dutchman');
                return guest >= 0 && lost >= 0 && guest < lost;
            JS);

            return $ready === true;
        });
        $moved = self::driver()->executeScript(<<<'JS'
            const row = (name) => [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(name));
            const miles = (node) => {
                const chip = node && node.querySelector('[data-chip="miles"]');
                const match = chip && chip.textContent ? chip.textContent.match(/(\d+)\s+miles/) : null;
                return match ? match[1] : '';
            };
            return { guest: miles(row('Guest house')), lost: miles(row('Lost Dutchman')) };
        JS);
        self::assertIsArray($moved);
        if ($night['miles'] !== '') {
            self::assertNotSame($night['miles'], $moved['guest']);
            self::assertNotSame('', $moved['guest']);
        }
    }

    private static function choose(string $name, string $label): void
    {
        $visible = self::wait()->until(function () use ($name, $label): string|false {
            $ready = self::driver()->executeScript(<<<'JS'
                const select = document.querySelector(arguments[0]);
                if (!select) return '';
                const label = arguments[1];
                const option = [...select.options].find((item) => {
                    const text = item.textContent.trim();
                    return text === label || text.startsWith(label + ' (') || text.startsWith(label + ' -');
                });
                return option ? option.textContent.trim() : '';
            JS, ['select[name="' . $name . '"]', $label]);

            return is_string($ready) && $ready !== '' ? $ready : false;
        });
        self::assertIsString($visible);
        $element = self::driver()->findElement(WebDriverBy::name($name));
        (new WebDriverSelect($element))->selectByVisibleText($visible);
    }

    private static function assertCollapsedSidebar(): void
    {
        self::clickCss('button[aria-label="Collapse sidebar"]');
        $mini = self::driver()->executeScript(<<<'JS'
            const nav = document.querySelector('nav[aria-label="Primary"]');
            const shown = (node) => (node ? getComputedStyle(node).display : '');
            const planner = [...nav.querySelectorAll('a')].find((node) => node.textContent.trim() === 'Planner');
            const name = nav.querySelector('.nav-name');
            const letter = name ? getComputedStyle(name, '::first-letter').fontSize : '';
            const group = nav.querySelector('.nav-group');
            const shell = document.querySelector('.shell');
            const columns = shell ? getComputedStyle(shell).gridTemplateColumns.split(' ') : [];
            return {
                collapsed: nav.classList.contains('collapsed'),
                mark: nav.querySelector('.brand-mark') ? nav.querySelector('.brand-mark').textContent : '',
                word: shown(nav.querySelector('.brand-word')),
                log: shown(nav.querySelector('.nav-log .nav-label')),
                planner: shown(planner ? planner.querySelector('.nav-label') : null),
                group: shown(group),
                reset: shown(nav.querySelector('.nav-reset')),
                nameSize: name ? getComputedStyle(name).fontSize : '',
                letter,
                theme: document.querySelector('.theme-toggle') ? document.querySelector('.theme-toggle').getAttribute('aria-label') : '',
                period: nav.querySelector('.period-dates') ? nav.querySelector('.period-dates').textContent.trim() : '',
                periodSize: nav.querySelector('.period-dates') ? Number.parseFloat(getComputedStyle(nav.querySelector('.period-dates')).fontSize) : 0,
                periodStack: (() => {
                    const dates = nav.querySelector('.period-dates');
                    const step = dates ? dates.parentElement : null;
                    if (!step) return null;
                    const buttons = [...step.querySelectorAll('button')];
                    if (buttons.length !== 2) return null;
                    const line = dates.getBoundingClientRect();
                    const previous = buttons[0].getBoundingClientRect();
                    const next = buttons[1].getBoundingClientRect();
                    const sample = dates.textContent;
                    dates.textContent = '10/16-11/15';
                    const wide = dates.getBoundingClientRect().width;
                    dates.textContent = sample;
                    return {
                        datesAbove: line.bottom <= previous.top + 1 && line.bottom <= next.top + 1,
                        stacked: previous.bottom <= next.top + 1,
                        previousWide: previous.width >= 30,
                        fits: wide <= nav.getBoundingClientRect().width + 1,
                    };
                })(),
                moon: (() => {
                    const button = nav.querySelector('.theme-toggle');
                    const path = button ? button.querySelector('path') : null;
                    if (!button || !path) return null;
                    const box = button.getBoundingClientRect();
                    const ink = path.getBoundingClientRect();
                    return {
                        x: Math.abs((ink.left + ink.width / 2) - (box.left + box.width / 2)),
                        y: Math.abs((ink.top + ink.height / 2) - (box.top + box.height / 2)),
                    };
                })(),
                logout: Boolean(nav.querySelector('.nav-logout svg')),
                rail: columns.length ? Number.parseFloat(columns[0]) : 0,
            };
        JS);
        self::assertIsArray($mini);
        self::assertTrue($mini['collapsed']);
        self::assertSame('N', $mini['mark']);
        self::assertSame('none', $mini['word']);
        self::assertSame('none', $mini['log']);
        self::assertSame('none', $mini['planner']);
        self::assertSame('none', $mini['group']);
        self::assertSame('none', $mini['reset']);
        self::assertSame('0px', $mini['nameSize']);
        self::assertGreaterThan(0, (float) $mini['letter']);
        self::assertSame('Night', $mini['theme']);
        self::assertMatchesRegularExpression('/^\d{1,2}\/\d{1,2}-\d{1,2}\/\d{1,2}$/', (string) $mini['period']);
        self::assertLessThanOrEqual(9, (float) $mini['periodSize']);
        self::assertIsArray($mini['periodStack']);
        self::assertTrue($mini['periodStack']['datesAbove']);
        self::assertTrue($mini['periodStack']['stacked']);
        self::assertTrue($mini['periodStack']['previousWide']);
        self::assertTrue($mini['periodStack']['fits']);
        self::assertIsArray($mini['moon']);
        self::assertLessThan(1.5, (float) $mini['moon']['x']);
        self::assertLessThan(1.5, (float) $mini['moon']['y']);
        self::assertTrue($mini['logout']);
        self::assertLessThan(90, $mini['rail']);

        self::driver()->executeScript(<<<'JS'
            window.__tipAt = 0;
            const orig = window.setTimeout;
            window.setTimeout = function (fn, ms, ...rest) {
                if (ms === 100) window.__tipAt = performance.now();
                return orig.call(window, fn, ms, ...rest);
            };
        JS);
        $planner = self::driver()->findElement(WebDriverBy::cssSelector('nav[aria-label="Primary"] a[href="/"]'));
        self::driver()->action()->moveToElement($planner)->perform();
        $elapsed = self::wait()->until(function (): float|false {
            $tip = self::driver()->executeScript(<<<'JS'
                const tip = document.querySelector('.nav-tip');
                if (!tip || tip.textContent.trim() !== 'Planner' || !window.__tipAt) return null;
                return performance.now() - window.__tipAt;
            JS);

            return is_numeric($tip) ? (float) $tip : false;
        });
        self::assertGreaterThanOrEqual(90, $elapsed);

        self::clickCss('.theme-toggle');
        self::wait()->until(function (): bool {
            $light = self::driver()->executeScript(<<<'JS'
                return document.documentElement.classList.contains('light')
                    && document.querySelector('.theme-toggle')?.getAttribute('aria-label') === 'Day';
            JS);

            return $light === true;
        });
        self::clickCss('.theme-toggle');
        self::wait()->until(function (): bool {
            $night = self::driver()->executeScript(<<<'JS'
                return !document.documentElement.classList.contains('light')
                    && document.querySelector('.theme-toggle')?.getAttribute('aria-label') === 'Night';
            JS);

            return $night === true;
        });

        self::clickCss('button[aria-label="Expand sidebar"]');
        self::wait()->until(function (): bool {
            $open = self::driver()->executeScript(<<<'JS'
                const nav = document.querySelector('nav[aria-label="Primary"]');
                const label = nav.querySelector('a .nav-label');
                return !nav.classList.contains('collapsed') && label && getComputedStyle(label).display !== 'none';
            JS);

            return $open === true;
        });
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
                const event = new Event('input', { bubbles: true });
                input.dispatchEvent(event);
                input.dispatchEvent(new Event('change', { bubbles: true }));
                for (const symbol of Object.getOwnPropertySymbols(input)) {
                    const map = input[symbol];
                    if (map && typeof map.input === 'function') {
                        map.input.call(input, { currentTarget: input, target: input });
                    }
                }
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
        self::assertSame('-$140.00 Due Nov 22', $chip['text']);
        self::assertTrue($chip['moneyFollows']);
        self::assertStringNotContainsString('2026', $chip['text']);
        self::assertTrue($chip['icon']);
        self::assertStringContainsString('M12 5v13.5', $chip['path']);
        self::assertSame('rgb(51,57,65)', $chip['color']);
        self::assertSame('rgb(244,255,252)', $chip['ink']);
        self::assertSame('rgb(142,200,255)', $chip['iconInk']);
        self::assertSame(0, $chip['stray']);
        $card = self::stayCardFacts();
        self::assertStringContainsString('Nov 20, 2026', $card['text']);
        self::assertStringContainsString('Lost Dutchman', $card['text']);

        return $chip['iconInk'];
    }

    private static function assertFuelChipOnStayTwo(string $balanceIcon): void
    {
        $chip = self::chipOnCard('.fuel-amount-chip');
        self::assertMatchesRegularExpression('/^Fuel -\$\d[\d,]*\.\d{2}$/', $chip['text']);
        self::assertTrue($chip['moneyFollows']);
        self::assertTrue($chip['icon']);
        self::assertStringContainsString('M12 19.5c2.6', $chip['path']);
        self::assertSame('rgb(51,57,65)', $chip['color']);
        self::assertSame('rgb(244,255,252)', $chip['ink']);
        self::assertSame('rgb(245,185,66)', $chip['iconInk']);
        self::assertSame(0, $chip['stray']);
        $balance = self::chipOnCard('.balance-due-chip');
        self::assertSame('-$75.00 Due Dec 1', $balance['text']);
        self::assertTrue($balance['moneyFollows']);
        self::assertStringNotContainsString('2026', $balance['text']);
        self::assertStringNotContainsString('$100.00', $balance['text']);
        self::assertSame('rgb(51,57,65)', $balance['color']);
        self::assertSame($balanceIcon, $balance['iconInk']);
        $mpg = self::chipOnCard('.mpg-chip');
        self::assertMatchesRegularExpression('/^\d+\.\d mpg$/', $mpg['text']);
        self::assertTrue($mpg['icon']);
        self::assertStringContainsString('M6 16.5V9.5', $mpg['path']);
        self::assertSame('rgb(191,163,255)', $mpg['iconInk']);
        self::assertSame(0, $mpg['stray']);
        $price = self::chipOnCard('.fuel-price-chip');
        self::assertSame('$4.00/gal', $price['text']);
        self::assertTrue($price['moneyFollows']);
        self::assertTrue($price['icon']);
        self::assertSame('rect', $price['drawn']);
        self::assertSame('rgb(255,139,152)', $price['iconInk']);
        self::assertSame(0, $price['stray']);
        $miles = self::chipOnCard('.miles-chip');
        self::assertMatchesRegularExpression('/^\d+ miles$/', $miles['text']);
        self::assertStringNotContainsString('from previous stay', $miles['text']);
        self::assertTrue($miles['icon']);
        self::assertStringContainsString('M4 14.5', $miles['path']);
        self::assertSame('rgb(132,219,158)', $miles['iconInk']);
        self::assertSame(0, $miles['stray']);
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
                const money = chip.querySelector('.money');
                const moneyStyle = money ? getComputedStyle(money) : null;
                const chipStyle = getComputedStyle(chip);
                return {
                    text: chip.innerText.replace(/\s+/g, ' ').trim(),
                    color: chipStyle.backgroundColor.replace(/\s+/g, ''),
                    ink: chipStyle.color.replace(/\s+/g, ''),
                    iconInk: icon ? getComputedStyle(icon).color.replace(/\s+/g, '') : '',
                    icon: Boolean(icon && drawn && box && box.width >= 8 && box.height >= 8),
                    path: path ? path.getAttribute('d') || '' : '',
                    drawn: drawn ? drawn.tagName.toLowerCase() : '',
                    stray: document.querySelectorAll('button ' + arguments[0]).length,
                    moneyFollows: Boolean(moneyStyle)
                        && moneyStyle.color === chipStyle.color
                        && moneyStyle.fontFamily === chipStyle.fontFamily
                        && moneyStyle.fontSize === chipStyle.fontSize,
                };
            JS,
            [$selector],
        );
        self::assertIsArray($chip);

        return $chip;
    }

    /** @return array{total:string,deposit:string,due:string,totalTone:string,depositTone:string,dueTone:string} */
    private static function lodgingFigures(): array
    {
        $row = self::driver()->executeScript(<<<'JS'
            const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === 'Lodging');
            const line = heading && heading.parentElement ? heading.parentElement.querySelector('p') : null;
            if (!line) return null;
            const probe = document.createElement('span');
            document.body.appendChild(probe);
            const token = (paint) => {
                const named = ['--teal', '--coral', '--muted'].find((name) => {
                    probe.style.color = `var(${name})`;
                    return getComputedStyle(probe).color === paint;
                });
                return named || paint;
            };
            const read = (index) => {
                const money = line.querySelectorAll('.money')[index];
                if (!money) return { text: '', tone: '' };
                return { text: money.textContent.trim(), tone: token(getComputedStyle(money).color) };
            };
            const total = read(0);
            const deposit = read(1);
            const due = read(2);
            probe.remove();
            return {
                total: total.text,
                deposit: deposit.text,
                due: due.text,
                totalTone: total.tone,
                depositTone: deposit.tone,
                dueTone: due.tone,
            };
        JS);
        self::assertIsArray($row);

        return [
            'total' => (string) $row['total'],
            'deposit' => (string) $row['deposit'],
            'due' => (string) $row['due'],
            'totalTone' => (string) $row['totalTone'],
            'depositTone' => (string) $row['depositTone'],
            'dueTone' => (string) $row['dueTone'],
        ];
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

        self::replace('ratingComment', 'Comment 2');
        self::wait()->until(function (): bool {
            $saved = self::driver()->executeScript(<<<'JS'
                const input = document.querySelector('input[name="ratingComment"]');
                return Boolean(input)
                    && input.value === 'Comment 2'
                    && input.getAttribute('data-saved') === 'Comment 2';
            JS);

            return $saved === true;
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
        self::clickCss('button[data-date="2026-11-22"]');
        self::waitForText('Lost Dutchman');
        $paint = self::stayPaint('2026-11-20', '2026-11-22', '2026-11-24', '2026-11-19', 'Lost Dutchman');
        self::assertSame('tide', $paint['cardColor']);
        self::assertSame('rgb(130,216,237)', $paint['card']);
        self::assertSame('rgb(130,216,237)', $paint['row']);
        self::assertStringContainsString('Lost Dutchman', $paint['cardText']);
        self::assertStringContainsString('Nov 20, 2026', $paint['cardText']);
        self::assertStringContainsString('Nov 24, 2026', $paint['cardText']);
        self::assertStringContainsString('-$140.00 Due Nov 22', $paint['cardText']);
        $lodging = self::lodgingFigures();
        self::assertSame('-$140.00', $lodging['total']);
        self::assertSame('$0.00', $lodging['deposit']);
        self::assertSame('-$140.00', $lodging['due']);
        self::assertSame('--coral', $lodging['totalTone']);
        self::assertSame('--muted', $lodging['depositTone']);
        self::assertSame('--coral', $lodging['dueTone']);
        self::assertStringNotContainsString('Nov 22, 2026', $paint['cardText']);
        self::assertFalse($paint['cardHasTitle']);
        self::assertSame('tide', $paint['arriveIn']);
        self::assertSame('apricot', $paint['arriveOut']);
        self::assertFalse($paint['arriveHalf']);
        self::assertGradient($paint['arriveImage'], 'rgb(255,198,133)', 'rgb(130,216,237)');
        self::assertSame('tide', $paint['middleIn']);
        self::assertSame('tide', $paint['middleOut']);
        self::assertTrue($paint['middleCovered']);
        self::assertGradient($paint['middleImage'], 'rgb(130,216,237)', 'rgb(130,216,237)');
        self::assertSame('blossom', $paint['departIn']);
        self::assertSame('tide', $paint['departOut']);
        self::assertFalse($paint['departHalf']);
        self::assertGradient($paint['departImage'], 'rgb(130,216,237)', 'rgb(255,153,177)');
        self::assertStringContainsString('stay-covered', $paint['emptyClass']);

        self::openTripMonth('2026-12');
        $next = self::stayPaint('2026-12-01', '2026-12-03', '2026-12-05', '2026-12-06', 'Cottonwood');
        self::assertSame('fern', $next['rowColor']);
        self::assertSame('rgb(132,219,158)', $next['row']);
        self::assertNotSame($paint['row'], $next['row']);
        self::assertSame('fern', $next['arriveIn']);
        self::assertSame('blossom', $next['arriveOut']);
        self::assertFalse($next['arriveHalf']);
        self::assertGradient($next['arriveImage'], 'rgb(255,153,177)', 'rgb(132,219,158)');
        self::assertSame('lilac', $next['departIn']);
        self::assertSame('fern', $next['departOut']);
        self::assertFalse($next['departHalf']);
        self::assertGradient($next['departImage'], 'rgb(132,219,158)', 'rgb(227,150,250)');
        self::assertStringContainsString('stay-covered', $next['emptyClass']);

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
        $row = self::stayListFacts('Lost Dutchman');
        self::assertTrue(
            $row['locate'] && $row['pencil'] && $row['crosshair'] && $row['right'] && $row['inside'] && $row['plain'] && $row['compact'] && $row['card'],
            json_encode($row),
        );
        self::assertPhoneStayListFits();

        self::clickCss('.stay-card-hit');
        self::waitForPin('Lost Dutchman', '6109 N Apache Trail, Apache Junction, AZ 85119');
        self::clickLink('Cancel');
        self::waitForText('Usery Mountain');

        self::wait()->until(function (): bool {
            $view = self::mapView();

            return $view !== null
                && $view['zoom'] > 2
                && $view['zoom'] < 8
                && abs($view['longitude'] + 113.40) < 0.45
                && abs($view['latitude'] - 33.73) < 0.45
                && str_contains($view['pin'], 'Usery Mountain');
        });
        $wide = self::mapView();
        self::assertNotNull($wide);
        self::click('Next');
        self::waitForText('McDowell Mountain');
        self::wait()->until(function (): bool {
            $view = self::mapView();

            return $view !== null
                && $view['zoom'] > 8.7
                && $view['zoom'] < 9.7
                && abs($view['longitude'] + 111.707) < 0.8
                && abs($view['latitude'] - 33.689) < 0.8
                && str_contains($view['pin'], 'McDowell Mountain');
        });
        self::click('Previous');
        self::waitForText('Usery Mountain');
        self::wait()->until(function (): bool {
            $view = self::mapView();

            return $view !== null
                && $view['zoom'] > 8.7
                && $view['zoom'] < 9.7
                && abs($view['longitude'] + 111.6135) < 0.8
                && abs($view['latitude'] - 33.4805) < 0.8
                && str_contains($view['pin'], 'Usery Mountain');
        });
        self::click('Next month');
        self::waitForText('Nov 2026');
        self::clickStayAction('Usery Mountain', 'Locate stay');
        self::wait()->until(function (): bool {
            $view = self::mapView();

            return $view !== null
                && self::closeZoom($view['zoom'])
                && abs($view['longitude'] + 111.6135) < 0.8
                && abs($view['latitude'] - 33.4805) < 0.8
                && str_contains($view['pin'], 'Usery Mountain');
        });
        $close = self::mapView();
        self::assertNotNull($close);
        self::assertGreaterThan($wide['zoom'], $close['zoom']);
        self::assertTrue(self::closeZoom($close['zoom']));

        self::click('Next month');
        self::wait()->until(function (): bool {
            return self::driver()->findElements(
                WebDriverBy::xpath('//div[contains(@class,"stay-list")]//strong[normalize-space()="Cottonwood"]'),
            ) !== [];
        });
        $held = self::mapView();
        self::assertNotNull($held);
        $name = self::driver()->findElement(WebDriverBy::xpath('//div[contains(@class,"stay-list")]//strong[normalize-space()="Cottonwood"]'));
        self::driver()->executeScript('arguments[0].scrollIntoView({block:"center", inline:"nearest"})', [$name]);
        $name->click();
        usleep(700000);
        $still = self::mapView();
        self::assertNotNull($still);
        self::assertEqualsWithDelta($held['longitude'], $still['longitude'], 0.15);
        self::assertEqualsWithDelta($held['latitude'], $still['latitude'], 0.15);
        self::assertEqualsWithDelta($held['zoom'], $still['zoom'], 0.2);
        self::assertStringContainsString('Usery Mountain', $still['pin']);
        $card = self::driver()->findElement(WebDriverBy::cssSelector('.stay-card'))->getText();
        self::assertStringContainsString('Usery Mountain', $card);
        self::assertStringNotContainsString('Cottonwood', $card);

        $later = self::stayListFacts('Cottonwood');
        self::assertTrue($later['locate'] && $later['pencil'] && $later['right'] && $later['plain'], json_encode($later));
        self::clickStayAction('Cottonwood', 'Locate stay');
        self::wait()->until(function (): bool {
            $view = self::mapView();
            if ($view === null || !self::closeZoom($view['zoom'])) {
                return false;
            }
            $text = self::driver()->findElement(WebDriverBy::cssSelector('.stay-card'))->getText();

            return abs($view['longitude'] + 115.81) < 0.8
                && abs($view['latitude'] - 33.741) < 0.8
                && str_contains($view['pin'], 'Cottonwood')
                && str_contains($view['pin'], 'Joshua Tree, CA')
                && str_contains($text, 'Cottonwood');
        });
        $moved = self::mapView();
        self::assertNotNull($moved);
        self::assertTrue(self::closeZoom($moved['zoom']));
        self::clickStayAction('Cottonwood', 'Locate stay');
        usleep(700000);
        $again = self::mapView();
        self::assertNotNull($again);
        self::assertTrue(self::closeZoom($again['zoom']));
        self::assertEqualsWithDelta($moved['longitude'], $again['longitude'], 0.15);
        self::assertEqualsWithDelta($moved['latitude'], $again['latitude'], 0.15);
        self::assertStringContainsString('Cottonwood', $again['pin']);

        self::clickStayAction('Cottonwood', 'Edit stay');
        self::waitForPin('Cottonwood', 'Cottonwood Campground, Joshua Tree, CA 92277');
        self::clickLink('Cancel');
        self::waitForText('Usery Mountain');
    }

    private static function closeZoom(float $zoom): bool
    {
        return $zoom >= 14.5 && $zoom < 15.5;
    }

    private static function waitForPin(string $name, string $address): void
    {
        self::wait()->until(function () use ($name, $address): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const pins = [...document.querySelectorAll('.globe .map-pin')];
                return pins.some((pin) => {
                    const label = pin.getAttribute('aria-label') || '';
                    return label.includes(arguments[0]) && label.includes(arguments[1]);
                });
            JS, [$name, $address]);

            return $ready === true;
        });
    }

    private static function clickStayAction(string $name, string $label): void
    {
        self::driver()->executeScript(<<<'JS'
            const row = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes(arguments[0]));
            row?.querySelector(`[aria-label="${arguments[1]}"]`)?.click();
        JS, [$name, $label]);
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
                const row = [...document.querySelectorAll('.stay-list')].find((node) => node.textContent.includes('Lost Dutchman'));
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
