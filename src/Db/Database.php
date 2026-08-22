<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

final class Database {
    private static ?PDO $connection = null;

    public static function connection(): PDO {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = require __DIR__ . '/../../config/config.php';
        $pdo = new PDO('sqlite:' . $config['db_path']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // SQLite erzwingt Foreign-Key-Constraints nur, wenn diese Pragma pro
        // Verbindung explizit gesetzt wird - sonst würden z.B. verwaiste
        // article_tags-Zeilen beim Löschen eines Artikels liegen bleiben.
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$connection = $pdo;
        return $pdo;
    }
}
