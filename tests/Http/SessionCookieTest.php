<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SessionMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SessionCookieTest extends TestCase
{
    public function testHttpsCookieStaysInsideACrossSiteFrame(): void
    {
        self::assertSame('None', SessionMiddleware::params(true)['samesite']);
        self::assertTrue(SessionMiddleware::params(true)['secure']);
        $cookie = SessionMiddleware::setCookie('abc', true);
        self::assertSame(
            'nomad_finance_session=abc; Path=/; Secure; HttpOnly; SameSite=None; Partitioned',
            $cookie,
        );
    }

    public function testHttpCookieStaysLax(): void
    {
        self::assertSame('Lax', SessionMiddleware::params(false)['samesite']);
        self::assertFalse(SessionMiddleware::params(false)['secure']);
        self::assertSame(
            'nomad_finance_session=abc; Path=/; HttpOnly; SameSite=Lax',
            SessionMiddleware::setCookie('abc', false),
        );
    }

    public function testProxiedHttpsCountsAsSecure(): void
    {
        $factory = new ServerRequestFactory();
        $plain = $factory->createServerRequest('GET', 'http://127.0.0.1:8080/');
        self::assertFalse(SessionMiddleware::https($plain));
        $forwarded = $plain->withHeader('X-Forwarded-Proto', 'https');
        self::assertTrue(SessionMiddleware::https($forwarded));
        $visitor = $plain->withHeader('CF-Visitor', '{"scheme":"https"}');
        self::assertTrue(SessionMiddleware::https($visitor));
    }

    public function testLogoutExpiresThePartitionedCookie(): void
    {
        self::assertSame(
            'nomad_finance_session=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Secure; HttpOnly; SameSite=None; Partitioned',
            SessionMiddleware::setCookie('', true, true),
        );
    }
}
