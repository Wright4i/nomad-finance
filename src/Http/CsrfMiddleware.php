<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            Csrf::check($request);
        }

        return $handler->handle($request);
    }
}
