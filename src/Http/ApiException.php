<?php

declare(strict_types=1);

namespace App\Http;

final class ApiException extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
