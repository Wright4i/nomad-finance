<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Database
{
    public static function connect(string $path, string $migrationsDir): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the database directory.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        Migrator::migrate($pdo, $migrationsDir);
        (new \App\Demo\DemoLibrary($pdo, new \App\Money\CurrencyCatalog()))->ensure();

        return $pdo;
    }
}
