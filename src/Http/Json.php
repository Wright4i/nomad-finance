<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class Json
{
    /** @param mixed $data */
    public static function write(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function error(int $status, string $message, array $fields = []): ResponseInterface
    {
        $body = ['error' => $message];
        if ($fields !== []) {
            $body['fields'] = $fields;
        }

        return self::write(new Response(), $body, $status);
    }
}
