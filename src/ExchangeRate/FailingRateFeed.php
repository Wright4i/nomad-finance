<?php

declare(strict_types=1);

namespace App\ExchangeRate;

final class FailingRateFeed implements RateFeed
{
    public function quotes(string $base): array
    {
        throw new \RuntimeException('Exchange rate feed is off.');
    }
}
