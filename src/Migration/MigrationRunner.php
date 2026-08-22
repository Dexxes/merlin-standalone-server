<?php

declare(strict_types=1);

namespace Merlin\Migration;

use PDO;

final class MigrationRunner {
    public function __construct(private readonly PDO $db) {
    }

    /**
     * Wendet alle noch nicht angewandten *.sql-Dateien aus migrations/ in
     * Dateinamen-Reihenfolge an. Jede Datei läuft in einer eigenen
     * Transaktion, damit ein Fehler mitten in einer Migration nicht die
     * schema_migrations-Tabelle inkonsistent zurücklässt.
     *
     * @return string[] Namen der neu angewandten Migrationen
     */
    public function migrate(): array {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $applied = $this->db->query('SELECT version FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);

        $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
        sort($files);

        $newlyApplied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Could not read migration file: {$file}");
            }

            $this->db->beginTransaction();
            try {
                $this->db->exec($sql);
                $stmt = $this->db->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );
                $stmt->execute([
                    'version' => $version,
                    'applied_at' => gmdate('c'),
                ]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw new \RuntimeException("Migration {$version} failed: {$e->getMessage()}", 0, $e);
            }

            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }
}
