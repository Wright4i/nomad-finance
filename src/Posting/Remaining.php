<?php

declare(strict_types=1);

namespace App\Posting;

use PDO;

final class Remaining
{
    /**
     * @param list<array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    public static function attach(PDO $pdo, int $userId, array $categories, ?string $start, ?string $end): array
    {
        $nets = $start === null ? [] : self::netOut($pdo, $userId, $start, $end);
        $listed = [];
        foreach ($categories as $category) {
            $id = (int) ($category['id'] ?? 0);
            $category['remainingMinor'] = (int) ($category['amountMinor'] ?? 0) - ($nets[$id] ?? 0);
            $listed[] = $category;
        }

        return $listed;
    }

    public static function one(PDO $pdo, int $userId, int $categoryId, int $assigned, ?string $start, ?string $end): int
    {
        if ($start === null) {
            return $assigned;
        }
        $nets = self::netOut($pdo, $userId, $start, $end);

        return $assigned - ($nets[$categoryId] ?? 0);
    }

    /** @return array<int, int> */
    private static function netOut(PDO $pdo, int $userId, string $start, ?string $end): array
    {
        $sql = 'SELECT p.direction,
                       CASE WHEN l.id IS NULL THEN p.category_id ELSE l.category_id END AS category_id,
                       CASE WHEN l.id IS NULL THEN p.amount_minor ELSE l.amount_minor END AS amount_minor
                FROM postings p
                LEFT JOIN posting_lines l ON l.posting_id = p.id
                WHERE p.user_id = ? AND p.posted_on >= ?';
        $params = [$userId, $start];
        if ($end !== null) {
            $sql .= ' AND p.posted_on <= ?';
            $params[] = $end;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $nets = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $id = (int) $row['category_id'];
            $amount = (int) $row['amount_minor'];
            $nets[$id] = ($nets[$id] ?? 0) + ($row['direction'] === 'out' ? $amount : -$amount);
        }

        return $nets;
    }
}
