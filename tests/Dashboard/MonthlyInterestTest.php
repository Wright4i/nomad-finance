<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Dashboard\MonthlyInterest;
use PHPUnit\Framework\TestCase;

final class MonthlyInterestTest extends TestCase
{
    public function testDebtUsesSimpleAprAndSavingsUsesCompoundApy(): void
    {
        $summary = MonthlyInterest::summarize([
            ['type' => 'credit', 'balanceMinor' => 40000, 'interestBps' => 1999],
            ['type' => 'loan', 'balanceMinor' => 500000, 'interestBps' => 699],
            ['type' => 'savings', 'balanceMinor' => 1000000, 'interestBps' => 450],
            ['type' => 'savings', 'balanceMinor' => 20000, 'interestBps' => null],
        ]);

        // 400.00 at 19.99% / 12 is 6.6633..., half up to $6.66.
        // 5000.00 at 6.99% / 12 is 29.125 exactly, half up to $29.13.
        self::assertSame(666 + 2913, $summary['interestLostMinor']);
        // 10000.00 at 4.50% APY. Monthly rate is (1.045)^(1/12) - 1, half up to $36.75.
        self::assertSame(3675, $summary['interestGainedMinor']);
    }

    public function testAHalfCentOfAprRoundsUp(): void
    {
        $summary = MonthlyInterest::summarize([
            ['type' => 'loan', 'balanceMinor' => 500000, 'interestBps' => 699],
        ]);

        self::assertSame(2913, $summary['interestLostMinor']);
        self::assertSame(0, $summary['interestGainedMinor']);
    }

    public function testBlankZeroAndNonPositiveBalancesContributeNothing(): void
    {
        $summary = MonthlyInterest::summarize([
            ['type' => 'credit', 'balanceMinor' => 40000, 'interestBps' => null],
            ['type' => 'loan', 'balanceMinor' => 500000, 'interestBps' => 0],
            ['type' => 'credit', 'balanceMinor' => 0, 'interestBps' => 1999],
            ['type' => 'loan', 'balanceMinor' => -100, 'interestBps' => 699],
            ['type' => 'savings', 'balanceMinor' => 1000000, 'interestBps' => null],
            ['type' => 'savings', 'balanceMinor' => 1000000, 'interestBps' => 0],
            ['type' => 'savings', 'balanceMinor' => 0, 'interestBps' => 450],
            ['type' => 'savings', 'balanceMinor' => -50, 'interestBps' => 450],
        ]);

        self::assertSame(0, $summary['interestLostMinor']);
        self::assertSame(0, $summary['interestGainedMinor']);
    }

    public function testFuelCheckingCashAndOtherStayOut(): void
    {
        $summary = MonthlyInterest::summarize([
            ['type' => 'fuel', 'balanceMinor' => 8000, 'interestBps' => 2499],
            ['type' => 'checking', 'balanceMinor' => 249000, 'interestBps' => 100],
            ['type' => 'cash', 'balanceMinor' => 100000, 'interestBps' => 500],
            ['type' => 'other', 'side' => 'liability', 'balanceMinor' => 12000, 'interestBps' => 1800],
            ['type' => 'other', 'side' => 'asset', 'balanceMinor' => 75000, 'interestBps' => 300],
        ]);

        self::assertSame(0, $summary['interestLostMinor']);
        self::assertSame(0, $summary['interestGainedMinor']);
    }

    public function testEmptyBooksAreZero(): void
    {
        $summary = MonthlyInterest::summarize([]);

        self::assertSame(0, $summary['interestLostMinor']);
        self::assertSame(0, $summary['interestGainedMinor']);
    }
}
