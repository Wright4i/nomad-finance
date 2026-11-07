<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\WebDriverWait;

/** Scrolls a bill section until a named bill is on screen. Later days load at the end. */
final class Reveal
{
    public static function bill(ChromeDriver $driver, string $name, ?string $section = null): void
    {
        $found = (new WebDriverWait($driver, 45))->until(function () use ($driver, $name, $section): bool {
            $ready = $driver->executeScript(<<<'JS'
                const name = arguments[0];
                const section = arguments[1];
                const root = section ? document.querySelector(`section[data-section="${section}"]`) : document;
                if (!root) return false;
                if (section && root.hasAttribute('hidden')) {
                    const tab = document.querySelector(`[role="tab"][data-section="${section}"]`);
                    if (tab) tab.click();
                    return false;
                }
                const named = (node) => {
                    const strong = node.querySelector('strong');
                    const text = (strong ? strong.textContent : node.textContent).replace(/\s+/g, ' ').trim();
                    return text === name || text.startsWith(name + ' ');
                };
                const hit = [...root.querySelectorAll('article, a')].find(named);
                if (hit) {
                    const scroller = hit.closest('.bill-scroll');
                    if (scroller) {
                        const box = hit.getBoundingClientRect();
                        const frame = scroller.getBoundingClientRect();
                        if (box.top < frame.top + 4 || box.bottom > frame.bottom - 4) {
                            scroller.scrollTop += box.top - frame.top - 16;
                        }
                    }
                    hit.scrollIntoView({ block: 'center', inline: 'nearest' });
                    if (root.dataset.revealMark !== name) {
                        root.dataset.revealMark = name;
                        return false;
                    }
                    return true;
                }
                root.dataset.revealMark = '';
                const scrollers = section
                    ? [...root.querySelectorAll('.bill-scroll')]
                    : [...document.querySelectorAll('.bill-scroll')];
                scrollers.forEach((scroller) => {
                    scroller.scrollTop = scroller.scrollHeight;
                    const wheel = new WheelEvent('wheel', { deltaY: 240, bubbles: true });
                    scroller.dispatchEvent(wheel);
                    scroller.dispatchEvent(new Event('scroll', { bubbles: true }));
                    const pointed = { currentTarget: scroller, target: scroller, deltaY: 240 };
                    for (const symbol of Object.getOwnPropertySymbols(scroller)) {
                        const map = scroller[symbol];
                        if (!map) continue;
                        if (typeof map.wheel === 'function') map.wheel.call(scroller, pointed);
                        if (typeof map.scroll === 'function') map.scroll.call(scroller, pointed);
                    }
                });
                return false;
            JS, [$name, $section]);

            return $ready === true;
        });
        if ($found !== true) {
            throw new \RuntimeException('Could not reveal ' . $name);
        }
    }
}
