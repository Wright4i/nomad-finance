<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->current() === null) {
            throw new ApiException(401, 'Sign in first.');
        }

        return $handler->handle($request);
    }
}
