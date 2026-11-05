<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Money\Converter;
use App\Money\CurrencyCatalog;

final class NetWorth
{
    /**
     * @param list<array{id:int|string,name:string,side:string,currency:string,balanceMinor:int|string}> $accounts
     * @param array<string, string> $rates
     * @return array{
     *   netWorthMinor: ?int,
     *   assetsMinor: int,
     *   liabilitiesMinor: int,
     *   unconverted: list<array{accountId:int,name:string,side:string,currency:string,balanceMinor:int}>
     * }
     */
    public static function summarize(string $base, array $accounts, array $rates, CurrencyCatalog $currencies): array
    {
        $baseExponent = $currencies->exponent($base);
        $assets = 0;
        $liabilities = 0;
        $convertible = 0;
        $unconverted = [];

        foreach ($accounts as $account) {
            $currency = (string) $account['currency'];
            $minor = (int) $account['balanceMinor'];
            $converted = null;
            if ($currency === $base) {
                $converted = $minor;
            } elseif (isset($rates[$currency])) {
                $converted = Converter::convert(
                    $minor,
                    $currencies->exponent($currency),
                    $baseExponent,
                    $rates[$currency],
                );
            }

            if ($converted === null) {
                $unconverted[] = [
                    'accountId' => (int) $account['id'],
                    'name' => (string) $account['name'],
                    'side' => (string) $account['side'],
                    'currency' => $currency,
                    'balanceMinor' => $minor,
                ];
                continue;
            }

            $convertible++;
            if ($account['side'] === 'liability') {
                $liabilities += $converted;
            } else {
                $assets += $converted;
            }
        }

        $net = $accounts !== [] && $convertible === 0 ? null : $assets - $liabilities;

        return [
            'netWorthMinor' => $net,
            'assetsMinor' => $assets,
            'liabilitiesMinor' => $liabilities,
            'unconverted' => $unconverted,
        ];
    }
}
