<?php

declare(strict_types=1);

namespace App\Category;

use App\Bill\BillTypes;
use PDO;

final class SystemBuckets
{
    public static function ensure(PDO $pdo, int $userId): void
    {
        $now = gmdate('c');
        $insert = $pdo->prepare(
            'INSERT INTO categories (user_id, name, category_group, amount_minor, role, bill_type, created_at, updated_at)
             SELECT ?, ?, ?, 0, \'locked\', ?, ?, ?
             WHERE NOT EXISTS (
                SELECT 1 FROM categories WHERE user_id = ? AND role = \'locked\' AND bill_type = ?
             )'
        );
        foreach (BillTypes::GROUP as $type => $group) {
            $insert->execute([
                $userId,
                BillTypes::label($type),
                $group,
                $type,
                $now,
                $now,
                $userId,
                $type,
            ]);
        }
    }

    public static function idForType(PDO $pdo, int $userId, string $type): int
    {
        self::ensure($pdo, $userId);
        $stmt = $pdo->prepare(
            'SELECT id FROM categories WHERE user_id = ? AND role = \'locked\' AND bill_type = ?'
        );
        $stmt->execute([$userId, $type]);

        return (int) $stmt->fetchColumn();
    }

    public static function reserved(string $name): bool
    {
        foreach (BillTypes::LABEL as $label) {
            if (strcasecmp($name, $label) === 0) {
                return true;
            }
        }

        return false;
    }
}
