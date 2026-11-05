<?php

declare(strict_types=1);

namespace App\ExchangeRate;

final class FileRateFeed implements RateFeed
{
    public function __construct(private readonly string $path)
    {
    }

    public function quotes(string $base): array
    {
        $body = file_get_contents($this->path);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        return RateFeedParser::quotes($body, $base);
    }
}
