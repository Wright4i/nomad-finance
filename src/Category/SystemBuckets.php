<?php

declare(strict_types=1);

namespace App\Category;

use PDO;

final class SystemBuckets
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'needs' => 'Needs Default',
        'wants' => 'Wants Default',
        'savings' => 'Savings Default',
    ];

    public const FUEL = 'Fuel Bucket';

    /** @var list<string> */
    public const RESERVED = ['Needs Default', 'Wants Default', 'Savings Default', 'Fuel Bucket'];

    public static function ensure(PDO $pdo, int $userId): void
    {
        $now = gmdate('c');
        $insert = $pdo->prepare(
            'INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
             SELECT ?, ?, ?, 0, \'cover\', ?, ?, ?
             WHERE NOT EXISTS (
                SELECT 1 FROM categories WHERE user_id = ? AND role = ? AND category_group = ?
             )'
        );
        foreach (self::DEFAULTS as $group => $name) {
            $insert->execute([$userId, $name, $group, 'default', $now, $now, $userId, 'default', $group]);
        }
        $insert->execute([$userId, self::FUEL, 'needs', 'fuel', $now, $now, $userId, 'fuel', 'needs']);
    }

    public static function id(PDO $pdo, int $userId, string $role, string $group): int
    {
        self::ensure($pdo, $userId);
        $stmt = $pdo->prepare(
            'SELECT id FROM categories WHERE user_id = ? AND role = ? AND category_group = ?'
        );
        $stmt->execute([$userId, $role, $group]);

        return (int) $stmt->fetchColumn();
    }

    public static function reserved(string $name): bool
    {
        foreach (self::RESERVED as $reserved) {
            if (strcasecmp($name, $reserved) === 0) {
                return true;
            }
        }

        return false;
    }
}
