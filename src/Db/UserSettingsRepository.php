<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/**
 * Persönliche Einstellungen pro Nutzer - Pendant zu Nextclouds
 * IConfig::getUserValue()/setUserValue() (siehe merlin-nextcloud/lib/Controller/
 * SettingsController.php), hier als eigene Key/Value-Tabelle statt einer
 * generischen App-Config, weil merlin-server keinen IConfig-Dienst hat.
 * Getrennt von SettingsRepository (instanzweite Einstellungen wie
 * allow_self_registration) - unterschiedlicher Scope, keine Verwechslungsgefahr.
 */
final class UserSettingsRepository {
    public function __construct(private readonly PDO $db) {
    }

    /** @return array<string, string> */
    public function getAllForUser(int $userId): array {
        $stmt = $this->db->prepare('SELECT key, value FROM user_settings WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    public function setForUser(int $userId, string $key, string $value): void {
        $stmt = $this->db->prepare(
            'INSERT INTO user_settings (user_id, key, value) VALUES (:user_id, :key, :value)
             ON CONFLICT(user_id, key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['user_id' => $userId, 'key' => $key, 'value' => $value]);
    }
}
