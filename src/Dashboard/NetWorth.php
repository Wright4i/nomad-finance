<?php

declare(strict_types=1);

namespace App\Dashboard;

final class NetWorth
{
    /**
     * @param list<array{id:int|string,name:string,side:string,balanceMinor:int|string}> $accounts
     * @return array{
     */
    public static function summarize(array $accounts): array
    {
        $assets = 0;
        $liabilities = 0;
        foreach ($accounts as $account) {
            $minor = (int) $account['balanceMinor'];
            if ($account['side'] === 'liability') {
                $liabilities += $minor;
            } else {
                $assets += $minor;
            }
        }

        return [
            'netWorthMinor' => $assets - $liabilities,
            'assetsMinor' => $assets,
            'liabilitiesMinor' => $liabilities,
            'unconverted' => [],
        ];
    }
}
