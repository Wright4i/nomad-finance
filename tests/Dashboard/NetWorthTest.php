<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Dashboard\NetWorth;
use PHPUnit\Framework\TestCase;

final class NetWorthTest extends TestCase
{
    public function testEveryBalanceCountsAsCentsWithNoRate(): void
    {
        $summary = NetWorth::summarize([
            ['id' => 1, 'name' => 'Checking', 'side' => 'asset', 'balanceMinor' => 10000],
            ['id' => 2, 'name' => 'Card', 'side' => 'liability', 'balanceMinor' => 2500],
            ['id' => 3, 'name' => 'Yen cash', 'side' => 'asset', 'balanceMinor' => 1000],
            ['id' => 4, 'name' => 'Euro cash', 'side' => 'asset', 'balanceMinor' => 500],
        ]);

        self::assertSame(9000, $summary['netWorthMinor']);
        self::assertSame(11500, $summary['assetsMinor']);
        self::assertSame(2500, $summary['liabilitiesMinor']);
        self::assertSame([], $summary['unconverted']);
    }

    public function testAStoredMinorAmountIsNotMultipliedByARate(): void
    {
        $summary = NetWorth::summarize([
            ['id' => 1, 'name' => 'Yen', 'side' => 'asset', 'balanceMinor' => 100000],
        ]);

        self::assertSame(100000, $summary['netWorthMinor']);
        self::assertSame([], $summary['unconverted']);
    }

    public function testEmptyBooksAreZero(): void
    {
        $summary = NetWorth::summarize([]);
        self::assertSame(0, $summary['netWorthMinor']);
        self::assertSame([], $summary['unconverted']);
    }
}
