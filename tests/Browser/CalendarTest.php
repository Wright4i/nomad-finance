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

final class CalendarTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    /** Canopy, then trunk. */
    private const TREE = [
        'M12 3 16.6 12.2H7.4L12 3z',
        'M10.2 12.2h3.6V20.4h-3.6z',
    ];

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
            '--disable-features=OverlayScrollbar',
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

    public function testStaysFiltersAndTheDayDialog(): void
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
        self::clickLink('Calendar');
        self::openMonth('2026-11');
        self::waitForText('Stay 1');
        self::waitForText('Nov 20, 2026 – Nov 24, 2026');
        self::waitForText('Weekly Payor');
        self::waitForText('Travel Bill');
        $marks = self::chipMarks();
        self::assertSame('M16.2 8.2A5.2 5.2 0 1 0 17 12M17 7.2V11h-3.6', $marks['income']);
        self::assertSame('M4 14.5 20 9.5l-7 1.2L10 16.5l1.4-4.2L4 14.5z', $marks['bill']);
        self::assertSame(self::TREE, $marks['stay']);
        self::assertTrue($marks['incomeInk']);
        self::assertTrue($marks['billInk']);
        self::assertSame('--coral', $marks['billFill']);
        self::assertTrue($marks['stayInk']);
        self::assertGreaterThan(0, $marks['stayChips']);
        self::assertSame($marks['stayChips'], $marks['stayIcons']);
        self::waitForText('Show Income');
        self::waitForText('Show Stays');
        self::waitForText('Show Paid Bills');
        self::waitForText('Show Unpaid Bills');
        $labels = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('.calendar-filter')].map((node) => node.innerText.replace(/\s+/g, ' ').trim());
        JS);
        self::assertSame(['Show Income', 'Show Stays', 'Show Paid Bills', 'Show Unpaid Bills'], $labels);
        $checked = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('.calendar-filter input')].map((input) => input.checked);
        JS);
        self::assertSame([true, true, true, true], $checked);
        self::assertSame('--lilac', self::filterTone('Show Paid Bills'));
        self::assertSame('--coral', self::filterTone('Show Unpaid Bills'));
        self::assertSame('--lilac', self::chipFill('2026-11-04', 'Paid Weekly Bill'));
        self::assertSame(1, self::chipCount('2026-11-04', 'Paid Weekly Bill'));
        self::assertSame('--coral', self::chipFill('2026-11-11', 'Paid Weekly Bill'));
        self::assertStringContainsString('$64.68', self::paidColumn());
        self::setFilter('Show Unpaid Bills', false);
        self::assertSame('--lilac', self::chipFill('2026-11-04', 'Paid Weekly Bill'));
        self::assertSame('', self::chipFill('2026-11-11', 'Paid Weekly Bill'));
        self::setFilter('Show Unpaid Bills', true);
        self::setFilter('Show Paid Bills', false);
        self::assertSame('', self::chipFill('2026-11-04', 'Paid Weekly Bill'));
        self::assertSame('--coral', self::chipFill('2026-11-11', 'Paid Weekly Bill'));
        self::setFilter('Show Paid Bills', true);
        self::assertSame('--lilac', self::chipFill('2026-11-04', 'Paid Weekly Bill'));

        $total = self::monthTotal();
        self::assertStringContainsString('Income', $total);
        self::assertStringContainsString('Debt', $total);
        self::assertStringContainsString('Paid', $total);
        self::assertStringContainsString('Net', $total);
        self::assertStringNotContainsString('Stay 1', $total);

        self::setFilter('Show Stays', false);
        self::waitForTextGone('Stay 1');
        self::assertSame($total, self::monthTotal());
        self::clickDay('2026-11-24');
        $depart = self::dialogText();
        self::assertStringContainsString('Stay 1', $depart);
        self::assertStringContainsString('Nov 20, 2026 – Nov 24, 2026', $depart);
        self::assertStringContainsString('No income this day.', $depart);
        self::assertStringContainsString('No bills this day.', $depart);
        self::assertStringNotContainsString('No stay this day.', $depart);
        self::assertSame(self::TREE, self::dialogPaths('Stay'));
        self::assertLessThan(strpos($depart, 'Income'), strpos($depart, 'Stay'));
        self::assertLessThan(strpos($depart, 'Bills'), strpos($depart, 'Income'));
        self::click('Close');

        self::clickDay('2026-11-20');
        $arrive = self::dialogText();
        self::assertStringContainsString('Stay 1', $arrive);
        self::assertStringContainsString('Recurring Yearly Bill', $arrive);
        self::assertStringContainsString('No income this day.', $arrive);
        self::click('Close');

        self::clickDay('2026-11-19');
        $before = self::dialogText();
        self::assertStringContainsString('No stay this day.', $before);
        self::assertStringContainsString('No income this day.', $before);
        self::assertStringContainsString('Travel Bill', $before);
        self::assertSame('M4 14.5 20 9.5l-7 1.2L10 16.5l1.4-4.2L4 14.5z', self::dialogBillPath('Travel Bill'));
        self::click('Close');

        self::setFilter('Show Stays', true);
        self::setFilter('Show Income', false);
        self::setFilter('Show Paid Bills', false);
        self::setFilter('Show Unpaid Bills', false);
        self::waitForTextGone('Weekly Payor');
        self::waitForTextGone('Travel Bill');
        self::assertSame($total, self::monthTotal());
        self::clickDay('2026-11-22');
        $middle = self::dialogText();
        self::assertStringContainsString('Stay 1', $middle);
        self::assertStringContainsString('Weekly Payor', $middle);
        self::assertStringContainsString('Recurring Weekly Bill', $middle);
        self::assertStringNotContainsString('No bills this day.', $middle);
        self::assertSame('M16.2 8.2A5.2 5.2 0 1 0 17 12M17 7.2V11h-3.6', self::dialogMark('Income'));
        self::click('Close');
        self::setFilter('Show Income', true);
        self::setFilter('Show Paid Bills', true);
        self::setFilter('Show Unpaid Bills', true);
        self::waitForText('Weekly Payor');
        self::waitForText('Stay 1');

        self::openMonth('2026-10');
        self::waitForText('Paid One-Time Bill');
        self::waitForText('Overdue Monthly Bill');
        self::assertSame('--lilac', self::chipFill('2026-10-01', 'Paid One-Time Bill'));
        self::assertSame('--coral', self::chipFill('2026-10-15', 'Overdue Monthly Bill'));
        self::assertTrue(self::chipInk('2026-10-01', 'Paid One-Time Bill'));
        self::assertTrue(self::chipInk('2026-10-15', 'Overdue Monthly Bill'));
        self::clickDay('2026-10-01');
        self::assertStringContainsString('Paid One-Time Bill', self::dialogText());
        self::assertSame('--lilac', self::dialogAmountTone('Bills'));
        self::click('Close');
        self::clickDay('2026-10-15');
        self::assertStringContainsString('Overdue Monthly Bill', self::dialogText());
        self::assertSame('--coral', self::dialogAmountTone('Bills', 'Overdue Monthly Bill'));
        self::click('Close');

        self::setFilter('Show Unpaid Bills', false);
        self::waitForTextGone('Overdue Monthly Bill');
        self::waitForText('Paid One-Time Bill');
        self::setFilter('Show Unpaid Bills', true);
        self::waitForText('Overdue Monthly Bill');
        self::setFilter('Show Paid Bills', false);
        self::waitForTextGone('Paid One-Time Bill');
        self::waitForText('Overdue Monthly Bill');
        self::setFilter('Show Paid Bills', true);
        self::waitForText('Paid One-Time Bill');

        self::openMonth('2026-12');
        self::wait()->until(function (): bool {
            return self::chipFill('2026-12-15', 'Recurring Monthly Bill') === '--coral';
        });
        self::assertSame(1, self::chipCount('2026-12-15', 'Recurring Monthly Bill'));

        self::clickLink('Bills');
        self::waitForText('Paid Weekly Bill');
        $actions = self::driver()->executeScript(<<<'JS'
            const buttons = (section, name) => {
                const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === section);
                const card = heading && heading.parentElement
                    ? [...heading.parentElement.querySelectorAll('article')].find((node) => node.innerText.includes(name))
                    : null;
                return card ? [...card.querySelectorAll('button')].map((node) => node.textContent.trim()) : [];
            };
            return { active: buttons('Active', 'Paid Weekly Bill'), paid: buttons('Paid', 'Paid Weekly Bill') };
        JS);
        self::assertIsArray($actions);
        self::assertContains('Mark paid', $actions['active']);
        self::assertNotContains('Unpay', $actions['active']);
        self::assertContains('Unpay', $actions['paid']);
        self::assertNotContains('Mark paid', $actions['paid']);
        self::wait()->until(
            WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::partialLinkText('Paid Weekly Bill'))
        )->click();
        self::waitForText('Save bill');
        $open = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('button')].map((node) => node.textContent.trim());
        JS);
        self::assertIsArray($open);
        self::assertContains('Mark paid', $open);
        self::assertNotContains('Unpay', $open);
        self::clickLink('Calendar');
        self::waitForText('Show Paid Bills');
    }

    #[Depends('testStaysFiltersAndTheDayDialog')]
    public function testSmallScreensStackOneDayPerRow(): void
    {
        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        self::openMonth('2026-10');
        $phone = self::layout();
        self::assertSame(1, $phone['columns']);
        self::assertTrue($phone['headHidden']);
        self::assertSame(0, $phone['pads']);
        self::assertFalse($phone['overflow']);
        self::assertSame('2026-10-01', $phone['days'][0]);
        self::assertCount(31, $phone['days']);
        self::assertSame('Thu1', self::compact($phone['label']));

        self::openMonth('2026-11');
        self::waitForText('Stay 1');
        self::waitForText('Show Stays');
        $november = self::layout();
        self::assertSame(1, $november['columns']);
        self::assertSame('Sun1', self::compact($november['label']));
        self::assertFalse($november['overflow']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::openMonth('2026-10');
        $desktop = self::layout();
        self::assertSame(7, $desktop['columns']);
        self::assertFalse($desktop['headHidden']);
        self::assertSame(4, $desktop['pads']);
        self::assertSame('2026-10-01', $desktop['days'][0]);
        self::assertSame('1', self::compact($desktop['label']));
    }

    #[Depends('testStaysFiltersAndTheDayDialog')]
    public function testCrowdedDayShowsThreeItemsAndKeepsTheNumber(): void
    {
        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::clickLink('Calendar');
        self::openMonth('2026-11');
        self::setFilter('Show Income', true);
        self::setFilter('Show Stays', true);
        self::setFilter('Show Paid Bills', true);
        self::setFilter('Show Unpaid Bills', true);
        self::waitForText('Stay 1');
        self::waitForText('Weekly Payor');
        self::wait()->until(function (): bool {
            $first = self::dayWindow('2026-11-01');
            $stay = self::dayWindow('2026-11-20');

            return $first['chips'] > 3
                && $first['chipHeight'] > 24
                && $first['visible'] === 3
                && $first['arrow'] === true
                && $stay['chips'] > 3
                && $stay['visible'] === 3
                && $stay['arrow'] === true;
        });

        $quiet = self::dayWindow('2026-11-19');
        self::assertGreaterThan(0, $quiet['chips']);
        self::assertLessThanOrEqual(3, $quiet['chips']);
        self::assertFalse($quiet['arrow']);
        self::assertSame($quiet['chips'], $quiet['visible']);

        foreach (self::dayArrows() as $row) {
            if ($row['chips'] > 3) {
                self::assertTrue($row['arrow'], $row['date'] . ' shows a down arrow');
            } else {
                self::assertFalse($row['arrow'], $row['date'] . ' hides the down arrow');
            }
        }

        $scrolled = self::scrollDay('2026-11-01');
        self::assertSame('auto', $scrolled['overflowY']);
        self::assertGreaterThan($scrolled['clientHeight'], $scrolled['scrollHeight']);
        self::assertGreaterThan(0, $scrolled['scrollTop']);
        self::assertGreaterThanOrEqual(4, $scrolled['scrollbar'], 'scrollbar ' . $scrolled['scrollbar'] . ' css ' . $scrolled['barWidth']);
        self::assertTrue($scrolled['atEnd']);
        self::assertLessThan(2.0, $scrolled['numberDelta']);
        self::assertGreaterThan(0.0, $scrolled['numberFromCell']);
        self::assertLessThan(20.0, $scrolled['numberFromCell']);
        self::assertFalse($scrolled['numberInsideList']);
        self::assertSame('M12 5v11M7.5 12.5 12 17.5 16.5 12.5', $scrolled['arrow']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(390, 844));
        self::wait()->until(function (): bool {
            $phone = self::dayWindow('2026-11-01');

            return $phone['chips'] > 3 && $phone['visible'] === 3 && $phone['arrow'] === true;
        });
        $phoneScroll = self::scrollDay('2026-11-01');
        self::assertSame('auto', $phoneScroll['overflowY']);
        self::assertGreaterThan(0, $phoneScroll['scrollTop']);
        self::assertGreaterThanOrEqual(4, $phoneScroll['scrollbar']);
        self::assertLessThan(2.0, $phoneScroll['numberDelta']);
        self::assertGreaterThan(0.0, $phoneScroll['numberFromCell']);
        self::assertLessThan(24.0, $phoneScroll['numberFromCell']);

        self::driver()->manage()->window()->setSize(new WebDriverDimension(1280, 900));
        self::assertSame(1, self::chipCount('2026-11-01', 'Weekly Payor'));
        self::assertSame(1, self::chipCount('2026-11-01', 'Averaged Payor'));
        self::clickDay('2026-11-01');
        self::assertStringContainsString('Averaged Payor', self::dialogText());
        self::assertStringContainsString('Weekly Payor', self::dialogText());
        self::click('Close');
    }

    private static function compact(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', '', str_replace("\u{00A0}", '', $value));

        return is_string($collapsed) ? $collapsed : $value;
    }

    private static function setFilter(string $label, bool $on): void
    {
        self::wait()->until(function () use ($label, $on): bool {
            $ready = self::driver()->executeScript(
                <<<'JS'
                    const label = [...document.querySelectorAll('.calendar-filter')].find((node) => node.textContent.includes(arguments[0]));
                    if (!label) return false;
                    const input = label.querySelector('input');
                    if (!input) return false;
                    if (input.checked !== arguments[1]) input.click();
                    return input.checked === arguments[1];
                JS,
                [$label, $on],
            );

            return $ready === true;
        });
    }

    private static function openMonth(string $month): void
    {
        self::wait()->until(function (): bool {
            $current = self::driver()->executeScript(<<<'JS'
                const node = document.querySelector('[data-month]');
                return node ? node.getAttribute('data-month') : '';
            JS);

            return is_string($current) && preg_match('/^\d{4}-\d{2}$/', $current) === 1;
        });
        for ($step = 0; $step < 36; $step++) {
            $current = self::driver()->executeScript(<<<'JS'
                const node = document.querySelector('[data-month]');
                return node ? node.getAttribute('data-month') : '';
            JS);
            if ($current === $month) {
                return;
            }
            if (!is_string($current) || preg_match('/^\d{4}-\d{2}$/', $current) !== 1) {
                throw new RuntimeException('The calendar month is missing.');
            }
            $label = $current > $month ? 'Previous month' : 'Next month';
            $by = WebDriverBy::xpath('//button[normalize-space()=' . self::quote($label) . ']');
            $element = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
            self::driver()->executeScript(
                'arguments[0].scrollIntoView({block:"center", inline:"nearest"})',
                [$element],
            );
            self::click($label);
        }

        throw new RuntimeException('Could not reach ' . $month);
    }

    /** @return array{chips:int,chipHeight:int,visible:int,arrow:bool,overflowY:string,scrollHeight:int,clientHeight:int,scrollbar:int} */
    private static function dayWindow(string $iso): array
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const day = document.querySelector(`.day[data-date="${arguments[0]}"]`);
                if (!day) return null;
                const list = day.querySelector('.day-items');
                if (list) list.scrollTop = 0;
                const chips = list ? [...list.querySelectorAll('.bill-chip')] : [];
                const listRect = list ? list.getBoundingClientRect() : null;
                const visible = listRect ? chips.filter((chip) => {
                    const rect = chip.getBoundingClientRect();
                    if (rect.height <= 0) return false;
                    const shown = Math.min(rect.bottom, listRect.bottom) - Math.max(rect.top, listRect.top);
                    return shown >= rect.height - 1;
                }).length : 0;
                const styles = list ? getComputedStyle(list) : null;
                return {
                    chips: chips.length,
                    chipHeight: chips[0] ? chips[0].offsetHeight : 0,
                    visible,
                    arrow: Boolean(day.querySelector('.day-more')),
                    overflowY: styles ? styles.overflowY : '',
                    scrollHeight: list ? list.scrollHeight : 0,
                    clientHeight: list ? list.clientHeight : 0,
                    scrollbar: list ? list.offsetWidth - list.clientWidth : 0,
                };
            JS,
            [$iso],
        );
        if (!is_array($value)) {
            return [
                'chips' => 0,
                'chipHeight' => 0,
                'visible' => 0,
                'arrow' => false,
                'overflowY' => '',
                'scrollHeight' => 0,
                'clientHeight' => 0,
                'scrollbar' => 0,
            ];
        }

        return [
            'chips' => (int) $value['chips'],
            'chipHeight' => (int) $value['chipHeight'],
            'visible' => (int) $value['visible'],
            'arrow' => $value['arrow'] === true,
            'overflowY' => is_string($value['overflowY']) ? $value['overflowY'] : '',
            'scrollHeight' => (int) $value['scrollHeight'],
            'clientHeight' => (int) $value['clientHeight'],
            'scrollbar' => (int) $value['scrollbar'],
        ];
    }

    /** @return list<array{date:string,chips:int,arrow:bool}> */
    private static function dayArrows(): array
    {
        $value = self::driver()->executeScript(<<<'JS'
            return [...document.querySelectorAll('.day[data-date]')].map((day) => {
                const list = day.querySelector('.day-items');
                return {
                    date: day.getAttribute('data-date') || '',
                    chips: list ? list.querySelectorAll('.bill-chip').length : 0,
                    arrow: Boolean(day.querySelector('.day-more')),
                };
            });
        JS);
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'date' => is_string($row['date'] ?? null) ? $row['date'] : '',
                'chips' => (int) ($row['chips'] ?? 0),
                'arrow' => ($row['arrow'] ?? false) === true,
            ];
        }

        return $rows;
    }

    /** @return array{overflowY:string,scrollHeight:int,clientHeight:int,scrollTop:int,scrollbar:int,atEnd:bool,numberDelta:float,numberFromCell:float,arrow:string,numberInsideList:bool} */
    private static function scrollDay(string $iso): array
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const day = document.querySelector(`.day[data-date="${arguments[0]}"]`);
                const list = day.querySelector('.day-items');
                const number = day.querySelector('strong');
                const arrow = day.querySelector('.day-more svg path');
                day.scrollIntoView({ block: 'center', inline: 'nearest' });
                const beforeNumber = number.getBoundingClientRect().top;
                list.scrollTop = list.scrollHeight;
                const afterNumber = number.getBoundingClientRect().top;
                const cellTop = day.getBoundingClientRect().top;
                const paint = getComputedStyle(list, '::-webkit-scrollbar');
                return {
                    overflowY: getComputedStyle(list).overflowY,
                    scrollHeight: list.scrollHeight,
                    clientHeight: list.clientHeight,
                    scrollTop: list.scrollTop,
                    scrollbar: list.offsetWidth - list.clientWidth,
                    barWidth: paint.width,
                    atEnd: list.scrollTop + list.clientHeight >= list.scrollHeight - 2,
                    numberDelta: Math.abs(afterNumber - beforeNumber),
                    numberFromCell: afterNumber - cellTop,
                    arrow: arrow ? arrow.getAttribute('d') || '' : '',
                    numberInsideList: list.contains(number),
                };
            JS,
            [$iso],
        );
        self::assertIsArray($value);

        return [
            'overflowY' => is_string($value['overflowY']) ? $value['overflowY'] : '',
            'scrollHeight' => (int) $value['scrollHeight'],
            'clientHeight' => (int) $value['clientHeight'],
            'scrollTop' => (int) $value['scrollTop'],
            'scrollbar' => (int) $value['scrollbar'],
            'barWidth' => is_string($value['barWidth'] ?? null) ? $value['barWidth'] : '',
            'atEnd' => $value['atEnd'] === true,
            'numberDelta' => (float) $value['numberDelta'],
            'numberFromCell' => (float) $value['numberFromCell'],
            'arrow' => is_string($value['arrow']) ? $value['arrow'] : '',
            'numberInsideList' => $value['numberInsideList'] === true,
        ];
    }

    private static function clickDay(string $iso): void
    {
        $by = WebDriverBy::cssSelector('.day[data-date="' . $iso . '"] strong');
        $element = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
        self::driver()->executeScript(
            'arguments[0].scrollIntoView({block:"center", inline:"nearest"})',
            [$element],
        );
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function dialogText(): string
    {
        $text = self::wait()->until(function (): string|false {
            $value = self::driver()->executeScript(<<<'JS'
                const dialog = document.querySelector('dialog[open]');
                return dialog ? dialog.innerText : '';
            JS);

            return is_string($value) && $value !== '' ? $value : false;
        });
        self::assertIsString($text);

        return $text;
    }

    /** @return array{income:string,bill:string,incomeInk:bool,billInk:bool,billFill:string,stay:list<string>,stayInk:bool,stayIcons:int,stayChips:int} */
    private static function chipMarks(): array
    {
        $value = self::driver()->executeScript(<<<'JS'
            const chip = (selector) => document.querySelector(selector);
            const drawn = (node) => {
                const path = node ? node.querySelector('.glyph path') : null;
                return path ? path.getAttribute('d') || '' : '';
            };
            const paths = (node) => node
                ? [...node.querySelectorAll('.glyph path')].map((path) => path.getAttribute('d') || '')
                : [];
            const ink = (node) => {
                if (!node) return false;
                const icon = node.querySelector('.glyph');
                const name = node.querySelector('.bill-chip-name');
                if (!icon || !name) return false;
                return getComputedStyle(icon).color === getComputedStyle(name).color;
            };
            const probe = document.createElement('span');
            document.body.appendChild(probe);
            const token = (paint) => {
                const named = ['--coral', '--lilac', '--sky', '--teal'].find((name) => {
                    probe.style.color = `var(${name})`;
                    return getComputedStyle(probe).color === paint;
                });
                return named || paint;
            };
            const income = chip('.day[data-date="2026-11-01"] .income-chip[title="Weekly Payor"]');
            const bill = chip('.day[data-date="2026-11-19"] .bill-chip[title="Travel Bill"]');
            const stay = chip('.day[data-date="2026-11-20"] .stay-chip[title="Stay 1"]');
            const stays = document.querySelectorAll('.stay-chip');
            const billFill = bill ? token(getComputedStyle(bill).backgroundColor) : '';
            probe.remove();
            return {
                income: drawn(income),
                bill: drawn(bill),
                incomeInk: ink(income),
                billInk: ink(bill),
                billFill,
                stay: paths(stay),
                stayInk: ink(stay),
                stayIcons: document.querySelectorAll('.stay-chip .glyph').length,
                stayChips: stays.length,
            };
        JS);
        self::assertIsArray($value);

        return $value;
    }

    private static function chipFill(string $date, string $title): string
    {
        return self::paintToken('.day[data-date="' . $date . '"] .bill-chip[title="' . $title . '"]', 'backgroundColor');
    }

    private static function chipCount(string $date, string $title): int
    {
        $value = self::driver()->executeScript(
            'return document.querySelectorAll(arguments[0]).length;',
            ['.day[data-date="' . $date . '"] .bill-chip[title="' . $title . '"]'],
        );

        return is_int($value) ? $value : 0;
    }

    private static function chipInk(string $date, string $title): bool
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const node = document.querySelector(arguments[0]);
                if (!node) return false;
                const icon = node.querySelector('.glyph');
                const name = node.querySelector('.bill-chip-name');
                if (!icon || !name) return false;
                return getComputedStyle(icon).color === getComputedStyle(name).color;
            JS,
            ['.day[data-date="' . $date . '"] .bill-chip[title="' . $title . '"]'],
        );

        return $value === true;
    }

    private static function filterTone(string $label): string
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const label = [...document.querySelectorAll('.calendar-filter')].find((node) => node.innerText.replace(/\s+/g, ' ').includes(arguments[0]));
                if (!label) return '';
                const swatch = label.querySelector('.calendar-swatch');
                const mark = label.querySelector('.calendar-mark, .glyph');
                if (!swatch || !mark) return '';
                const probe = document.createElement('span');
                document.body.appendChild(probe);
                const nameOf = (paint) => {
                    const named = ['--coral', '--lilac', '--sky', '--teal'].find((name) => {
                        probe.style.color = `var(${name})`;
                        return getComputedStyle(probe).color === paint;
                    });
                    return named || paint;
                };
                const swatchName = nameOf(getComputedStyle(swatch).backgroundColor);
                const markName = nameOf(getComputedStyle(mark).color);
                probe.remove();
                return swatchName === markName ? swatchName : swatchName + '/' + markName;
            JS,
            [$label],
        );
        self::assertIsString($value);

        return $value;
    }

    private static function dialogAmountTone(string $heading, ?string $bill = null): string
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const title = [...document.querySelectorAll('dialog[open] h3')].find((node) => node.textContent.trim() === arguments[0]);
                const section = title ? title.parentElement : null;
                const row = arguments[1] && section
                    ? [...section.querySelectorAll('a.row')].find((node) => node.innerText.includes(arguments[1]))
                    : null;
                const money = row ? row.querySelector('strong.money') : (section ? section.querySelector('strong.money') : null);
                if (!money) return '';
                const paint = getComputedStyle(money).color;
                const probe = document.createElement('span');
                document.body.appendChild(probe);
                const named = ['--coral', '--lilac'].find((name) => {
                    probe.style.color = `var(${name})`;
                    return getComputedStyle(probe).color === paint;
                });
                probe.remove();
                return named || paint;
            JS,
            [$heading, $bill],
        );
        self::assertIsString($value);

        return $value;
    }

    private static function paintToken(string $selector, string $property): string
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const node = document.querySelector(arguments[0]);
                if (!node) return '';
                const paint = getComputedStyle(node)[arguments[1]];
                const probe = document.createElement('span');
                document.body.appendChild(probe);
                const named = ['--coral', '--lilac', '--sky', '--teal'].find((name) => {
                    probe.style.color = `var(${name})`;
                    return getComputedStyle(probe).color === paint;
                });
                probe.remove();
                return named || paint;
            JS,
            [$selector, $property],
        );
        self::assertIsString($value);

        return $value;
    }

    /** @return list<string> */
    private static function dialogPaths(string $heading): array
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const title = [...document.querySelectorAll('dialog[open] h3')].find((node) => node.textContent.trim() === arguments[0]);
                if (!title || !title.parentElement) return [];
                return [...title.parentElement.querySelectorAll('.glyph path')].map((path) => path.getAttribute('d') || '');
            JS,
            [$heading],
        );
        self::assertIsArray($value);

        return array_values($value);
    }

    private static function dialogMark(string $heading): string
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const title = [...document.querySelectorAll('dialog[open] h3')].find((node) => node.textContent.trim() === arguments[0]);
                const path = title ? title.parentElement.querySelector('.glyph path') : null;
                return path ? path.getAttribute('d') || '' : '';
            JS,
            [$heading],
        );
        self::assertIsString($value);

        return $value;
    }

    private static function dialogBillPath(string $name): string
    {
        $value = self::driver()->executeScript(
            <<<'JS'
                const row = [...document.querySelectorAll('dialog[open] a')].find((node) => node.innerText.includes(arguments[0]));
                const path = row ? row.querySelector('.glyph path') : null;
                return path ? path.getAttribute('d') || '' : '';
            JS,
            [$name],
        );
        self::assertIsString($value);

        return $value;
    }

    private static function paidColumn(): string
    {
        $text = self::driver()->findElement(WebDriverBy::cssSelector('.month-paid'))->getText();
        self::assertIsString($text);

        return $text;
    }

    private static function monthTotal(): string
    {
        $text = self::driver()->findElement(WebDriverBy::cssSelector('.month-total'))->getText();
        self::assertIsString($text);

        return $text;
    }

    /** @return array{columns:int,headHidden:bool,pads:int,overflow:bool,days:list<string>,label:string} */
    private static function layout(): array
    {
        $value = self::driver()->executeScript(<<<'JS'
            const month = document.querySelector('.month');
            const scroll = document.querySelector('.month-scroll');
            const columns = getComputedStyle(month).gridTemplateColumns.split(' ').filter(Boolean).length;
            const heads = [...document.querySelectorAll('.month-head')];
            const pads = [...document.querySelectorAll('.month-pad')].filter((node) => getComputedStyle(node).display !== 'none').length;
            const days = [...document.querySelectorAll('.day')].map((node) => node.getAttribute('data-date'));
            const label = document.querySelector('.day strong') ? document.querySelector('.day strong').innerText : '';
            return {
                columns,
                headHidden: heads.length > 0 && heads.every((node) => getComputedStyle(node).display === 'none'),
                pads,
                overflow: scroll.scrollWidth > scroll.clientWidth + 2,
                days,
                label,
            };
        JS);
        self::assertIsArray($value);

        return $value;
    }

    private static function click(string $label): void
    {
        $by = WebDriverBy::xpath('//button[normalize-space()=' . self::quote($label) . ']');
        $element = self::wait()->until(WebDriverExpectedCondition::presenceOfElementLocated($by));
        self::driver()->executeScript(
            'arguments[0].scrollIntoView({block:"center", inline:"nearest"})',
            [$element],
        );
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
