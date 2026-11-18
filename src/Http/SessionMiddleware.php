<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = $request->getUri()->getScheme() === 'https';
            session_name('nomad_finance_session');
            session_set_cookie_params(self::params($secure));
            session_start();
            if ($secure && session_id() !== '') {
                header_remove('Set-Cookie');
                header('Set-Cookie: ' . self::setCookie(session_id(), true), false);
            }
        }

        return $handler->handle($request);
    }

    /** @return array{lifetime:int,path:string,secure:bool,httponly:bool,samesite:string} */
    public static function params(bool $secure): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            // Lax is withheld from a cross-site iframe, so the form token and the
            // next request land in different sessions. None plus Partitioned keeps
            // one session inside the frame on HTTPS. HTTP stays Lax for local tests.
            'samesite' => $secure ? 'None' : 'Lax',
        ];
    }

    public static function expireCookie(): void
    {
        $params = session_get_cookie_params();
        $name = session_name();
        $same = (string) ($params['samesite'] ?? 'Lax');
        if ($same === '') {
            $same = 'Lax';
        }
        setcookie($name, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'domain' => (string) $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $same,
        ]);
        if ($same === 'None') {
            header('Set-Cookie: ' . self::setCookie('', true, true), false);
        }
    }

    public static function setCookie(string $id, bool $secure, bool $expired = false): string
    {
        $name = 'nomad_finance_session';
        $body = $name . '=' . $id . '; Path=/';
        if ($expired) {
            $body .= '; Expires=Thu, 01 Jan 1970 00:00:01 GMT';
        }
        if ($secure) {
            return $body . '; Secure; HttpOnly; SameSite=None; Partitioned';
        }

        return $body . '; HttpOnly; SameSite=Lax';
    }
}
