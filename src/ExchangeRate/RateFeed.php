<?php

declare(strict_types=1);

namespace App\ExchangeRate;

interface RateFeed
{
    /**
     * Base units per 1 unit of each other currency.
     *
     * @return array<string, string>
     */
    public function quotes(string $base): array;
}
