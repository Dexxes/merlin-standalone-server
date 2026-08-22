<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

final class PasswordResetRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt): void {
        $stmt = $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, created_at, expires_at)
             VALUES (:user_id, :token_hash, :created_at, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'created_at' => gmdate('c'),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Findet ein noch gültiges, unbenutztes Token (Ablauf + used_at werden
     * hier statt nur in PHP geprüft, damit ein manipulierter Serverzeit-Skew
     * nicht zwei unterschiedliche Antworten liefert).
     */
    public function findValidByHash(string $tokenHash): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM password_reset_tokens
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now'
        );
        $stmt->execute(['hash' => $tokenHash, 'now' => gmdate('c')]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markUsed(int $id): void {
        $stmt = $this->db->prepare('UPDATE password_reset_tokens SET used_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('c'), 'id' => $id]);
    }
}
