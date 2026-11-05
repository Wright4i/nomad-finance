<?php

declare(strict_types=1);

namespace App\Money;

final class CurrencyCatalog
{
    /** @var array<string, array{name:string, exponent:int}>|null */
    private static ?array $table = null;

    public function assert(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!isset(self::table()[$code])) {
            throw new UnknownCurrency('Use a three-letter ISO currency code.');
        }

        return $code;
    }

    public function name(string $code): string
    {
        return self::table()[$this->assert($code)]['name'];
    }

    public function label(string $code): string
    {
        $code = $this->assert($code);

        return $code . ' (' . $this->name($code) . ')';
    }

    public function exponent(string $code): int
    {
        return self::table()[$this->assert($code)]['exponent'];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::table());
    }

    /** @return array<string, array{name:string, exponent:int}> */
    private static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $path = dirname(__DIR__, 2) . '/data/currencies.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Currency list is unreadable.');
        }

        $table = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !is_string($row['code'] ?? null) || !is_string($row['name'] ?? null) || !is_int($row['exponent'] ?? null)) {
                throw new \RuntimeException('Currency list is unreadable.');
            }
            $table[$row['code']] = ['name' => $row['name'], 'exponent' => $row['exponent']];
        }

        self::$table = $table;

        return $table;
    }
}
