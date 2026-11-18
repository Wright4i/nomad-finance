<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Psr7\Response;
use Throwable;

final class JsonErrorHandler implements ErrorHandlerInterface
{
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if ($exception instanceof ApiException) {
            return Json::error($exception->status, $exception->getMessage(), $exception->fields);
        }

        $status = 500;
        $message = 'Something went wrong.';
        if ($exception instanceof HttpException) {
            $code = $exception->getCode();
            $status = $code >= 400 && $code < 600 ? $code : 500;
            $message = $status === 404 ? 'Not found.' : $exception->getMessage();
        } elseif ($displayErrorDetails) {
            $message = $exception->getMessage();
        }

        if ($logErrors && $status >= 500) {
            $trace = $logErrorDetails ? "\n" . $exception->getTraceAsString() : '';
            error_log($exception::class . ': ' . $exception->getMessage() . $trace);
        }

        if (!str_starts_with($request->getUri()->getPath(), '/api')) {
            $response = (new Response($status))->withHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->getBody()->write($message);

            return $response;
        }

        return Json::error($status, $message);
    }
}
