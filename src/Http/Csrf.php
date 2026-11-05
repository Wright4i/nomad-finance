<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

final class Csrf
{
    public static function token(): string
    {
        $current = $_SESSION['csrf'] ?? null;
        if (!is_string($current) || $current === '') {
            $current = bin2hex(random_bytes(32));
            $_SESSION['csrf'] = $current;
        }

        return $current;
    }

    public static function check(ServerRequestInterface $request): void
    {
        $sent = $request->getHeaderLine('X-CSRF-Token');
        $token = $_SESSION['csrf'] ?? '';
        if (!is_string($token) || $token === '' || !hash_equals($token, $sent)) {
            throw new ApiException(403, 'The form expired. Reload and try again.');
        }
    }
}
