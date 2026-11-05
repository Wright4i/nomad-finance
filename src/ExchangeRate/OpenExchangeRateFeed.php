<?php

declare(strict_types=1);

namespace App\ExchangeRate;

final class OpenExchangeRateFeed implements RateFeed
{
    private const ENDPOINT = 'https://open.er-api.com/v6/latest/';

    public function quotes(string $base): array
    {
        $base = strtoupper($base);
        if (!preg_match('/^[A-Z]{3}$/', $base)) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        return RateFeedParser::quotes($this->fetch(self::ENDPOINT . $base), $base);
    }

    private function fetch(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 4);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'User-Agent: Nomad-finance']);
        $body = curl_exec($curl);
        unset($curl);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Exchange rate feed is unreadable.');
        }

        return $body;
    }
}
