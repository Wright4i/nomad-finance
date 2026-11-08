<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\JsonWireCompat;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlannerWidgetsTest extends TestCase
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

    public function testThePlannerShowsTheSixWidgetsAndKeepsAMove(): void
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
        self::waitForText('Camp host');
        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));

        $seen = self::driver()->executeScript(<<<'JS'
            const text = (selector) => {
                const node = document.querySelector(selector);
                return node ? node.innerText.replace(/\s+/g, ' ').trim() : '';
            };
            const box = (selector) => {
                const node = document.querySelector(selector);
                if (!node) return null;
                const rect = node.getBoundingClientRect();
                return { top: rect.top, left: rect.left, width: rect.width, height: rect.height };
            };
            const money = document.querySelector('[data-account-type="credit"] .money');
            const checking = document.querySelector('[data-account-type="checking"] .money');
            const otherDebt = document.querySelector('[data-account-type="other"][data-side="liability"] .money');
            const pie = document.querySelector('svg.bill-pie');
            const pieBox = pie ? pie.getBoundingClientRect() : null;
            const figure = document.querySelector('.planner-net .figure');
            const now = new Date();
            const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const titleOf = (id) => {
                const node = document.querySelector(`[data-section="${id}"] a.planner-widget-title`);
                if (!node) return null;
                const style = getComputedStyle(node);
                return {
                    text: (node.textContent || '').replace(/\s+/g, ' ').trim(),
                    href: node.getAttribute('href'),
                    weight: style.fontWeight,
                    size: style.fontSize,
                    transform: style.textTransform,
                    color: style.color,
                    spacing: style.letterSpacing,
                };
            };
            const charts = document.querySelector('.planner-board .bill-charts');
            const pieCell = document.querySelector('.bill-pie-cell');
            const legend = document.querySelector('.bill-legend');
            const gaugeTops = [...document.querySelectorAll('.planner-gauges .want-gauge')].map((node) => node.getBoundingClientRect().top);
            return {
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
                widgets: [...document.querySelectorAll('[data-widget]')].map((node) => node.dataset.widget),
                income: text('[data-section="income"]'),
                titles: {
                    income: titleOf('income'),
                    calendar: titleOf('calendar'),
                    trips: titleOf('trips'),
                    accounts: titleOf('accounts'),
                    envelopes: titleOf('envelopes'),
                    bills: titleOf('bills'),
                },
                netKicker: text('.planner-net .kicker'),
                interestLost: text('.planner-interest-lost .planner-interest-amount'),
                interestGained: text('.planner-interest-gained .planner-interest-amount'),
                netCopy: text('.planner-net'),
                paydayOn: document.querySelector('[data-payday]')?.dataset.payday ?? '',
                today: document.querySelector('.planner-day')?.dataset.today ?? '',
                totals: [...document.querySelectorAll('.account-total')].map((node) => {
                    const rect = node.getBoundingClientRect();
                    const label = node.querySelector('.kicker');
                    const amount = node.querySelector('.money');
                    const inside = (item) => {
                        if (!item) return false;
                        const box = item.getBoundingClientRect();
                        return box.top >= rect.top - 1
                            && box.bottom <= rect.bottom + 1
                            && item.scrollHeight <= item.clientHeight + 2;
                    };
                    return {
                        type: node.dataset.accountType,
                        side: node.dataset.side,
                        money: amount?.className ?? '',
                        top: rect.top,
                        height: rect.height,
                        clipped: !inside(label) || !inside(amount),
                    };
                }),
                tripMap: document.querySelector('[data-section="trips"] .globe') !== null,
                billColumns: charts ? getComputedStyle(charts).gridTemplateColumns.split(' ').filter(Boolean).length : 0,
                billStacked: pieCell && legend ? legend.getBoundingClientRect().top >= pieCell.getBoundingClientRect().bottom - 2 : false,
                gaugesShareRow: gaugeTops.length === 3 && Math.abs(gaugeTops[0] - gaugeTops[1]) < 8 && Math.abs(gaugeTops[1] - gaugeTops[2]) < 8,
                day: text('[data-section="calendar"] strong'),
                calendar: text('[data-section="calendar"]'),
                dayShare: (() => {
                    const day = box('.planner-day');
                    const widget = box('[data-section="calendar"]');
                    return day && widget && widget.height > 0 ? day.height / widget.height : 0;
                })(),
                assign: [...document.querySelectorAll('[data-section="envelopes"] a, [data-section="envelopes"] button')].some((node) => (node.textContent || '').trim() === 'Assign'),
                expectedDay: String(now.getDate()),
                localToday: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`,
                trips: text('[data-section="trips"]'),
                envelopes: text('[data-section="envelopes"]'),
                accounts: text('[data-section="accounts"]'),
                bills: text('[data-section="bills"]'),
                pies: document.querySelectorAll('svg.bill-pie').length,
                pieWidth: pieBox ? pieBox.width : 0,
                credit: money ? getComputedStyle(money).color : '',
                checking: checking ? getComputedStyle(checking).color : '',
                creditClass: money ? money.className : '',
                otherClass: otherDebt ? otherDebt.className : '',
                figureChildren: figure ? figure.childElementCount : -1,
                net: box('.planner-net'),
                incomeBox: box('[data-section="income"]'),
                calendarBox: box('[data-section="calendar"]'),
                tripBox: box('[data-section="trips"]'),
                accountBox: box('[data-section="accounts"]'),
            };
        JS);
        self::assertIsArray($seen);
        self::assertSame(
            ['income', 'calendar', 'trips', 'accounts', 'envelopes', 'bills'],
            $seen['widgets'],
        );
        self::assertSame('income,calendar,trips,accounts,envelopes,bills', $seen['order']);
        self::assertStringContainsString('Camp host', $seen['income']);
        self::assertStringContainsString('Nov 8, 2026', $seen['income']);
        self::assertStringContainsString('$192.00', $seen['income']);
        self::assertStringContainsString('3 payors', $seen['income']);
        self::assertStringContainsString('Still coming in', $seen['income']);
        self::assertStringContainsString('$768.00', $seen['income']);
        self::assertStringContainsString('Nov 1', (string) $seen['bills']);
        self::assertStringNotContainsString('Dec 2026', (string) $seen['bills']);
        self::assertGreaterThanOrEqual($seen['today'], $seen['paydayOn']);
        self::assertSame($seen['localToday'], $seen['today']);
        self::assertSame($seen['expectedDay'], $seen['day']);
        $billsTitle = $seen['titles']['bills'];
        self::assertIsArray($billsTitle);
        foreach ([
            'income' => ['Income', '/pay-cycles'],
            'calendar' => ['Calendar', '/calendar'],
            'trips' => ['Trips', '/trips'],
            'accounts' => ['Accounts', '/accounts'],
            'envelopes' => ['Budget', '/budget'],
            'bills' => ['Bills', '/bills'],
        ] as $id => [$label, $href]) {
            $title = $seen['titles'][$id];
            self::assertIsArray($title, $id);
            self::assertSame($label, $title['text']);
            self::assertSame($href, $title['href']);
            self::assertSame($billsTitle['weight'], $title['weight']);
            self::assertSame($billsTitle['size'], $title['size']);
            self::assertSame($billsTitle['transform'], $title['transform']);
            self::assertSame($billsTitle['color'], $title['color']);
            self::assertSame($billsTitle['spacing'], $title['spacing']);
            self::assertNotSame('uppercase', $title['transform']);
            self::assertSame('400', (string) $title['weight']);
        }
        self::assertSame('NET WORTH', $seen['netKicker']);
        self::assertStringContainsString('Calendar', (string) $seen['calendar']);
        self::assertGreaterThan(0.5, $seen['dayShare']);
        self::assertStringContainsString('Trips', (string) $seen['trips']);
        self::assertStringContainsString('Usery Mountain', $seen['trips']);
        self::assertStringNotContainsString('No current stay', $seen['trips']);
        self::assertTrue($seen['tripMap']);
        self::assertStringContainsStringIgnoringCase('Accounts', (string) $seen['accounts']);
        self::assertStringContainsStringIgnoringCase('Budget', (string) $seen['envelopes']);
        self::assertStringContainsStringIgnoringCase('Assigned', $seen['envelopes']);
        self::assertStringContainsStringIgnoringCase('Max', $seen['envelopes']);
        self::assertFalse($seen['assign']);
        self::assertStringContainsString('50%', $seen['envelopes']);
        self::assertStringContainsString('30%', $seen['envelopes']);
        self::assertStringContainsString('20%', $seen['envelopes']);
        self::assertStringContainsString('Needs', $seen['envelopes']);
        self::assertStringContainsString('Available to assign', $seen['envelopes']);
        $accounts = strtolower((string) $seen['accounts']);
        self::assertStringContainsString('checking', $accounts);
        self::assertStringContainsString('$3,180.00', $accounts);
        self::assertStringContainsString('savings', $accounts);
        self::assertStringContainsString('$10,050.00', $accounts);
        self::assertStringContainsString('credit', $accounts);
        self::assertStringContainsString('$860.00', $accounts);
        self::assertStringContainsString('loan', $accounts);
        self::assertStringContainsString('$2,450.00', $accounts);
        self::assertStringContainsString('fuel', $accounts);
        self::assertStringContainsString('$64.00', $accounts);
        self::assertStringContainsString('coral', $seen['creditClass']);
        self::assertStringContainsString('coral', $seen['otherClass']);
        self::assertStringNotContainsString('teal', $seen['otherClass']);
        self::assertNotSame($seen['checking'], $seen['credit']);
        self::assertSame(
            [
                ['type' => 'savings', 'side' => 'asset'],
                ['type' => 'checking', 'side' => 'asset'],
                ['type' => 'other', 'side' => 'asset'],
                ['type' => 'cash', 'side' => 'asset'],
                ['type' => 'fuel', 'side' => 'liability'],
                ['type' => 'other', 'side' => 'liability'],
                ['type' => 'credit', 'side' => 'liability'],
                ['type' => 'loan', 'side' => 'liability'],
            ],
            array_map(
                static fn (array $row): array => ['type' => $row['type'], 'side' => $row['side']],
                $seen['totals'],
            ),
        );
        $accountRows = [];
        foreach ($seen['totals'] as $total) {
            self::assertIsArray($total);
            self::assertFalse($total['clipped']);
            $top = (float) $total['top'];
            $placed = false;
            foreach ($accountRows as &$accountRow) {
                if (abs($accountRow['top'] - $top) < 2) {
                    $accountRow['heights'][] = (float) $total['height'];
                    $placed = true;
                    break;
                }
            }
            unset($accountRow);
            if (!$placed) {
                $accountRows[] = ['top' => $top, 'heights' => [(float) $total['height']]];
            }
        }
        $accountRowMatched = false;
        foreach ($accountRows as $accountRow) {
            if (count($accountRow['heights']) < 2) {
                continue;
            }
            $accountRowMatched = true;
            self::assertLessThan(2, max($accountRow['heights']) - min($accountRow['heights']));
        }
        self::assertTrue($accountRowMatched);
        self::assertGreaterThan(1, $seen['billColumns']);
        self::assertFalse($seen['billStacked']);
        self::assertTrue($seen['gaugesShareRow']);
        self::assertStringContainsString('rent', $seen['bills']);
        self::assertSame(1, $seen['pies']);
        self::assertGreaterThan(0, $seen['pieWidth']);
        self::assertLessThan(200, $seen['pieWidth']);
        self::assertSame(0, $seen['figureChildren']);
        self::assertMatchesRegularExpression('/^\$[\d,]+\.\d{2}\/mn$/', (string) $seen['interestLost']);
        self::assertMatchesRegularExpression('/^\$[\d,]+\.\d{2}\/mn$/', (string) $seen['interestGained']);
        self::assertStringNotContainsString('per month', (string) $seen['netCopy']);
        self::assertStringNotContainsString('USD', $seen['income'] . $seen['accounts'] . $seen['envelopes'] . $seen['bills']);
        self::assertGreaterThan($seen['net']['left'] + $seen['net']['width'] - 8, $seen['incomeBox']['left']);
        self::assertEqualsWithDelta($seen['calendarBox']['top'], $seen['tripBox']['top'], 4);
        self::assertEqualsWithDelta($seen['calendarBox']['top'], $seen['accountBox']['top'], 4);
        self::assertGreaterThan($seen['calendarBox']['left'], $seen['tripBox']['left']);
        self::assertGreaterThan($seen['tripBox']['left'], $seen['accountBox']['left']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 900));
        $stacked = self::driver()->executeScript(<<<'JS'
            const box = (selector) => document.querySelector(selector).getBoundingClientRect();
            const calendar = box('[data-section="calendar"]');
            const trips = box('[data-section="trips"]');
            const accounts = box('[data-section="accounts"]');
            return { calendar: calendar.top, trips: trips.top, accounts: accounts.top, height: calendar.height };
        JS);
        self::assertIsArray($stacked);
        self::assertGreaterThan($stacked['calendar'] + $stacked['height'] - 2, $stacked['trips']);
        self::assertGreaterThan($stacked['trips'], $stacked['accounts']);

        $handles = self::driver()->executeScript(<<<'JS'
            const grabs = [...document.querySelectorAll('.planner-grab')];
            return {
                count: grabs.length,
                shown: grabs.filter((node) => getComputedStyle(node).display !== 'none').length,
            };
        JS);
        self::assertIsArray($handles);
        self::assertSame(6, $handles['count']);
        self::assertSame(0, $handles['shown']);

        self::driver()->executeScript(
            'document.querySelector(\'[data-widget="income"] .planner-size\')?.scrollIntoView({ block: "center" })',
        );
        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="income"] .planner-size'))->click();
        self::wait()->until(function (): bool {
            $locked = self::driver()->executeScript(<<<'JS'
                const dialog = document.querySelector('dialog.modal[open]');
                const input = dialog ? dialog.querySelector('input[type="range"]') : null;
                const text = dialog ? dialog.innerText : '';
                return Boolean(dialog)
                    && text.includes('Edit Income layout')
                    && text.includes('Move back')
                    && text.includes('Move forward')
                    && input
                    && input.disabled === true;
            JS);

            return $locked === true;
        });
        $locked = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            const input = dialog.querySelector('input[type="range"]');
            const button = (label) => [...dialog.querySelectorAll('button')].find((node) => (node.textContent || '').trim() === label);
            const readout = dialog.querySelector('.span-slider-readout strong');
            return {
                opacity: input ? Number(getComputedStyle(input).opacity) : 1,
                value: input ? input.value : '',
                readout: readout ? readout.textContent.trim() : '',
                span: document.querySelector('[data-widget="income"]')?.dataset.span ?? '',
                back: button('Move back') ? button('Move back').disabled : null,
                forward: button('Move forward') ? button('Move forward').disabled : null,
            };
        JS);
        self::assertIsArray($locked);
        self::assertLessThan(1, (float) $locked['opacity']);
        self::assertSame('3', $locked['value']);
        self::assertSame('3', $locked['readout']);
        self::assertSame('3', $locked['span']);
        self::assertTrue($locked['back'] === true);
        self::assertFalse($locked['forward'] === true);

        self::chooseSpan(6);
        $untouched = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            const readout = dialog ? dialog.querySelector('.span-slider-readout strong') : null;
            return {
                span: document.querySelector('[data-widget="income"]')?.dataset.span ?? '',
                saved: document.querySelector('.planner-board')?.dataset.spans ?? '',
                readout: readout ? readout.textContent.trim() : '',
                value: dialog ? (dialog.querySelector('input[type="range"]')?.value ?? '') : '',
            };
        JS);
        self::assertIsArray($untouched);
        self::assertSame('3', $untouched['span']);
        self::assertSame('3', $untouched['readout']);
        self::assertSame('3', $untouched['value']);
        self::assertIsString($untouched['saved']);
        self::assertStringContainsString('income:3', $untouched['saved']);
        self::assertStringNotContainsString('income:6', $untouched['saved']);

        self::stepOrder('Move forward', 'calendar,income,trips,accounts,envelopes,bills');
        self::stepOrder('Move back', 'income,calendar,trips,accounts,envelopes,bills');
        self::driver()->findElement(WebDriverBy::xpath('//dialog[contains(@class,"modal") and @open]//button[normalize-space()="Close"]'))->click();
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );

        self::driver()->executeScript(
            'document.querySelector(\'[data-widget="bills"] .planner-size\')?.scrollIntoView({ block: "center" })',
        );
        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="bills"] .planner-size'))->click();
        self::waitForText('Edit Bills layout');
        $last = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            const button = (label) => [...dialog.querySelectorAll('button')].find((node) => (node.textContent || '').trim() === label);
            return {
                back: button('Move back') ? button('Move back').disabled : null,
                forward: button('Move forward') ? button('Move forward').disabled : null,
            };
        JS);
        self::assertIsArray($last);
        self::assertFalse($last['back'] === true);
        self::assertTrue($last['forward'] === true);
        self::driver()->findElement(WebDriverBy::xpath('//dialog[contains(@class,"modal") and @open]//button[normalize-space()="Close"]'))->click();
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );

        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        $grabDisplay = self::driver()->executeScript(
            'return getComputedStyle(document.querySelector(\'[data-widget="bills"] .planner-grab\')).display',
        );
        self::assertSame('flex', $grabDisplay);

        $grab = self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="bills"] .planner-grab'));
        $income = self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="income"]'));
        self::dragOnto($grab, $income);
        self::wait()->until(function (): bool {
            $saved = self::driver()->executeScript(
                'return document.querySelector(".planner-board")?.dataset.saved ?? ""',
            );

            return is_string($saved) && str_starts_with($saved, 'bills,');
        });

        self::driver()->navigate()->refresh();
        self::waitForText('Camp host');
        $kept = self::driver()->executeScript(<<<'JS'
            return {
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
                widgets: [...document.querySelectorAll('[data-widget]')].map((node) => node.dataset.widget),
                income: document.querySelector('[data-section="income"]')?.innerText ?? '',
                pie: document.querySelectorAll('svg.bill-pie').length,
            };
        JS);
        self::assertIsArray($kept);
        self::assertSame(
            ['bills', 'income', 'calendar', 'trips', 'accounts', 'envelopes'],
            $kept['widgets'],
        );
        self::assertSame('bills,income,calendar,trips,accounts,envelopes', $kept['order']);
        self::assertStringContainsString('Camp host', $kept['income']);
        self::assertSame(1, $kept['pie']);

        // Income is half beside net worth. Calendar, Trips, and Accounts are single, three across.
        // Budget and Bills each take the row.
        $defaults = self::driver()->executeScript(<<<'JS'
            const span = (id) => document.querySelector(`[data-widget="${id}"]`)?.dataset.span ?? '';
            return {
                income: span('income'),
                calendar: span('calendar'),
                trips: span('trips'),
                accounts: span('accounts'),
                envelopes: span('envelopes'),
                bills: span('bills'),
                wrenches: document.querySelectorAll('.planner-size').length,
                netWrench: document.querySelector('.planner-net .planner-size') !== null,
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
            };
        JS);
        self::assertIsArray($defaults);
        self::assertSame('3', $defaults['income']);
        self::assertSame('2', $defaults['calendar']);
        self::assertSame('2', $defaults['trips']);
        self::assertSame('2', $defaults['accounts']);
        self::assertSame('6', $defaults['envelopes']);
        self::assertSame('6', $defaults['bills']);
        self::assertSame(6, $defaults['wrenches']);
        self::assertFalse($defaults['netWrench']);

        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="income"] .planner-size'))->click();
        self::wait()->until(function (): bool {
            $text = self::driver()->executeScript(
                'return document.querySelector("dialog.modal[open]")?.innerText ?? ""',
            );

            return is_string($text)
                && str_contains($text, 'Edit Income layout')
                && str_contains($text, 'of 6 columns')
                && str_contains($text, 'Move back')
                && str_contains($text, 'Move forward');
        });
        $selected = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            const input = dialog.querySelector('input[type="range"]');
            const button = (label) => [...dialog.querySelectorAll('button')].find((node) => (node.textContent || '').trim() === label);
            return {
                min: input?.min ?? '',
                max: input?.max ?? '',
                step: input?.step ?? '',
                value: input?.value ?? '',
                locked: input ? input.disabled : true,
                back: button('Move back') ? button('Move back').disabled : true,
                forward: button('Move forward') ? button('Move forward').disabled : true,
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
            };
        JS);
        self::assertIsArray($selected);
        self::assertSame('1', $selected['min']);
        self::assertSame('6', $selected['max']);
        self::assertSame('1', $selected['step']);
        self::assertSame('3', $selected['value']);
        self::assertFalse($selected['locked'] === true);
        self::assertFalse($selected['back'] === true);
        self::assertFalse($selected['forward'] === true);
        self::assertSame($defaults['order'], $selected['order']);

        self::stepOrder('Move forward', 'bills,calendar,income,trips,accounts,envelopes');
        self::stepOrder('Move back', 'bills,income,calendar,trips,accounts,envelopes');

        self::chooseSpan(6);
        $full = self::waitForSpan('income', 6);
        self::assertEqualsWithDelta((float) $full['board'], (float) $full['width'], 2.0);

        self::chooseSpan(2);
        $single = self::waitForSpan('income', 2);
        self::assertLessThan((float) $single['board'] * 0.4, (float) $single['width']);

        self::chooseSpan(3);
        $half = self::waitForSpan('income', 3);
        self::assertGreaterThan((float) $half['board'] * 0.4, (float) $half['width']);
        self::assertLessThan((float) $half['board'] * 0.6, (float) $half['width']);

        $inside = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            const rect = dialog.getBoundingClientRect();
            return { x: Math.round(rect.left + rect.width / 2), y: Math.round(rect.top + 28) };
        JS);
        self::assertIsArray($inside);
        self::pointerSweep((int) $inside['x'], (int) $inside['y'], 8, 8);
        self::assertTrue(self::driver()->executeScript('return document.querySelector("dialog.modal[open]") !== null') === true);
        self::pointerClick(8, 8);
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );

        self::driver()->navigate()->refresh();
        self::waitForText('Camp host');
        $restored = self::driver()->executeScript(<<<'JS'
            return {
                span: document.querySelector('[data-widget="income"]')?.dataset.span ?? '',
                column: getComputedStyle(document.querySelector('[data-widget="income"]')).gridColumn,
                order: document.querySelector('.planner-board')?.dataset.order ?? '',
            };
        JS);
        self::assertIsArray($restored);
        self::assertSame('3', $restored['span']);
        self::assertMatchesRegularExpression('/span\s+3(?!\d)/', (string) $restored['column']);
        self::assertSame('bills,income,calendar,trips,accounts,envelopes', $restored['order']);
    }

    #[\PHPUnit\Framework\Attributes\Depends('testThePlannerShowsTheSixWidgetsAndKeepsAMove')]
    public function testCalendarTripMapAndGaugeList(): void
    {
        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::driver()->get(self::$base . '/');
        self::waitForText('Camp host');

        self::driver()->findElement(WebDriverBy::cssSelector('a.planner-day'))->click();
        self::wait()->until(
            fn (): bool => str_contains(self::driver()->getCurrentURL(), '/calendar'),
        );
        self::waitForText('Show Income');
        self::driver()->get(self::$base . '/');
        self::waitForText('Usery Mountain');
        self::assertStringNotContainsString('No current stay', self::driver()->findElement(WebDriverBy::cssSelector('body'))->getText());
        $square = self::driver()->executeScript(<<<'JS'
            const day = document.querySelector('.planner-day');
            const items = day ? day.querySelector('.day-items') : null;
            const names = [...(day ? day.querySelectorAll('.bill-chip-name') : [])].map((node) => node.textContent.trim());
            return {
                align: day ? getComputedStyle(day).alignContent : '',
                overflow: items ? getComputedStyle(items).overflowY : '',
                names,
            };
        JS);
        self::assertIsArray($square);
        self::assertContains('Usery Mountain', $square['names']);
        self::assertSame('auto', $square['overflow']);
        self::assertContains($square['align'], ['start', 'flex-start']);
        $map = null;
        try {
            self::wait()->until(function () use (&$map): bool {
                $read = self::driver()->executeScript(<<<'JS'
                    try {
                        const section = document.querySelector('[data-section="trips"]');
                        const globe = section ? section.querySelector('.globe') : null;
                        const pin = globe ? globe.querySelector('.map-pin') : null;
                        const label = pin ? pin.getAttribute('aria-label') || '' : '';
                        const zoom = globe ? Number(globe.dataset.zoom) : 0;
                        const ok = Boolean(globe)
                            && globe.dataset.projection === 'mercator'
                            && globe.dataset.basemap !== 'satellite'
                            && zoom > 6 && zoom < 12
                            && label.includes('Usery Mountain')
                            && label.includes('Mesa, AZ');
                        return {
                            ok,
                            text: section ? section.innerText.replace(/\s+/g, ' ').trim().slice(0, 240) : '',
                            projection: globe ? globe.dataset.projection || '' : '',
                            basemap: globe ? globe.dataset.basemap || '' : '',
                            zoom: globe ? globe.dataset.zoom || '' : '',
                            mapError: globe ? globe.dataset.mapError || '' : '',
                            label,
                            failed: section ? section.innerText.includes('did not start') : false,
                        };
                    } catch (error) {
                        return { ok: false, mapError: String(error) };
                    }
                JS);
                $map = is_array($read) ? $read : ['ok' => false, 'raw' => $read];

                return ($map['ok'] ?? false) === true;
            });
        } catch (\Facebook\WebDriver\Exception\TimeoutException $e) {
            self::fail('Trip map was not ready: ' . json_encode($map));
        } catch (\Facebook\WebDriver\Exception\WebDriverException $e) {
            self::fail($e::class . ' ' . $e->getMessage() . ' ' . json_encode($e->getResults()));
        }

        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 900));
        $phone = self::driver()->executeScript(<<<'JS'
            const charts = document.querySelector('.planner-board .bill-charts');
            const pie = document.querySelector('.bill-pie-cell');
            const legend = document.querySelector('.bill-legend');
            const gauges = [...document.querySelectorAll('.planner-gauges .want-gauge')].map((node) => node.getBoundingClientRect().top);
            return {
                columns: charts ? getComputedStyle(charts).gridTemplateColumns.split(' ').filter(Boolean).length : 0,
                stacked: pie && legend ? legend.getBoundingClientRect().top >= pie.getBoundingClientRect().bottom - 2 : false,
                gauges: gauges.length === 3 && gauges[1] > gauges[0] + 20 && gauges[2] > gauges[1] + 20,
            };
        JS);
        self::assertIsArray($phone);
        self::assertSame(1, $phone['columns']);
        self::assertTrue($phone['stacked']);
        self::assertTrue($phone['gauges']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="bills"] .planner-size'))->click();
        self::waitForText('Edit Bills layout');
        self::chooseSpan(2);
        self::waitForSpan('bills', 2);
        self::driver()->findElement(WebDriverBy::xpath('//dialog[contains(@class,"modal") and @open]//button[normalize-space()="Close"]'))->click();
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );
        $singleBills = self::driver()->executeScript(<<<'JS'
            const charts = document.querySelector('.planner-board .bill-charts');
            const pie = document.querySelector('.bill-pie-cell');
            const legend = document.querySelector('.bill-legend');
            return {
                columns: charts ? getComputedStyle(charts).gridTemplateColumns.split(' ').filter(Boolean).length : 0,
                stacked: pie && legend ? legend.getBoundingClientRect().top >= pie.getBoundingClientRect().bottom - 2 : false,
                span: document.querySelector('[data-widget="bills"]')?.dataset.span ?? '',
            };
        JS);
        self::assertIsArray($singleBills);
        self::assertSame('2', $singleBills['span']);
        self::assertSame(1, $singleBills['columns']);
        self::assertTrue($singleBills['stacked']);

        self::driver()->executeScript(
            'document.querySelector(\'[data-widget="envelopes"] .planner-size\')?.scrollIntoView({ block: "center" })',
        );
        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="envelopes"] .planner-size'))->click();
        self::waitForText('Edit Budget layout');
        self::chooseSpan(2);
        self::waitForSpan('envelopes', 2);
        self::driver()->findElement(WebDriverBy::xpath('//dialog[contains(@class,"modal") and @open]//button[normalize-space()="Close"]'))->click();
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );
        $singleGauges = self::driver()->executeScript(<<<'JS'
            const gauges = [...document.querySelectorAll('.planner-gauges .want-gauge')].map((node) => node.getBoundingClientRect().top);
            return gauges.length === 3 && gauges[1] > gauges[0] + 20 && gauges[2] > gauges[1] + 20;
        JS);
        self::assertTrue($singleGauges === true);

        self::driver()->executeScript(<<<'JS'
            const button = document.querySelector('[data-widget="envelopes"] button.want-gauge');
            button.scrollIntoView({ block: 'center' });
            button.click();
        JS);
        self::wait()->until(function (): bool {
            $text = self::driver()->executeScript(
                'return document.querySelector("dialog.gauge-modal[open]")?.innerText ?? ""',
            );

            return is_string($text) && str_contains($text, 'Road cushion') && str_contains($text, 'Rent');
        });
        $marks = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.gauge-modal[open]');
            const mark = (name) => {
                const node = [...dialog.querySelectorAll('[data-glyph]')].find((item) => (item.textContent || '').includes(name));
                if (!node) return null;
                return { glyph: node.dataset.glyph || '', tone: node.style.getPropertyValue('--tone') };
            };
            const names = [...dialog.querySelectorAll('[data-glyph]')].map((node) => (node.textContent || '').replace(/\s+/g, ' ').trim());
            return { needs: mark('Road cushion'), rent: mark('Rent'), names };
        JS);
        self::assertIsArray($marks);
        self::assertSame('needs', $marks['needs']['glyph']);
        self::assertSame('rent', $marks['rent']['glyph']);
        self::assertSame('var(--tone-needs)', $marks['needs']['tone']);
        self::assertSame('var(--tone-rent)', $marks['rent']['tone']);
        self::assertNotContains('Fun money', $marks['names']);
        self::assertNotContains('Next rig', $marks['names']);

        $inside = self::driver()->executeScript(<<<'JS'
            const node = document.querySelector('dialog.gauge-modal[open] [data-glyph="rent"]');
            const dialog = node.closest('dialog');
            let rect = node.getBoundingClientRect();
            if (rect.top < 8 || rect.bottom > window.innerHeight - 8) {
                dialog.scrollTop += rect.top - 80;
                rect = node.getBoundingClientRect();
            }
            return {
                x: Math.round(rect.left + Math.min(12, Math.max(1, rect.width / 2))),
                y: Math.round(rect.top + rect.height / 2),
            };
        JS);
        self::assertIsArray($inside);
        self::pointerClick((int) $inside['x'], (int) $inside['y']);
        self::assertTrue(self::driver()->executeScript('return document.querySelector("dialog.gauge-modal[open]") !== null') === true);
        self::pointerClick(8, 8);
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.gauge-modal[open]") === null') === true,
        );
    }

    #[\PHPUnit\Framework\Attributes\Depends('testThePlannerShowsTheSixWidgetsAndKeepsAMove')]
    public function testCalendarDaysFollowThePlannerSpan(): void
    {
        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::driver()->get(self::$base . '/');
        self::waitForText('Camp host');

        $default = self::calendarSquares();
        self::assertSame('2', $default['span']);
        self::assertCalendarDays(1);

        self::sizeCalendar(4);
        self::assertCalendarDays(2);

        self::sizeCalendar(6);
        self::assertCalendarDays(3);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 900));
        $phone = self::calendarSquares();
        self::assertSame('6', $phone['span']);
        self::assertSame(3, $phone['count']);
        self::assertTrue($phone['row'] === true);
        self::assertFalse($phone['overflow'] === true);
        self::assertGreaterThan((float) $phone['calendarBottom'], (float) $phone['tripsTop']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::sizeCalendar(1);
        self::assertCalendarDays(1);

        self::sizeCalendar(2);
        self::assertCalendarDays(1);
    }

    private static function sizeCalendar(int $span): void
    {
        self::driver()->executeScript(
            'document.querySelector(\'[data-widget="calendar"] .planner-size\')?.scrollIntoView({ block: "center" })',
        );
        self::driver()->findElement(WebDriverBy::cssSelector('[data-widget="calendar"] .planner-size'))->click();
        self::waitForText('Edit Calendar layout');
        self::chooseSpan($span);
        self::waitForSpan('calendar', $span);
        self::driver()->findElement(WebDriverBy::xpath('//dialog[contains(@class,"modal") and @open]//button[normalize-space()="Close"]'))->click();
        self::wait()->until(
            fn (): bool => self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true,
        );
    }

    private static function assertCalendarDays(int $count): void
    {
        $seen = self::calendarSquares();
        self::assertSame($count, $seen['count']);
        self::assertSame($seen['expected'], $seen['dates']);
        self::assertSame($seen['numbers'], $seen['days']);
        self::assertSame('/calendar', $seen['href']);
        self::assertFalse($seen['overflow'] === true);
        self::assertTrue($seen['row'] === true);
        $flags = $seen['todayFlags'];
        self::assertIsArray($flags);
        self::assertTrue($flags[0] === true);
        foreach (array_slice($flags, 1) as $later) {
            self::assertFalse($later === true);
        }
        $lefts = $seen['lefts'];
        $widths = $seen['widths'];
        self::assertIsArray($lefts);
        self::assertIsArray($widths);
        self::assertCount($count, $lefts);
        for ($index = 1; $index < $count; $index++) {
            self::assertGreaterThan($lefts[$index - 1], $lefts[$index]);
            self::assertEqualsWithDelta((float) $widths[0], (float) $widths[$index], 2.0);
        }
    }

    /** @return array<string, mixed> */
    private static function calendarSquares(): array
    {
        $seen = self::driver()->executeScript(<<<'JS'
            const widget = document.querySelector('[data-section="calendar"]');
            const trips = document.querySelector('[data-section="trips"]');
            const squares = [...document.querySelectorAll('[data-section="calendar"] .planner-day')];
            const widgetBox = widget ? widget.getBoundingClientRect() : null;
            const add = (iso, days) => {
                const [year, month, day] = iso.split('-').map(Number);
                const date = new Date(year, month - 1, day + days);
                return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            };
            const today = squares.find((node) => node.dataset.today)?.dataset.today
                || squares[0]?.dataset.date
                || '';
            const drawn = squares.map((node) => {
                const rect = node.getBoundingClientRect();
                const date = node.dataset.date || '';
                return {
                    date,
                    day: (node.querySelector('strong')?.innerText || '').replace(/\s+/g, ' ').trim(),
                    number: date.length >= 10 ? String(Number(date.slice(8))) : '',
                    today: node.classList.contains('today'),
                    href: node.getAttribute('href'),
                    left: rect.left,
                    top: rect.top,
                    right: rect.right,
                    width: rect.width,
                };
            });
            const hrefs = [...new Set(drawn.map((square) => square.href))];
            return {
                span: widget?.dataset.span ?? '',
                count: drawn.length,
                dates: drawn.map((square) => square.date),
                expected: today ? Array.from({ length: drawn.length }, (_, offset) => add(today, offset)) : [],
                days: drawn.map((square) => square.day),
                numbers: drawn.map((square) => square.number),
                todayFlags: drawn.map((square) => square.today),
                lefts: drawn.map((square) => square.left),
                widths: drawn.map((square) => square.width),
                href: hrefs.length === 1 ? hrefs[0] : '',
                overflow: !widgetBox || drawn.some((square) => square.left < widgetBox.left - 1 || square.right > widgetBox.right + 1),
                row: drawn.length === 0 || drawn.every((square) => Math.abs(square.top - drawn[0].top) < 4),
                calendarBottom: widgetBox ? widgetBox.bottom : 0,
                tripsTop: trips ? trips.getBoundingClientRect().top : 0,
            };
        JS);
        self::assertIsArray($seen);

        return $seen;
    }

    private static function stepOrder(string $label, string $saved): void
    {
        self::driver()->findElement(WebDriverBy::xpath(
            '//dialog[contains(@class,"modal") and @open]//button[normalize-space()="' . $label . '"]',
        ))->click();
        self::wait()->until(function () use ($saved): bool {
            $read = self::driver()->executeScript(
                'return document.querySelector(".planner-board")?.dataset.saved ?? ""',
            );

            return $read === $saved;
        });
        self::assertTrue(
            self::driver()->executeScript('return document.querySelector("dialog.modal[open] #span-title") !== null') === true,
        );
    }

    private static function chooseSpan(int $span): void
    {
        self::driver()->executeScript(<<<'JS'
            const input = document.querySelector('dialog.modal[open] input[type="range"]');
            const proto = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
            proto.set.call(input, String(arguments[0]));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS, [$span]);
    }

    /** @return array<string, mixed> */
    private static function waitForSpan(string $widget, int $span): array
    {
        $seen = self::wait()->until(function () use ($widget, $span): array|false {
            $read = self::driver()->executeScript(<<<'JS'
                const widget = arguments[0];
                const node = document.querySelector(`[data-widget="${widget}"]`);
                const board = document.querySelector('.planner-board');
                if (!node || !board) return null;
                return {
                    span: node.dataset.span ?? '',
                    column: getComputedStyle(node).gridColumn,
                    width: node.getBoundingClientRect().width,
                    board: board.getBoundingClientRect().width,
                    saved: board.dataset.spans ?? '',
                };
            JS, [$widget]);
            if (!is_array($read)) {
                return false;
            }
            if ((string) $read['span'] !== (string) $span) {
                return false;
            }
            if (!is_string($read['column']) || !preg_match('/span\s+' . $span . '(?!\d)/', $read['column'])) {
                return false;
            }
            if (!is_string($read['saved']) || !str_contains($read['saved'], $widget . ':' . $span)) {
                return false;
            }

            return $read;
        });
        self::assertIsArray($seen);

        return $seen;
    }

    private static function pointerClick(int $x, int $y): void
    {
        self::pointerPath([
            ['type' => 'pointerMove', 'duration' => 0, 'x' => $x, 'y' => $y, 'origin' => 'viewport'],
            ['type' => 'pointerDown', 'button' => 0],
            ['type' => 'pointerUp', 'button' => 0],
        ]);
    }

    private static function pointerSweep(int $x0, int $y0, int $x1, int $y1): void
    {
        self::pointerPath([
            ['type' => 'pointerMove', 'duration' => 0, 'x' => $x0, 'y' => $y0, 'origin' => 'viewport'],
            ['type' => 'pointerDown', 'button' => 0],
            ['type' => 'pointerMove', 'duration' => 80, 'x' => $x1, 'y' => $y1, 'origin' => 'viewport'],
            ['type' => 'pointerUp', 'button' => 0],
        ]);
    }

    /** @param list<array<string, mixed>> $actions */
    private static function pointerPath(array $actions): void
    {
        self::driver()->execute('actions', [
            'actions' => [[
                'type' => 'pointer',
                'id' => 'mouse',
                'parameters' => ['pointerType' => 'mouse'],
                'actions' => $actions,
            ]],
        ]);
    }

    private static function dragOnto(WebDriverElement $grab, WebDriverElement $target): void
    {
        $origin = static fn (WebDriverElement $element): array => [
            JsonWireCompat::WEB_DRIVER_ELEMENT_IDENTIFIER => $element->getID(),
        ];
        // The pointer target is the element center. Chrome rejects the move when that
        // point is outside the window, which happens once the bills handle sits below the fold.
        if (self::scrollDragEndsIntoView($grab, $target)) {
            self::pointerPath([
                ['type' => 'pointerMove', 'duration' => 0, 'origin' => $origin($grab), 'x' => 0, 'y' => 0],
                ['type' => 'pointerDown', 'button' => 0],
                ['type' => 'pointerMove', 'duration' => 200, 'origin' => $origin($target), 'x' => 0, 'y' => 0],
                ['type' => 'pointerUp', 'button' => 0],
            ]);

            return;
        }

        self::scrollCenterIntoView($grab);
        self::pointerPath([
            ['type' => 'pointerMove', 'duration' => 0, 'origin' => $origin($grab), 'x' => 0, 'y' => 0],
            ['type' => 'pointerDown', 'button' => 0],
        ]);
        self::scrollCenterIntoView($target);
        self::pointerPath([
            ['type' => 'pointerMove', 'duration' => 200, 'origin' => $origin($target), 'x' => 0, 'y' => 0],
            ['type' => 'pointerUp', 'button' => 0],
        ]);
    }

    private static function scrollDragEndsIntoView(WebDriverElement $grab, WebDriverElement $target): bool
    {
        $ready = self::driver()->executeScript(<<<'JS'
            const grab = arguments[0];
            const target = arguments[1];
            const margin = 24;
            const center = (el) => {
                const rect = el.getBoundingClientRect();
                return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
            };
            const band = (a, b, limit) => {
                const low = Math.max(a - (limit - margin), b - (limit - margin));
                const high = Math.min(a - margin, b - margin);
                return low <= high ? low : null;
            };
            const inside = (point) => point.x >= margin
                && point.y >= margin
                && point.x <= window.innerWidth - margin
                && point.y <= window.innerHeight - margin;
            const grabCenter = center(grab);
            const targetCenter = center(target);
            const dx = band(grabCenter.x, targetCenter.x, window.innerWidth);
            const dy = band(grabCenter.y, targetCenter.y, window.innerHeight);
            if (dx === null || dy === null) return false;
            window.scrollBy(Math.round(dx), Math.round(dy));
            return inside(center(grab)) && inside(center(target));
        JS, [$grab, $target]);

        return $ready === true;
    }

    private static function scrollCenterIntoView(WebDriverElement $element): void
    {
        self::driver()->executeScript(<<<'JS'
            const el = arguments[0];
            el.scrollIntoView({ block: 'center', inline: 'nearest' });
            const rect = el.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const margin = 8;
            let dx = 0;
            let dy = 0;
            if (x < margin) dx = x - margin;
            else if (x > window.innerWidth - margin) dx = x - (window.innerWidth - margin);
            if (y < margin) dy = y - margin;
            else if (y > window.innerHeight - margin) dy = y - (window.innerHeight - margin);
            if (dx !== 0 || dy !== 0) window.scrollBy(Math.round(dx), Math.round(dy));
        JS, [$element]);
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
