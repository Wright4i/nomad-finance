<?php

declare(strict_types=1);

namespace App\Money;

use PDO;

final class RateBaseline
{
    /** @var array{base:string,effectiveDate:string,rates:array<string,string>}|null */
    private static ?array $snapshot = null;

    /** @return array{base:string,effectiveDate:string,rates:array<string,string>} */
    public static function load(): array
    {
        if (self::$snapshot !== null) {
            return self::$snapshot;
        }

        $path = dirname(__DIR__, 2) . '/data/usd-rates.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        if (
            !is_array($decoded)
            || !is_string($decoded['base'] ?? null)
            || !is_string($decoded['effectiveDate'] ?? null)
            || !is_array($decoded['rates'] ?? null)
        ) {
            throw new \RuntimeException('Exchange-rate snapshot is unreadable.');
        }

        $rates = [];
        foreach ($decoded['rates'] as $currency => $rate) {
            if (!is_string($currency) || !is_string($rate)) {
                throw new \RuntimeException('Exchange-rate snapshot is unreadable.');
            }
            $rates[$currency] = $rate;
        }

        self::$snapshot = [
            'base' => $decoded['base'],
            'effectiveDate' => $decoded['effectiveDate'],
            'rates' => $rates,
        ];

        return self::$snapshot;
    }

    /** @return array<string, string> */
    public static function rates(): array
    {
        return self::load()['rates'];
    }

    /**
     * Copy the USD snapshot into one user's books. Another base currency is left empty.
     *
     * @param list<string> $omit
     */
    public static function seed(PDO $pdo, int $userId, string $base, array $omit, string $now): void
    {
        $snapshot = self::load();
        if ($base !== $snapshot['base']) {
            return;
        }

        $skip = array_map(strtoupper(...), $omit);
        $stmt = $pdo->prepare(
            'INSERT INTO exchange_rates (user_id, base_currency, currency, rate, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($snapshot['rates'] as $currency => $rate) {
            if (in_array($currency, $skip, true)) {
                continue;
            }
            $stmt->execute([$userId, $base, $currency, $rate, $now, $now]);
        }
    }
}
