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
        self::waitForText('assigned of');
        self::waitForText('Needs');
        self::waitForText('50%');
        self::waitForText('Assigned');
        self::waitForText('Max');
        self::waitForText('Wants');
        self::waitForText('30%');
        self::waitForText('Savings');
        self::waitForText('20%');
        $gauges = self::driver()->executeScript(<<<'JS'
            const read = (name) => {
                const gauge = [...document.querySelectorAll('.want-gauge')].find((node) => node.innerText.startsWith(name));
                return {
                    text: gauge ? gauge.innerText : '',
                    preview: gauge ? gauge.querySelectorAll('.want-gauge-preview').length : -1,
                };
            };
            return { needs: read('Needs'), wants: read('Wants'), savings: read('Savings') };
        JS);
        self::assertIsArray($gauges);
        self::assertStringContainsStringIgnoringCase('Assigned', $gauges['needs']['text']);
        self::assertStringContainsStringIgnoringCase('Max', $gauges['needs']['text']);
        self::assertSame(0, $gauges['needs']['preview']);
        self::assertStringContainsStringIgnoringCase('Assigned', $gauges['wants']['text']);
        self::assertSame(0, $gauges['wants']['preview']);
        self::assertStringContainsStringIgnoringCase('Assigned', $gauges['savings']['text']);
        self::assertSame(0, $gauges['savings']['preview']);
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
            return {
                envelopeText,
                needsBeforeAvailable: folded.indexOf('needs') >= 0 && folded.indexOf('available to assign') > folded.indexOf('needs'),
                gauges: envelopes.querySelectorAll('button.want-gauge').length,
                rentText: rent ? rent.innerText : '',
                billText: bills.innerText,
                pies: bills.querySelectorAll('svg.bill-pie').length,
                rentFill: slice ? getComputedStyle(slice).fill : '',
                teal: teal ? getComputedStyle(teal).color : '',
                swatch: swatch ? getComputedStyle(swatch).backgroundColor : '',
                assign: Boolean(envelopes.querySelector('a[href="/envelopes"]')),
                squares: monthButtons.length,
                monthButtons,
            };
        JS);
        self::assertIsArray($planner);
        self::assertTrue($planner['needsBeforeAvailable']);
        self::assertSame(3, $planner['gauges']);
        self::assertStringNotContainsString('Previous period', $planner['envelopeText']);
        self::assertStringNotContainsString('Next period', $planner['envelopeText']);
        self::assertStringNotContainsString('Open period', $planner['envelopeText']);
        self::assertStringNotContainsString('Cash 3', $planner['envelopeText']);
        self::assertMatchesRegularExpression('/Available to assign\s+\$[\d,]+\.\d{2}/', $planner['envelopeText']);
        self::assertStringContainsString('assigned of', $planner['envelopeText']);
        preg_match('/Available to assign\s+(\$[\d,]+\.\d{2})/', $planner['envelopeText'], $availableMatch);
        $availableText = $availableMatch[1];
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
        self::assertFalse($planner['assign']);
        self::assertSame(2, $planner['squares']);
        self::assertSame('Current Month', $planner['monthButtons'][0]['text']);
        self::assertSame('Next Month', $planner['monthButtons'][1]['text']);
        foreach ($planner['monthButtons'] as $button) {
            self::assertGreaterThan($button['height'], $button['width']);
            self::assertLessThan(72, $button['height']);
        }
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
        self::waitForText($availableText);
        self::waitForText('Needs 1');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '500.00';
        });
        $headings = self::driver()->executeScript(<<<'JS'
            const read = (name) => {
                const heading = document.querySelector(`h2.group-title[data-group="${name}"]`);
                const figure = (key) => {
                    const node = heading ? heading.querySelector(`[data-figure="${key}"]`) : null;
                    return node ? node.innerText.replace(/\s+/g, ' ').trim() : '';
                };
                const label = heading ? heading.querySelector('.group-name') : null;
                const sums = heading ? heading.querySelector('.group-sums') : null;
                const labelBox = label ? label.getBoundingClientRect() : null;
                const sumsBox = sums ? sums.getBoundingClientRect() : null;
                return {
                    assigned: figure('assigned'),
                    max: figure('max'),
                    target: figure('target'),
                    beside: Boolean(labelBox && sumsBox && sumsBox.left >= labelBox.right - 4 && Math.abs(sumsBox.top - labelBox.top) < 24),
                };
            };
            return { needs: read('needs'), wants: read('wants'), savings: read('savings') };
        JS);
        self::assertIsArray($headings);
        foreach (['needs', 'wants', 'savings'] as $group) {
            self::assertIsArray($headings[$group]);
            self::assertStringStartsWith('ASSIGNED $', $headings[$group]['assigned']);
            self::assertStringStartsWith('MAX $', $headings[$group]['max']);
            self::assertStringStartsWith('TARGET $', $headings[$group]['target']);
            self::assertStringNotContainsString('USD', $headings[$group]['assigned'] . $headings[$group]['max'] . $headings[$group]['target']);
            self::assertTrue($headings[$group]['beside']);
        }
        $hero = self::driver()->executeScript(<<<'JS'
            const figure = document.querySelector('[data-action="available"] .figure');
            const select = document.querySelector('select[name="payCycleId"]');
            return { text: figure ? figure.innerText.trim() : '', payor: Boolean(select) };
        JS);
        self::assertIsArray($hero);
        self::assertFalse($hero['payor']);
        self::assertSame($availableText, $hero['text']);
        $availableCents = self::cents($hero['text']);
        self::assertSame('TARGET ' . self::money(intdiv($availableCents * 50 + 50, 100)), $headings['needs']['target']);
        self::assertSame('TARGET ' . self::money(intdiv($availableCents * 30 + 50, 100)), $headings['wants']['target']);
        self::assertSame('TARGET ' . self::money(intdiv($availableCents * 20 + 50, 100)), $headings['savings']['target']);
        self::clickAvailable();
        $sources = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            if (!dialog) return null;
            const income = dialog.querySelector('[data-source="income"]');
            const accounts = dialog.querySelector('[data-source="accounts"]');
            const checking = dialog.querySelector('[data-type="checking"] .glyph');
            const savings = dialog.querySelector('[data-type="savings"] .glyph');
            return {
                income: income ? income.innerText : '',
                accounts: accounts ? accounts.innerText : '',
                incomeGlyph: Boolean(income && income.querySelector('.glyph')),
                accountGlyph: Boolean(accounts && accounts.querySelector('.glyph')),
                checking: checking ? getComputedStyle(checking).color : '',
                savings: savings ? getComputedStyle(savings).color : '',
            };
        JS);
        self::assertIsArray($sources);
        self::assertStringContainsString('Payor', $sources['income']);
        self::assertStringContainsString('$', $sources['income']);
        self::assertStringContainsString('Checking', $sources['accounts']);
        self::assertStringContainsString('$', $sources['accounts']);
        self::assertTrue($sources['incomeGlyph']);
        self::assertTrue($sources['accountGlyph']);
        self::assertNotSame('', $sources['checking']);
        self::assertNotSame($sources['checking'], $sources['savings']);
        self::driver()->findElement(WebDriverBy::cssSelector('dialog.modal[open] #available-title'))->click();
        self::assertTrue(self::driver()->executeScript('return document.querySelector("dialog.modal[open] #available-title") !== null') === true);
        self::clickBackdrop();
        self::wait()->until(function (): bool {
            return self::driver()->executeScript('return document.querySelector("dialog.modal[open]") === null') === true;
        });
        $bars = self::driver()->executeScript(<<<'JS'
            const tile = (name) => [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes(name));
            const read = (name) => {
                const row = tile(name);
                const water = row ? row.querySelector('.bucket-water') : null;
                const box = water ? water.getBoundingClientRect() : null;
                const wave = water ? water.querySelector('.bucket-wave') : null;
                const assigned = row ? row.querySelector('input[name^="amount-"]') : null;
                const maximum = row ? row.querySelector('input[name^="maximum-"]') : null;
                const heading = row ? row.querySelector('strong') : null;
                const label = row ? row.querySelector('.bucket-field > span') : null;
                const glyph = row ? row.querySelector('.glyph') : null;
                return {
                    found: Boolean(row),
                    input: assigned !== null,
                    nameShadow: heading ? getComputedStyle(heading).textShadow : 'none',
                    nameStroke: heading ? getComputedStyle(heading).webkitTextStroke : '',
                    nameColor: heading ? getComputedStyle(heading).color : '',
                    labelShadow: label ? getComputedStyle(label).textShadow : 'none',
                    labelStroke: label ? getComputedStyle(label).webkitTextStroke : '',
                    labelColor: label ? getComputedStyle(label).color : '',
                    inputShadow: assigned ? getComputedStyle(assigned).textShadow : 'none',
                    inputStroke: assigned ? getComputedStyle(assigned).webkitTextStroke : '',
                    inputColor: assigned ? getComputedStyle(assigned).color : '',
                    maximumStroke: maximum ? getComputedStyle(maximum).webkitTextStroke : '',
                    maximumColor: maximum ? getComputedStyle(maximum).color : '',
                    glyphFilter: glyph ? getComputedStyle(glyph).filter : 'none',
                    assignedDisabled: assigned ? assigned.disabled : true,
                    assignedValue: assigned ? assigned.value : '',
                    maximumDisabled: maximum ? maximum.disabled : false,
                    maximumValue: maximum ? maximum.value : '',
                    water: row ? row.style.getPropertyValue('--level') : '',
                    positioned: water ? getComputedStyle(water).position : '',
                    height: box ? box.height : 0,
                    waves: water ? water.querySelectorAll('.bucket-wave').length : 0,
                    wave: wave ? getComputedStyle(wave).animationName : '',
                    duration: wave ? getComputedStyle(wave).animationDuration : '',
                    timing: wave ? getComputedStyle(wave).animationTimingFunction : '',
                    radius: wave ? getComputedStyle(wave).borderTopLeftRadius : '',
                };
            };
            return { needs: read('Needs 1'), wants: read('Wants 1'), rent: read('Rent') };
        JS);
        self::assertIsArray($bars);
        self::assertTrue($bars['needs']['found']);
        self::assertTrue($bars['needs']['input']);
        self::assertSame('none', $bars['needs']['nameShadow']);
        self::assertSame('none', $bars['needs']['labelShadow']);
        self::assertSame('none', $bars['needs']['inputShadow']);
        self::assertHardStroke((string) $bars['needs']['nameStroke']);
        self::assertFieldInk($bars['needs']);
        self::assertHardIcon((string) $bars['needs']['glyphFilter']);
        self::assertFalse($bars['needs']['maximumDisabled']);
        self::assertTrue($bars['wants']['found']);
        self::assertTrue($bars['rent']['found']);
        self::assertTrue($bars['rent']['input']);
        self::assertHardStroke((string) $bars['rent']['nameStroke']);
        self::assertFieldInk($bars['rent']);
        self::assertHardIcon((string) $bars['rent']['glyphFilter']);
        self::assertFieldInkStaysWhiteInDay();
        self::assertTrue($bars['rent']['assignedDisabled']);
        self::assertSame('600.00', $bars['rent']['assignedValue']);
        self::assertFalse($bars['rent']['maximumDisabled']);
        self::assertSame('600.00', $bars['rent']['maximumValue']);
        self::assertSame('1', $bars['rent']['water']);
        self::assertSame('absolute', $bars['needs']['positioned']);
        self::assertGreaterThan(0, (float) $bars['needs']['water']);
        self::assertGreaterThan(8, (float) $bars['needs']['height']);
        self::assertSame(3, $bars['needs']['waves']);
        self::assertSame(0, $bars['wants']['waves']);
        self::assertSame(3, $bars['rent']['waves']);
        self::assertSame('bucket-wave', $bars['needs']['wave']);
        self::assertSame('5s', $bars['needs']['duration']);
        self::assertSame('ease-in-out', $bars['needs']['timing']);
        self::assertNotSame('0px', $bars['needs']['radius']);
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
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '500.00';
        });
        self::click('Previous month');
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
        self::assertSame('0.00', $closed['wants']);
        $disabled = self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
            const input = tile ? tile.querySelector('input') : null;
            return input ? input.disabled : false;
        JS);
        self::assertTrue($disabled);
        self::click('Next month');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '500.00';
        });
        self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
            const input = tile.querySelector('input');
            input.value = '600.00';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Needs 1'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '600.00';
        });
        self::waitForText('$3,953.03');
        self::waitForText($availableText);
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

    private static function assertHardStroke(string $stroke): void
    {
        self::assertMatchesRegularExpression('/^1px rgb\(0,\s*0,\s*0\)$/', $stroke);
    }

    /** @param array<string, mixed> $row */
    private static function assertFieldInk(array $row): void
    {
        self::assertSame('rgb(255, 255, 255)', $row['labelColor']);
        self::assertSame('rgb(255, 255, 255)', $row['inputColor']);
        self::assertSame('rgb(255, 255, 255)', $row['maximumColor']);
        self::assertHardStroke((string) $row['labelStroke']);
        self::assertHardStroke((string) $row['inputStroke']);
        self::assertHardStroke((string) $row['maximumStroke']);
        self::assertSame('none', $row['labelShadow']);
        self::assertSame('none', $row['inputShadow']);
    }

    private static function assertFieldInkStaysWhiteInDay(): void
    {
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('.theme-pill button[aria-label="Day"]'),
        ))->click();
        self::wait()->until(function (): bool {
            return self::driver()->executeScript('return document.documentElement.classList.contains("light")') === true;
        });
        $light = self::fieldInk('Needs 1');
        self::assertSame('1px rgb(255, 255, 255)', $light['nameStroke']);
        self::assertNotSame('rgb(255, 255, 255)', $light['nameColor']);
        self::assertFieldInk($light);
        self::driver()->findElement(WebDriverBy::cssSelector('.theme-pill button[aria-label="Night"]'))->click();
        self::wait()->until(function (): bool {
            return self::driver()->executeScript('return document.documentElement.classList.contains("light")') === false;
        });
    }

    /** @return array<string, string> */
    private static function fieldInk(string $name): array
    {
        $ink = self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const row = [...document.querySelectorAll('.bucket-tile.envelope-tile')].find((node) => node.innerText.includes(name));
            const heading = row ? row.querySelector('strong') : null;
            const label = row ? row.querySelector('.bucket-field > span') : null;
            const assigned = row ? row.querySelector('input[name^="amount-"]') : null;
            const maximum = row ? row.querySelector('input[name^="maximum-"]') : null;
            const read = (node, stroke) => node ? getComputedStyle(node)[stroke ? 'webkitTextStroke' : 'color'] : '';
            return {
                nameStroke: read(heading, true),
                nameColor: read(heading, false),
                labelStroke: read(label, true),
                labelColor: read(label, false),
                labelShadow: label ? getComputedStyle(label).textShadow : 'none',
                inputStroke: read(assigned, true),
                inputColor: read(assigned, false),
                inputShadow: assigned ? getComputedStyle(assigned).textShadow : 'none',
                maximumStroke: read(maximum, true),
                maximumColor: read(maximum, false),
            };
        JS, [$name]);
        self::assertIsArray($ink);

        return array_map(static fn (mixed $value): string => (string) $value, $ink);
    }

    private static function assertHardIcon(string $filter): void
    {
        self::assertStringContainsString('drop-shadow', $filter);
        self::assertMatchesRegularExpression('/rgb\(0,\s*0,\s*0\)/', $filter);
        self::assertDoesNotMatchRegularExpression('/\dpx\s+-?\dpx\s+[1-9]/', $filter);
        self::assertStringNotContainsString('4px', $filter);
        self::assertStringNotContainsString('10px', $filter);
    }

    private static function clickAvailable(): void
    {
        $by = WebDriverBy::cssSelector('[data-action="available"]');
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by))->click();
    }

    private static function clickBackdrop(): void
    {
        $point = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            if (!dialog) return null;
            const rect = dialog.getBoundingClientRect();
            const x = Math.max(1, Math.floor(rect.left / 2));
            const y = Math.max(1, Math.floor(rect.top + rect.height / 2));
            const outside = x < rect.left || x > rect.right || y < rect.top || y > rect.bottom;
            return { x, y, outside };
        JS);
        self::assertIsArray($point);
        self::assertTrue($point['outside'] === true);
        self::driver()->execute('actions', [
            'actions' => [[
                'type' => 'pointer',
                'id' => 'mouse',
                'parameters' => ['pointerType' => 'mouse'],
                'actions' => [
                    [
                        'type' => 'pointerMove',
                        'duration' => 0,
                        'x' => (int) $point['x'],
                        'y' => (int) $point['y'],
                        'origin' => 'viewport',
                    ],
                    ['type' => 'pointerDown', 'button' => 0],
                    ['type' => 'pointerUp', 'button' => 0],
                ],
            ]],
        ]);
    }

    private static function cents(string $money): int
    {
        return (int) str_replace([',', '$', '.'], '', $money);
    }

    private static function money(int $cents): string
    {
        $whole = intdiv($cents, 100);
        $frac = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return '$' . number_format($whole, 0, '.', ',') . '.' . $frac;
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
