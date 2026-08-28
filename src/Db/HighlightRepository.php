<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/** PDO-Port von merlin-nextcloud/lib/Db/HighlightMapper.php. */
final class HighlightRepository {
    public function __construct(private readonly PDO $db) {
    }

    /**
     * @return array{count: int, bytes: int}
     */
    public function getStorageStats(int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(
                LENGTH(CAST(highlighted_text AS BLOB)) + LENGTH(CAST(start_xpath AS BLOB)) +
                LENGTH(CAST(end_xpath AS BLOB))
            ), 0) AS bytes
            FROM highlights WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return ['count' => (int) $row['cnt'], 'bytes' => (int) $row['bytes']];
    }

    public function findByArticleId(int $articleId, int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM highlights WHERE article_id = :article_id AND user_id = :user_id ORDER BY created_at ASC'
        );
        $stmt->execute(['article_id' => $articleId, 'user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function create(int $articleId, int $userId, array $data): array {
        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO highlights (
                article_id, user_id, highlighted_text, start_xpath, start_offset,
                end_xpath, end_offset, color, created_at
            ) VALUES (
                :article_id, :user_id, :highlighted_text, :start_xpath, :start_offset,
                :end_xpath, :end_offset, :color, :created_at
            )'
        );
        $stmt->execute([
            'article_id' => $articleId,
            'user_id' => $userId,
            'highlighted_text' => $data['highlightedText'],
            'start_xpath' => $data['startXpath'],
            'start_offset' => $data['startOffset'],
            'end_xpath' => $data['endXpath'],
            'end_offset' => $data['endOffset'],
            'color' => $data['color'],
            'created_at' => $now,
        ]);

        $id = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM highlights WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->db->prepare('DELETE FROM highlights WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function toPublicArray(array $highlight): array {
        return [
            'id' => (int) $highlight['id'],
            'articleId' => (int) $highlight['article_id'],
            'highlightedText' => $highlight['highlighted_text'],
            'startXpath' => $highlight['start_xpath'],
            'startOffset' => (int) $highlight['start_offset'],
            'endXpath' => $highlight['end_xpath'],
            'endOffset' => (int) $highlight['end_offset'],
            'color' => $highlight['color'],
            'createdAt' => $highlight['created_at'],
        ];
    }
}
