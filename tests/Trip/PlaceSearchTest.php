<?php

declare(strict_types=1);

namespace App\Tests\Trip;

use App\Trip\PlaceSearch;
use PHPUnit\Framework\TestCase;

final class PlaceSearchTest extends TestCase
{
    public function testStaySearchAsksNominatimForEnglish(): void
    {
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', RecordingHttps::class);
        RecordingHttps::$url = '';
        try {
            (new PlaceSearch())->search('osaka, jp');
            $url = RecordingHttps::$url;
        } finally {
            stream_wrapper_restore('https');
        }

        $query = parse_url($url, PHP_URL_QUERY);
        $params = [];
        parse_str(is_string($query) ? $query : '', $params);

        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('nominatim.openstreetmap.org', parse_url($url, PHP_URL_HOST));
        self::assertSame('/search', parse_url($url, PHP_URL_PATH));
        self::assertSame('en', $params['accept-language'] ?? null);
        self::assertSame('jsonv2', $params['format'] ?? null);
        self::assertSame('1', $params['addressdetails'] ?? null);
        self::assertSame('5', $params['limit'] ?? null);
        self::assertSame('osaka, jp', $params['q'] ?? null);
        self::assertSame(
            ['format', 'addressdetails', 'limit', 'accept-language', 'q'],
            array_keys($params),
        );
    }
}

final class RecordingHttps
{
    public static string $url = '';

    /** @var resource|null */
    public $context;

    private bool $done = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        self::$url = $path;

        return true;
    }

    public function stream_read(int $count): string
    {
        $this->done = true;

        return '';
    }

    public function stream_eof(): bool
    {
        return $this->done;
    }

    /** @return array<int, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
