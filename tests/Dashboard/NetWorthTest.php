<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Dashboard\NetWorth;
use App\Money\CurrencyCatalog;
use PHPUnit\Framework\TestCase;

final class NetWorthTest extends TestCase
{
    public function testSubtractsLiabilitiesAndSkipsMissingRates(): void
    {
        $summary = NetWorth::summarize('USD', [
            ['id' => 1, 'name' => 'Checking', 'side' => 'asset', 'currency' => 'USD', 'balanceMinor' => 10000],
            ['id' => 2, 'name' => 'Card', 'side' => 'liability', 'currency' => 'USD', 'balanceMinor' => 2500],
            ['id' => 3, 'name' => 'Yen cash', 'side' => 'asset', 'currency' => 'JPY', 'balanceMinor' => 1000],
            ['id' => 4, 'name' => 'Euro cash', 'side' => 'asset', 'currency' => 'EUR', 'balanceMinor' => 500],
        ], ['JPY' => '0.0067'], new CurrencyCatalog());

        self::assertSame(8170, $summary['netWorthMinor']);
        self::assertSame(10670, $summary['assetsMinor']);
        self::assertSame(2500, $summary['liabilitiesMinor']);
        self::assertSame('Euro cash', $summary['unconverted'][0]['name']);
        self::assertCount(1, $summary['unconverted']);
    }

    public function testEmptyBooksAreZero(): void
    {
        $summary = NetWorth::summarize('USD', [], [], new CurrencyCatalog());
        self::assertSame(0, $summary['netWorthMinor']);
    }

    public function testAllMissingRatesLeaveNetWorthUnset(): void
    {
        $summary = NetWorth::summarize('USD', [
            ['id' => 1, 'name' => 'Yen', 'side' => 'asset', 'currency' => 'JPY', 'balanceMinor' => 100],
        ], [], new CurrencyCatalog());
        self::assertNull($summary['netWorthMinor']);
    }
}
