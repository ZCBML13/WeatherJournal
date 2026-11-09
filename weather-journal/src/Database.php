<?php

declare(strict_types=1);

final class Database
{
    /** Open (and create if needed) the SQLite database, then apply the schema. */
    public static function connect(string $path): PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

        return $pdo;
    }
}
