<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Bill\DuePie;
use PHPUnit\Framework\TestCase;

final class DuePieTest extends TestCase
{
    public function testASliceIsTheDueAmountForActiveBillsInThatMonth(): void
    {
        $pies = DuePie::month([
            $this->bill('rent', 'active', '2026-11-10', 8000, 'USD'),
            $this->bill('healthcare', 'active', '2026-11-20', 120000, 'USD'),
            $this->bill('healthcare', 'active', '2026-11-21', 7500, 'USD'),
            $this->bill('lodging', 'active', '2026-11-03', 6400, 'USD'),
            $this->bill('electricity', 'paused', '2026-11-12', 4000, 'USD'),
            $this->bill('healthcare', 'paid', '2026-11-01', 9900, 'USD'),
            $this->bill('debt', 'active', '2026-12-01', 45000, 'USD'),
            $this->bill('gas', 'active', '2026-11-14', 0, 'USD'),
            $this->bill('water', 'active', '2026-11-09', 2800, 'EUR'),
            $this->bill('rent', 'active', '2026-11-11', 1000, 'EUR'),
            $this->bill('gas', 'active', '2026-11-02', 500, 'JPY', 0),
        ], '2026-11');

        self::assertSame([
            [
                'currency' => 'EUR',
                'exponent' => 2,
                'slices' => [
                    ['type' => 'rent', 'amountMinor' => 1000],
                    ['type' => 'water', 'amountMinor' => 2800],
                ],
            ],
            [
                'currency' => 'JPY',
                'exponent' => 0,
                'slices' => [
                    ['type' => 'gas', 'amountMinor' => 500],
                ],
            ],
            [
                'currency' => 'USD',
                'exponent' => 2,
                'slices' => [
                    ['type' => 'lodging', 'amountMinor' => 6400],
                    ['type' => 'rent', 'amountMinor' => 8000],
                    ['type' => 'healthcare', 'amountMinor' => 127500],
                ],
            ],
        ], $pies);
    }

    public function testAnEmptyMonthHasNoPies(): void
    {
        $pies = DuePie::month([
            $this->bill('debt', 'active', '2026-12-01', 45000, 'USD'),
        ], '2026-11');

        self::assertSame([], $pies);
    }

    /** @return array{type:string,status:string,nextDueDate:string,amountMinor:int,currency:string,exponent:int} */
    private function bill(
        string $type,
        string $status,
        string $nextDueDate,
        int $amountMinor,
        string $currency,
        int $exponent = 2,
    ): array {
        return [
            'type' => $type,
            'status' => $status,
            'nextDueDate' => $nextDueDate,
            'amountMinor' => $amountMinor,
            'currency' => $currency,
            'exponent' => $exponent,
        ];
    }
}
