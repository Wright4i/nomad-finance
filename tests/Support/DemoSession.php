<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Demo\DemoLibrary;
use PDO;

final class DemoSession
{
    public static function start(PDO $pdo): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $existing = $pdo->query("SELECT id FROM user WHERE role = 'demo'")->fetchColumn();
        if ($existing === false) {
            $now = gmdate('c');
            $pdo->prepare(
                'INSERT INTO user (name, password_hash, base_currency, theme, role, created_at)
                 VALUES (?, ?, \'USD\', \'dark\', \'demo\', ?)'
            )->execute(['Demo', password_hash(DemoLibrary::PASSWORD, PASSWORD_DEFAULT), $now]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)')->execute([$id]);
        } else {
            $id = (int) $existing;
        }

        $_SESSION['user_id'] = $id;

        return $id;
    }
}
