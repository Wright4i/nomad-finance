<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Migrator
{
    public static function migrate(PDO $pdo, string $dir): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $applied = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = is_array($applied) ? $applied : [];
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $files = glob(rtrim($dir, '/') . '/*.sql');
            if ($files === false) {
                return;
            }
            sort($files);

            $insert = $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (?, ?)');
            foreach ($files as $file) {
                $id = basename($file);
                if (in_array($id, $applied, true)) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new \RuntimeException('Could not read migration ' . $id);
                }
                $pdo->beginTransaction();
                try {
                    foreach (self::statements($sql) as $statement) {
                        $pdo->exec($statement);
                    }
                    $insert->execute([$id, gmdate('c')]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        $parts = preg_split('/;\s*(?:\R|$)/', trim($sql));
        if ($parts === false) {
            return [];
        }

        $statements = [];
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
