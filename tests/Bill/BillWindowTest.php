<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Bill\BillWindow;
use PHPUnit\Framework\TestCase;

final class BillWindowTest extends TestCase
{
    public function testPaidBillsSortOldestToNewest(): void
    {
        $ordered = BillWindow::order([
            ['id' => 4, 'name' => 'Later same day', 'shownOn' => '2026-11-04', 'amountMinor' => 100],
            ['id' => 2, 'name' => 'November', 'shownOn' => '2026-11-18', 'amountMinor' => 800],
            ['id' => 3, 'name' => 'Earlier same day', 'shownOn' => '2026-11-04', 'amountMinor' => 500],
            ['id' => 1, 'name' => 'September', 'shownOn' => '2026-09-15', 'amountMinor' => 200],
        ]);

        self::assertSame(
            ['September', 'Earlier same day', 'Later same day', 'November'],
            array_column($ordered, 'name'),
        );
        self::assertSame('2026-11-04', BillWindow::shownOn([
            'status' => 'active',
            'recurrence' => 'weekly',
            'nextDueDate' => '2026-11-11',
            'lastPaidOn' => '2026-11-04',
        ], 'paid'));
        self::assertNull(BillWindow::shownOn([
            'status' => 'active',
            'recurrence' => 'once',
            'nextDueDate' => '2026-11-11',
            'lastPaidOn' => null,
        ], 'paid'));
    }

    public function testTheInitialWindowIsAFewDaysAndTheNextIncludesLaterRecords(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Opening', 'shownOn' => '2026-10-01', 'amountMinor' => 100],
            ['id' => 2, 'name' => 'Inside', 'shownOn' => '2026-10-04', 'amountMinor' => 100],
            ['id' => 3, 'name' => 'Next chunk', 'shownOn' => '2026-10-12', 'amountMinor' => 100],
            ['id' => 4, 'name' => 'Much later', 'shownOn' => '2026-11-20', 'amountMinor' => 100],
        ];

        $first = BillWindow::window($rows, 1);
        self::assertSame(['Opening', 'Inside'], array_column($first['rows'], 'name'));
        self::assertTrue($first['hasMore']);
        self::assertSame('2026-10-07', BillWindow::end(['2026-10-01', '2026-10-04', '2026-10-12', '2026-11-20'], 1));

        $next = BillWindow::window($rows, 2);
        self::assertSame(['Opening', 'Inside', 'Next chunk'], array_column($next['rows'], 'name'));
        self::assertNotContains('Much later', array_column($next['rows'], 'name'));
        self::assertTrue($next['hasMore']);

        $rest = BillWindow::window($rows, 3);
        self::assertContains('Much later', array_column($rest['rows'], 'name'));
        self::assertFalse($rest['hasMore']);
    }

    public function testALoneOpeningDateStillShowsTheNextBill(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Only early', 'shownOn' => '2026-10-15', 'amountMinor' => 100],
            ['id' => 2, 'name' => 'Next', 'shownOn' => '2026-11-03', 'amountMinor' => 100],
            ['id' => 3, 'name' => 'Later', 'shownOn' => '2026-11-20', 'amountMinor' => 100],
        ];

        $first = BillWindow::window($rows, 1);
        self::assertSame(['Only early', 'Next'], array_column($first['rows'], 'name'));
        self::assertTrue($first['hasMore']);

        $next = BillWindow::window($rows, 2);
        self::assertContains('Later', array_column($next['rows'], 'name'));
        self::assertSame('Only early', array_column($next['rows'], 'name')[0]);
    }

    public function testPaidOpensOnTheRecentEndAndShowsMoreThanOne(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'September', 'shownOn' => '2026-09-15', 'amountMinor' => 100],
            ['id' => 2, 'name' => 'October', 'shownOn' => '2026-10-01', 'amountMinor' => 100],
            ['id' => 3, 'name' => 'Early November', 'shownOn' => '2026-11-08', 'amountMinor' => 100],
            ['id' => 4, 'name' => 'Recent', 'shownOn' => '2026-11-18', 'amountMinor' => 100],
        ];

        $first = BillWindow::window($rows, 1, true);
        self::assertSame(['Early November', 'Recent'], array_column($first['rows'], 'name'));
        self::assertGreaterThan(1, count($first['rows']));
        self::assertTrue($first['hasMore']);

        $older = BillWindow::window($rows, 2, true);
        $names = array_column($older['rows'], 'name');
        self::assertContains('October', $names);
        self::assertNotContains('September', $names);
        self::assertSame('Recent', $names[array_key_last($names)]);
        self::assertSame($names, array_column(BillWindow::order($older['rows']), 'name'));
        self::assertTrue($older['hasMore']);
    }
}
