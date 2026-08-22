<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

final class ApiTokenRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function create(int $userId, string $name, string $tokenHash): array {
        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, created_at)
             VALUES (:user_id, :name, :token_hash, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $tokenHash,
            'created_at' => $now,
        ]);

        $stmt = $this->db->prepare('SELECT * FROM api_tokens WHERE id = :id');
        $stmt->execute(['id' => (int) $this->db->lastInsertId()]);
        return $stmt->fetch();
    }

    /**
     * Nur nicht widerrufene Tokens werden gefunden - ein revoked_at-Eintrag
     * macht den Hash für die Auth dauerhaft ungültig, auch wenn die Zeile aus
     * Audit-Gründen erhalten bleibt.
     */
    public function findActiveByHash(string $tokenHash): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM api_tokens WHERE token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByUser(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM api_tokens WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function touchLastUsed(int $tokenId): void {
        $stmt = $this->db->prepare('UPDATE api_tokens SET last_used_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('c'), 'id' => $tokenId]);
    }

    public function revoke(int $tokenId, int $userId): bool {
        $stmt = $this->db->prepare(
            'UPDATE api_tokens SET revoked_at = :now
             WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => gmdate('c'), 'id' => $tokenId, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Widerruft alle Tokens eines Users - wird nach einem Passwort-Reset
     * aufgerufen, da ein kompromittiertes Passwort sonst weiterhin gültige
     * API-Sitzungen auf allen Geräten offen ließe.
     */
    public function revokeAllForUser(int $userId): void {
        $stmt = $this->db->prepare(
            'UPDATE api_tokens SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => gmdate('c'), 'user_id' => $userId]);
    }

    public static function toPublicArray(array $token): array {
        return [
            'id' => (int) $token['id'],
            'name' => $token['name'],
            'createdAt' => $token['created_at'],
            'lastUsedAt' => $token['last_used_at'],
        ];
    }
}
