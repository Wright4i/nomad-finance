<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HomepageMiddleware implements MiddlewareInterface
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = is_string($this->settings['homepageToken'] ?? null) ? $this->settings['homepageToken'] : '';
        if ($token === '') {
            throw new ApiException(404, 'Not found.');
        }
        $provided = $request->getHeaderLine('X-Homepage-Token');
        if ($provided === '' || !hash_equals($token, $provided)) {
            throw new ApiException(401, 'Homepage token required.');
        }

        return $handler->handle($request);
    }
}
