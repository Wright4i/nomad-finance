<?php

declare(strict_types=1);

namespace App\Tests\Bill;

use App\Bill\Occurrences;
use PHPUnit\Framework\TestCase;

final class OccurrenceTest extends TestCase
{
    public function testAMonthlyBillAlsoAppearsInLaterMonthsAndAPaidDateIsNotProjected(): void
    {
        $open = $this->bill('2026-11-15', null);

        self::assertSame(
            [['2026-11-15', false]],
            $this->dated(Occurrences::inMonth([$open], '2026-11')),
        );
        self::assertSame(
            [['2026-12-15', false]],
            $this->dated(Occurrences::inMonth([$open], '2026-12')),
        );
        self::assertSame(
            [['2027-01-15', false]],
            $this->dated(Occurrences::inMonth([$open], '2027-01')),
        );

        $settled = $this->bill('2026-12-15', '2026-11-15');

        self::assertSame(
            [['2026-11-15', true]],
            $this->dated(Occurrences::inMonth([$settled], '2026-11')),
        );
        self::assertSame(
            [['2026-12-15', false]],
            $this->dated(Occurrences::inMonth([$settled], '2026-12')),
        );
        self::assertSame(
            [['2027-01-15', false]],
            $this->dated(Occurrences::inMonth([$settled], '2027-01')),
        );
    }

    /** @return array<string, mixed> */
    private function bill(string $next, ?string $paidOn): array
    {
        return [
            'id' => 7,
            'name' => 'Recurring Monthly Bill',
            'type' => 'subscriptions',
            'otherLabel' => null,
            'amountMinor' => 1599,
            'currency' => 'USD',
            'exponent' => 2,
            'recurrence' => 'monthly',
            'anchorDate' => '2026-11-15',
            'nextDueDate' => $next,
            'lastPaidOn' => $paidOn,
            'status' => 'active',
        ];
    }

    /**
     * @param list<array{on:string,paid:bool}> $marks
     * @return list<array{0:string,1:bool}>
     */
    private function dated(array $marks): array
    {
        return array_map(static fn (array $mark): array => [$mark['on'], $mark['paid']], $marks);
    }
}
