<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use PDO;

final class Actor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function id(): int
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            throw new ApiException(401, 'Sign in first.');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM user WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiException(401, 'Sign in first.');
        }

        return $id;
    }

    public function role(): string
    {
        $stmt = $this->pdo->prepare('SELECT role FROM user WHERE id = ?');
        $stmt->execute([$this->id()]);
        $role = $stmt->fetchColumn();
        if (!is_string($role)) {
            throw new ApiException(401, 'Sign in first.');
        }

        return $role;
    }

    public function baseCurrency(): string
    {
        $stmt = $this->pdo->prepare('SELECT base_currency FROM user WHERE id = ?');
        $stmt->execute([$this->id()]);
        $currency = $stmt->fetchColumn();
        if (!is_string($currency) || $currency === '') {
            throw new ApiException(401, 'Sign in first.');
        }

        return $currency;
    }

    public function requireDemo(): void
    {
        if ($this->role() !== 'demo') {
            throw new ApiException(403, 'Only the demo books can be reset.');
        }
    }
}
