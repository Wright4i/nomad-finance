<?php

declare(strict_types=1);

namespace App\Support;

final class Dates
{
    public static function parse(string $value): \DateTimeImmutable
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $warnings = is_array($errors) ? (int) ($errors['warning_count'] ?? 0) : 0;
        $errorCount = is_array($errors) ? (int) ($errors['error_count'] ?? 0) : 0;
        if (!$parsed instanceof \DateTimeImmutable || $warnings > 0 || $errorCount > 0 || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Use a YYYY-MM-DD date.');
        }

        return $parsed;
    }
}
