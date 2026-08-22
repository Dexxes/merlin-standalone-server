<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

final class SettingsRepository {
    public const ALLOW_SELF_REGISTRATION = 'allow_self_registration';

    public function __construct(private readonly PDO $db) {
    }

    public function get(string $key, string $default = ''): string {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function getBool(string $key, bool $default = false): bool {
        return $this->get($key, $default ? '1' : '0') === '1';
    }

    public function set(string $key, string $value): void {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}
