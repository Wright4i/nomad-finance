<?php

declare(strict_types=1);

namespace App\PayCycle;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Support\Dates;
use PDO;

final class RollingAverage
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /**
     * Sum of confirmed inflows in the previous N calendar months, divided by N, half up.
     * A month with no inflow counts as zero. An inflow with no usable rate is left out and named.
     * Inflows outside the window are ignored. This does not assign money.
     *
     * @param list<array{month:string,baseMinor:?int,name:string}> $inflows
     * @param list<string> $months YYYY-MM, including empty months. The length is the divisor.
     * @return array{averageMinor:int,excluded:list<string>}
     */
    public static function rolling(array $inflows, array $months): array
    {
        $window = array_fill_keys($months, 0);
        $excluded = [];
        foreach ($inflows as $inflow) {
            if (!array_key_exists($inflow['month'], $window)) {
                continue;
            }
            if ($inflow['baseMinor'] === null) {
                $excluded[] = $inflow['name'];
                continue;
            }
            $window[$inflow['month']] += $inflow['baseMinor'];
        }
        $count = count($months);
        $sum = array_sum($window);
        if ($count < 1) {
            return ['averageMinor' => 0, 'excluded' => $excluded];
        }

        return [
            'averageMinor' => intdiv($sum + intdiv($count, 2), $count),
            'excluded' => $excluded,
        ];
    }

    /**
     * The saved window for one pay cycle.
     * Null when the cycle's average is off. Postings are gone, so the window has no inflows.
     *
     * @return array{payCycleId:int,months:list<string>,averageMinor:int,excluded:list<string>}|null
     */
    public function preview(int $payCycleId, string $today): ?array
    {
        try {
            $today = Dates::parse($today)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            throw new ApiException(422, 'Check the highlighted fields.', ['today' => 'Use a YYYY-MM-DD date.']);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM pay_cycles WHERE id = ? AND user_id = ?');
        $stmt->execute([$payCycleId, $this->actor->id()]);
        $cycle = $stmt->fetch();
        if (!is_array($cycle)) {
            throw new ApiException(404, 'Pay cycle not found.');
        }
        if ($cycle['average_months'] === null) {
            return null;
        }
        $count = (int) $cycle['average_months'];
        $months = self::previousMonths($today, $count);
        $rolled = self::rolling([], $months);

        return [
            'payCycleId' => $payCycleId,
            'months' => $months,
            'averageMinor' => $rolled['averageMinor'],
            'excluded' => $rolled['excluded'],
        ];
    }

    /** @return list<string> */
    private static function previousMonths(string $today, int $count): array
    {
        $first = Dates::parse($today)->modify('first day of this month');
        $months = [];
        for ($i = $count; $i >= 1; $i--) {
            $months[] = $first->modify('-' . $i . ' months')->format('Y-m');
        }

        return $months;
    }
}
