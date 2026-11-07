<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
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
                assign: [...envelopes.querySelectorAll('a, button')].some((node) => (node.textContent || '').trim() === 'Assign'),
                squares: monthButtons.length,
                monthButtons,
            };
        JS);
        self::assertIsArray($planner);
        self::assertTrue($planner['needsBeforeAvailable']);
        self::assertSame(3, $planner['gauges']);
        self::assertEqualGaugeRow();
        self::assertStringNotContainsString('Previous period', $planner['envelopeText']);
        self::assertStringNotContainsString('Next period', $planner['envelopeText']);
        self::assertStringNotContainsString('Open period', $planner['envelopeText']);
        self::assertStringNotContainsString('Cash 3', $planner['envelopeText']);
        self::assertMatchesRegularExpression('/Available to assign\s+-?\$[\d,]+\.\d{2}/', $planner['envelopeText']);
        self::assertStringContainsString('assigned of', $planner['envelopeText']);
        preg_match('/Available to assign\s+(-?\$[\d,]+\.\d{2})/', $planner['envelopeText'], $availableMatch);
        $availableText = $availableMatch[1];
        self::assertSame(1, $planner['pies']);
        self::assertStringContainsString('rent', $planner['rentText']);
        self::assertStringContainsString('$805.00', $planner['rentText']);
        self::assertStringContainsString('$127.20', $planner['billText']);
        self::assertStringNotContainsString('$65.00', $planner['billText']);
        self::assertStringContainsString('$2,300.00', $planner['billText']);
        self::assertStringContainsString('debt', $planner['billText']);
        self::assertStringNotContainsString('entertainment', $planner['billText']);
        self::assertStringNotContainsString('Site week', $planner['billText']);
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
                && str_contains($legend, '$80.00')
                && str_contains($legend, 'rent');
        });
        self::click('Current Month');
        self::waitForText('$805.00');
        self::clickLink('Budget');
        self::waitForText('Available to assign');
        self::waitForText($availableText);
        self::waitForText('Road cushion');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '115.00';
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
                    auto: figure('auto'),
                    envelope: figure('envelope'),
                    target: figure('target'),
                    goal: figure('goal'),
                    goalOver: heading ? heading.querySelector('[data-figure="goal"]')?.classList.contains('over') === true : false,
                    beside: Boolean(labelBox && sumsBox && sumsBox.left >= labelBox.right - 4 && Math.abs(sumsBox.top - labelBox.top) < 24),
                };
            };
            return { needs: read('needs'), wants: read('wants'), savings: read('savings') };
        JS);
        self::assertIsArray($headings);
        foreach (['needs', 'wants', 'savings'] as $group) {
            self::assertIsArray($headings[$group]);
            self::assertStringStartsWith('AUTO-ASSIGNED $', $headings[$group]['auto']);
            self::assertStringStartsWith('ENVELOPE $', $headings[$group]['envelope']);
            self::assertStringStartsWith('TARGET $', $headings[$group]['target']);
            self::assertStringNotContainsString('USD', $headings[$group]['auto'] . $headings[$group]['envelope'] . $headings[$group]['target'] . $headings[$group]['goal']);
            self::assertTrue($headings[$group]['beside']);
        }
        $hero = self::driver()->executeScript(<<<'JS'
            const figure = document.querySelector('[data-action="available"] .figure');
            const line = document.querySelector('[data-action="available"]')?.parentElement?.querySelector('p.muted');
            const select = document.querySelector('select[name="payCycleId"]');
            return {
                text: figure ? figure.innerText.trim() : '',
                of: line ? line.innerText.trim() : '',
                payor: Boolean(select),
            };
        JS);
        self::assertIsArray($hero);
        self::assertFalse($hero['payor']);
        self::assertSame($availableText, $hero['text']);
        self::assertMatchesRegularExpression('/assigned of\s+\$[\d,]+\.\d{2}/', $hero['of']);
        preg_match('/assigned of\s+(\$[\d,]+\.\d{2})/', $hero['of'], $ofMatch);
        $ofCents = self::cents($ofMatch[1]);
        self::assertGreaterThan(self::cents($hero['text']), $ofCents);
        self::assertSame('(50% goal - ' . self::money(intdiv($ofCents * 50 + 50, 100)) . ')', $headings['needs']['goal']);
        self::assertSame('(30% goal - ' . self::money(intdiv($ofCents * 30 + 50, 100)) . ')', $headings['wants']['goal']);
        self::assertSame('(20% goal - ' . self::money(intdiv($ofCents * 20 + 50, 100)) . ')', $headings['savings']['goal']);
        self::clickAvailable();
        $sources = self::driver()->executeScript(<<<'JS'
            const dialog = document.querySelector('dialog.modal[open]');
            if (!dialog) return null;
            const income = dialog.querySelector('[data-source="income"]');
            const accounts = dialog.querySelector('[data-source="accounts"]');
            const checking = dialog.querySelector('[data-type="checking"] .glyph');
            const cash = dialog.querySelector('[data-type="cash"] .glyph');
            const floats = dialog.querySelector('[data-source="floats"]');
            return {
                income: income ? income.innerText : '',
                accounts: accounts ? accounts.innerText : '',
                floats: floats ? floats.innerText : '',
                incomeGlyph: Boolean(income && income.querySelector('.glyph')),
                accountGlyph: Boolean(accounts && accounts.querySelector('.glyph')),
                checking: checking ? getComputedStyle(checking).color : '',
                cash: cash ? getComputedStyle(cash).color : '',
            };
        JS);
        self::assertIsArray($sources);
        self::assertStringContainsString('Camp host', $sources['income']);
        self::assertMatchesRegularExpression('/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{1,2}/', $sources['income']);
        self::assertStringContainsString('$', $sources['income']);
        self::assertStringNotContainsString('covered', strtolower($sources['income'] . $sources['accounts'] . $sources['floats']));
        self::assertStringNotContainsString('uncovered', strtolower($sources['floats']));
        self::assertDoesNotMatchRegularExpression('/\$0\.00/', $sources['floats']);
        self::assertStringContainsString('Everyday checking', $sources['accounts']);
        self::assertStringNotContainsString('Savings', $sources['accounts']);
        self::assertStringContainsString('$', $sources['accounts']);
        self::assertTrue($sources['incomeGlyph']);
        self::assertTrue($sources['accountGlyph']);
        self::assertNotSame('', $sources['checking']);
        self::assertNotSame($sources['checking'], $sources['cash']);
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
                const maximum = row ? row.querySelector('[data-field="target"]') : null;
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
            return { needs: read('Road cushion'), wants: read('Fun money'), rent: read('Rent') };
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
        self::assertTrue($bars['wants']['found']);
        self::assertTrue($bars['rent']['found']);
        self::assertTrue($bars['rent']['input']);
        self::assertHardStroke((string) $bars['rent']['nameStroke']);
        self::assertFieldInk($bars['rent']);
        self::assertHardIcon((string) $bars['rent']['glyphFilter']);
        self::assertFieldInkStaysWhiteInDay();
        self::assertFalse($bars['rent']['assignedDisabled']);
        self::assertSame('0.00', $bars['rent']['assignedValue']);
        self::assertSame('1', $bars['rent']['water']);
        self::assertSame('absolute', $bars['needs']['positioned']);
        self::assertGreaterThan(0, (float) $bars['needs']['water']);
        self::assertGreaterThan(8, (float) $bars['needs']['height']);
        self::assertSame(3, $bars['needs']['waves']);
        self::assertSame(0, $bars['wants']['waves']);
        self::assertSame(3, $bars['rent']['waves']);
        self::assertEqualBucketRows();
        self::assertSame('bucket-wave', $bars['needs']['wave']);
        self::assertSame('5s', $bars['needs']['duration']);
        self::assertSame('ease-in-out', $bars['needs']['timing']);
        self::assertNotSame('0px', $bars['needs']['radius']);
        self::waitForText('Nov 2026');
        self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => (node.querySelector('strong')?.innerText || '') === 'Rent');
            const input = tile ? tile.querySelector('input') : null;
            input.value = '25.00';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => (node.querySelector('strong')?.innerText || '') === 'Rent');
                const target = tile ? tile.querySelector('[data-field="target"]') : null;
                return target ? target.innerText : '';
            JS);

            return $ready === '$865.00';
        });
        self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => (node.querySelector('strong')?.innerText || '') === 'Rent');
            const input = tile ? tile.querySelector('input') : null;
            input.value = '0.00';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        self::click('Next month');
        self::waitForText('Dec 2026');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '0.00';
        });
        self::waitForText('when this month opens');
        self::typeAssigned('Road cushion', '700.00');
        $started = microtime(true);
        self::wait()->until(function () use ($started): bool {
            if (microtime(true) - $started < 1.3) {
                return false;
            }
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input') : null;
                return input ? input.value : '';
            JS);

            return $value === '700.00';
        });
        self::click('Previous month');
        self::waitForText('Nov 2026');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '115.00';
        });
        self::click('Previous month');
        self::waitForText('A closed month can be read.');
        $closed = self::driver()->executeScript(<<<'JS'
            const value = (name) => {
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes(name));
                const input = tile ? tile.querySelector('input') : null;
                return input ? input.value : '';
            };
            return { needs: value('Road cushion'), wants: value('Fun money') };
        JS);
        self::assertSame('200.00', $closed['needs']);
        self::assertSame('0.00', $closed['wants']);
        $disabled = self::driver()->executeScript(<<<'JS'
            const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
            const input = tile ? tile.querySelector('input') : null;
            return input ? input.disabled : false;
        JS);
        self::assertTrue($disabled);
        self::click('Next month');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '115.00';
        });
        $assignedBefore = self::assignedCents();
        self::typeAssigned('Road cushion', '215.00');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '215.00';
        });
        $raised = self::money($assignedBefore + 10000);
        self::waitForText($raised);
        $left = self::money(self::cents($availableText) - 10000);
        self::waitForText($left);
        self::clickLink('Planner');
        self::waitForText($left);
        self::waitForText($raised . ' assigned of');
        self::clickLink('Bills');
        Reveal::bill(self::driver(), 'Site week', 'active');
        self::clickLinkContaining('Site week');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const name = document.querySelector('input[name="name"]');
                const select = document.querySelector('select[name="categoryId"]');
                const label = select && select.selectedOptions[0] ? select.selectedOptions[0].textContent.trim() : '';
                return name && name.value === 'Site week' && label === 'Road cushion';
            JS);

            return $ready === true;
        });
        self::clickLink('Budget');
        self::waitForText('Recalculate');
        self::click('Recalculate');
        self::waitForText('Recalculate this month?');
        self::waitForText('Amounts you typed are replaced.');
        self::click('Cancel');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile.envelope-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                const dialog = document.getElementById('recalculate-title');
                return input && input.value === '215.00' && dialog instanceof HTMLElement && !dialog.closest('dialog')?.open
                    ? input.value
                    : '';
            JS);

            return $value === '215.00';
        });
        self::click('Recalculate');
        self::click('Recalculate this month');
        self::wait()->until(function (): bool {
            $value = self::driver()->executeScript(<<<'JS'
                const tile = [...document.querySelectorAll('.bucket-tile.envelope-tile')].find((node) => node.innerText.includes('Road cushion'));
                const input = tile ? tile.querySelector('input[name^="amount-"]') : null;
                return input ? input.value : '';
            JS);

            return $value === '0.00';
        });
        self::click('Previous month');
        self::wait()->until(function (): bool {
            $disabled = self::driver()->executeScript(
                'const button = document.querySelector(\'[data-action="recalculate"]\'); return button instanceof HTMLButtonElement && button.disabled;',
            );

            return $disabled === true;
        });
        self::click('Next month');
        self::clickLink('Lodging');
        self::wait()->until(function (): bool {
            $ready = self::driver()->executeScript(<<<'JS'
                const type = document.querySelector('select[name="billType"]');
                return location.pathname === '/bills'
                    && new URLSearchParams(location.search).get('type') === 'lodging'
                    && type instanceof HTMLSelectElement
                    && type.value === 'lodging';
            JS);

            return $ready === true;
        });
        self::driver()->get(self::$base . '/accounts/new');
        self::waitForText('Include in Budget');
        self::assertTrue(self::driver()->findElement(WebDriverBy::name('inBudget'))->isSelected());
        self::click('savings');
        self::assertFalse(self::driver()->findElement(WebDriverBy::name('inBudget'))->isSelected());
        self::click('credit');
        self::assertCount(0, self::driver()->findElements(WebDriverBy::name('inBudget')));
        self::clickLink('Accounts');
        self::waitForText('Travel card');
        self::clickLinkContaining('Travel card');
        self::waitForText('Autopay is on at the bank');
        self::assertCount(0, self::driver()->findElements(WebDriverBy::name('inBudget')));
        self::clickLink('Accounts');
        self::clickLinkContaining('Emergency fund');
        self::wait()->until(function (): bool {
            $name = self::driver()->findElements(WebDriverBy::name('name'));

            return $name !== [] && $name[0]->getAttribute('value') === 'Emergency fund';
        });
        $box = self::driver()->findElement(WebDriverBy::name('inBudget'));
        self::assertFalse($box->isSelected());
        $box->click();
        self::click('Save account');
        self::waitForText('Everyday checking');
        self::clickLink('Budget');
        self::waitForText('Available to assign');
        self::clickAvailable();
        self::wait()->until(function (): bool {
            $accounts = self::driver()->executeScript(<<<'JS'
                const dialog = document.querySelector('dialog.modal[open] [data-source="accounts"]');
                return dialog ? dialog.innerText : '';
            JS);

            return is_string($accounts) && str_contains($accounts, 'Emergency fund');
        });
    }

    private static function assertOverageFooter(string $name, string $text): void
    {
        self::assertEqualBucketRows();
        $night = self::overageFooter($name);
        self::assertFooterOutside($night, $text);
        self::wait()->until(WebDriverExpectedCondition::elementToBeClickable(
            WebDriverBy::cssSelector('.theme-pill button[aria-label="Day"]'),
        ))->click();
        self::wait()->until(function (): bool {
            return self::driver()->executeScript('return document.documentElement.classList.contains("light")') === true;
        });
        $day = self::overageFooter($name);
        self::assertFooterOutside($day, $text);
        self::assertSame($night['background'], $day['background']);
        self::assertSame($night['color'], $day['color']);
        self::driver()->findElement(WebDriverBy::cssSelector('.theme-pill button[aria-label="Night"]'))->click();
        self::wait()->until(function (): bool {
            return self::driver()->executeScript('return document.documentElement.classList.contains("light")') === false;
        });
    }

    private static function assertEqualGaugeRow(): void
    {
        $rows = self::tileRows('.planner-gauges .want-gauge', '.want-gauge-readout, .want-gauge-meter, .want-gauge-name, .want-gauge-line, .want-gauge-warning');
        self::assertMatchedRows($rows);
    }

    private static function assertEqualBucketRows(): void
    {
        $rows = self::tileRows('.bucket-board .bucket-tile.envelope-tile', 'strong, .bucket-field, em, input');
        self::assertMatchedRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function assertMatchedRows(array $rows): void
    {
        $matched = false;
        foreach ($rows as $row) {
            self::assertFalse($row['clipped']);
            if ((int) $row['count'] >= 2) {
                $matched = true;
                self::assertLessThan(2, (float) $row['spread']);
            }
        }
        self::assertTrue($matched);
    }

    /** @return list<array<string, mixed>> */
    private static function tileRows(string $tileSelector, string $partSelector): array
    {
        $rows = self::driver()->executeScript(<<<'JS'
            const tileSelector = arguments[0];
            const partSelector = arguments[1];
            const tiles = [...document.querySelectorAll(tileSelector)];
            const grouped = [];
            for (const tile of tiles) {
                const rect = tile.getBoundingClientRect();
                const clipped = [...tile.querySelectorAll(partSelector)].some((node) => {
                    const box = node.getBoundingClientRect();
                    if (box.height < 1 || box.width < 1) return true;
                    if (box.top < rect.top - 1 || box.bottom > rect.bottom + 1) return true;
                    if (box.left < rect.left - 1 || box.right > rect.right + 1) return true;
                    if (node.tagName === 'INPUT') return false;
                    return node.scrollHeight > node.clientHeight + 3 || node.scrollWidth > node.clientWidth + 3;
                });
                const hit = grouped.find((item) => Math.abs(item.top - rect.top) < 2);
                const entry = { height: rect.height, clipped };
                if (hit) hit.tiles.push(entry);
                else grouped.push({ top: rect.top, tiles: [entry] });
            }
            return grouped.map((row) => {
                const heights = row.tiles.map((tile) => tile.height);
                return {
                    count: row.tiles.length,
                    spread: Math.max(...heights) - Math.min(...heights),
                    clipped: row.tiles.some((tile) => tile.clipped),
                };
            });
        JS, [$tileSelector, $partSelector]);
        self::assertIsArray($rows);

        return array_values(array_map(static function (mixed $row): array {
            self::assertIsArray($row);

            return $row;
        }, $rows));
    }

    /** @param array<string, mixed> $row */
    private static function assertFooterOutside(array $row, string $text): void
    {
        self::assertSame($text, $row['text']);
        self::assertFalse($row['inside']);
        self::assertStringNotContainsString('Over by', (string) $row['tileText']);
        self::assertSame('absolute', $row['position']);
        self::assertGreaterThan((float) $row['tileBottom'], (float) $row['footerTop']);
        self::assertSame('rgb(255, 255, 255)', $row['color']);
        self::assertSame('rgb(208, 18, 42)', $row['background']);
        self::assertTrue($row['rowAligned']);
        self::assertGreaterThan(1, (int) $row['rowCount']);
        self::assertNotNull($row['belowTop']);
        self::assertGreaterThanOrEqual((float) $row['footerBottom'] - 0.5, (float) $row['belowTop']);
        self::assertGreaterThan((float) $row['columnGap'], (float) $row['rowGap']);
        self::assertGreaterThan((float) $row['footerBottom'] - (float) $row['tileBottom'], (float) $row['rowGap']);
        self::assertFalse($row['otherFooter']);
        self::assertEqualsWithDelta((float) $row['otherHeight'], (float) $row['tileHeight'], 2.0);
        self::assertLessThan(2, (float) $row['rowSpread']);
    }

    /** @return array<string, mixed> */
    private static function overageFooter(string $name): array
    {
        $row = self::driver()->executeScript(<<<'JS'
            const name = arguments[0];
            const paint = (value) => {
                const match = String(value).match(/rgba?\(([^)]+)\)/);
                if (!match) return String(value);
                const parts = match[1].split(',').map((part) => part.trim());
                return `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`;
            };
            const tile = [...document.querySelectorAll('.bucket-tile.envelope-tile')].find((node) => (node.querySelector('strong')?.innerText || '') === name);
            const footer = tile && tile.parentElement ? tile.parentElement.querySelector('.bucket-overage') : null;
            const tileBox = tile ? tile.getBoundingClientRect() : null;
            const footerBox = footer ? footer.getBoundingClientRect() : null;
            const footerStyle = footer ? getComputedStyle(footer) : null;
            const board = tile ? tile.closest('.bucket-board') : null;
            const boardStyle = board ? getComputedStyle(board) : null;
            const tiles = board ? [...board.querySelectorAll('.bucket-tile.envelope-tile')] : [];
            const row = tileBox ? tiles.filter((node) => Math.abs(node.getBoundingClientRect().top - tileBox.top) < 2) : [];
            const below = tileBox ? tiles
                .map((node) => node.getBoundingClientRect())
                .filter((box) => box.top > tileBox.bottom - 1 && Math.abs(box.left - tileBox.left) < 4)
                .sort((left, right) => left.top - right.top)[0] : null;
            const rowPeers = row.filter((node) => node !== tile && !node.classList.contains('over'));
            const other = rowPeers[0] || null;
            const otherBox = other ? other.getBoundingClientRect() : null;
            return {
                text: footer ? footer.innerText.replace(/\s+/g, ' ').trim() : '',
                inside: Boolean(footer && tile && tile.contains(footer)),
                tileText: tile ? tile.innerText : '',
                footerTop: footerBox ? footerBox.top : 0,
                footerBottom: footerBox ? footerBox.bottom : 0,
                tileBottom: tileBox ? tileBox.bottom : 0,
                tileHeight: tileBox ? tileBox.height : 0,
                otherHeight: otherBox ? otherBox.height : 0,
                rowCount: row.length,
                rowSpread: (() => {
                    const heights = row.map((node) => node.getBoundingClientRect().height);
                    return heights.length === 0 ? 0 : Math.max(...heights) - Math.min(...heights);
                })(),
                rowAligned: row.every((node) => Math.abs(node.getBoundingClientRect().top - tileBox.top) < 2),
                belowTop: below ? below.top : null,
                rowGap: boardStyle ? parseFloat(boardStyle.rowGap) : 0,
                columnGap: boardStyle ? parseFloat(boardStyle.columnGap) : 0,
                color: footerStyle ? paint(footerStyle.color) : '',
                background: footerStyle ? paint(footerStyle.backgroundColor) : '',
                position: footerStyle ? footerStyle.position : '',
                otherFooter: Boolean(other && other.parentElement && other.parentElement.querySelector('.bucket-overage')),
            };
        JS, [$name]);
        self::assertIsArray($row);

        return $row;
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
        $light = self::fieldInk('Road cushion');
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
            const maximum = row ? row.querySelector('[data-field="target"]') : null;
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
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $frac = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return $sign . '$' . number_format($whole, 0, '.', ',') . '.' . $frac;
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

    private static function assignedCents(): int
    {
        $line = self::driver()->executeScript(<<<'JS'
            const line = document.querySelector('[data-action="available"]')?.parentElement?.innerText || '';
            const match = line.match(/(\$[\d,]+\.\d{2})\s+assigned of/);
            return match ? match[1] : '';
        JS);
        self::assertIsString($line);
        self::assertNotSame('', $line);

        return self::cents($line);
    }

    private static function typeAssigned(string $name, string $value): void
    {
        $by = WebDriverBy::cssSelector('input[aria-label="Envelope ' . $name . '"]');
        $input = self::wait()->until(WebDriverExpectedCondition::elementToBeClickable($by));
        self::driver()->executeScript('arguments[0].focus(); arguments[0].select();', [$input]);
        $input->sendKeys($value);
        $input->sendKeys(WebDriverKeys::ENTER);
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
