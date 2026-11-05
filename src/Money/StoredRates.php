<?php

declare(strict_types=1);

namespace App\Money;

use PDO;

/** Rates already stored for a user. A lookup does not ask the network for a new quote. */
final class StoredRates
{
    /** @return array<string, string> */
    public static function map(PDO $pdo, int $userId, string $base): array
    {
        $stmt = $pdo->prepare(
            'SELECT currency, rate FROM exchange_rates WHERE user_id = ? AND base_currency = ?'
        );
        $stmt->execute([$userId, $base]);
        $rates = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rates[(string) $row['currency']] = (string) $row['rate'];
        }

        $quotes = $pdo->prepare(
            'SELECT currency, rate, fetched_at FROM rate_quotes WHERE user_id = ? AND base_currency = ?'
        );
        $quotes->execute([$userId, $base]);
        $cutoff = gmdate('c', time() - 86400);
        while ($row = $quotes->fetch()) {
            if (!is_array($row)) {
                break;
            }
            if ((string) $row['fetched_at'] < $cutoff) {
                continue;
            }
            $rates[(string) $row['currency']] = (string) $row['rate'];
        }

        return $rates;
    }

    /**
     * @param array<string, string> $rates
     */
    public static function toBase(int $minor, string $currency, int $exponent, string $base, int $baseExponent, array $rates): ?int
    {
        if ($currency === $base) {
            return Converter::convert($minor, $exponent, $baseExponent, '1');
        }
        if (!isset($rates[$currency])) {
            return null;
        }

        return Converter::convert($minor, $exponent, $baseExponent, $rates[$currency]);
    }
}
