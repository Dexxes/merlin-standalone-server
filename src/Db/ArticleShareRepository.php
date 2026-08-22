<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/**
 * PDO-Port von merlin-nextclouds ArticleShareMapper/ArticleShare-Entity - ein
 * Artikel hat höchstens einen Share-Datensatz (UNIQUE (article_id, user_id)),
 * "Regenerieren" tauscht nur den Token statt einen zweiten Datensatz anzulegen.
 */
final class ArticleShareRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function findByArticleId(int $articleId, int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM article_shares WHERE article_id = :article_id AND user_id = :user_id'
        );
        $stmt->execute(['article_id' => $articleId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Auflösung eines öffentlichen Links: bewusst OHNE user_id/article_id-Filter,
     * da der anonyme Besucher nur den Token kennt (und kennen darf).
     */
    public function findByToken(string $token): ?array {
        $stmt = $this->db->prepare('SELECT * FROM article_shares WHERE token = :token');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(int $articleId, int $userId, string $token, ?string $passwordHash, ?string $expiresAt): array {
        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO article_shares (article_id, user_id, token, password_hash, expires_at, created_at, updated_at)
             VALUES (:article_id, :user_id, :token, :password_hash, :expires_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'article_id' => $articleId,
            'user_id' => $userId,
            'token' => $token,
            'password_hash' => $passwordHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stmt = $this->db->prepare('SELECT * FROM article_shares WHERE id = :id');
        $stmt->execute(['id' => (int) $this->db->lastInsertId()]);
        return $stmt->fetch();
    }

    /**
     * @param array{token?: string, password_hash?: ?string, expires_at?: ?string} $fields
     */
    public function update(int $id, array $fields): array {
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            $set[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        $set[] = 'updated_at = :updated_at';
        $params['updated_at'] = gmdate('c');

        $stmt = $this->db->prepare('UPDATE article_shares SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);

        $stmt = $this->db->prepare('SELECT * FROM article_shares WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function deleteByArticleId(int $articleId, int $userId): void {
        $stmt = $this->db->prepare(
            'DELETE FROM article_shares WHERE article_id = :article_id AND user_id = :user_id'
        );
        $stmt->execute(['article_id' => $articleId, 'user_id' => $userId]);
    }

    /**
     * Backoff-Zähler für Passwort-Fehlversuche - Ersatz für Nextclouds
     * IThrottler-Bordmittel, das es in merlin-server nicht gibt. Der Aufrufer
     * (PublicShareController::unlock()) schläft proportional zum Zählerstand,
     * bevor er das Passwort prüft.
     */
    public function registerFailedUnlock(int $id): void {
        $stmt = $this->db->prepare(
            'UPDATE article_shares SET failed_unlock_attempts = failed_unlock_attempts + 1, last_failed_unlock_at = :now
             WHERE id = :id'
        );
        $stmt->execute(['now' => gmdate('c'), 'id' => $id]);
    }

    public function resetFailedUnlock(int $id): void {
        $stmt = $this->db->prepare(
            'UPDATE article_shares SET failed_unlock_attempts = 0, last_failed_unlock_at = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public static function hasPassword(array $share): bool {
        return $share['password_hash'] !== null && $share['password_hash'] !== '';
    }

    public static function isExpired(array $share): bool {
        return $share['expires_at'] !== null && $share['expires_at'] < gmdate('c');
    }

    /** Wire-Format für die Verwaltungs-UI - enthält NIE den Passwort-Hash. */
    public static function toPublicArray(array $share, string $url): array {
        return [
            'articleId' => (int) $share['article_id'],
            'token' => $share['token'],
            'hasPassword' => self::hasPassword($share),
            'expiresAt' => $share['expires_at'],
            'createdAt' => $share['created_at'],
            'updatedAt' => $share['updated_at'],
            'url' => $url,
        ];
    }
}
