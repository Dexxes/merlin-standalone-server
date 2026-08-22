<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/** PDO-Port von merlin-nextcloud/lib/Db/TagMapper.php. */
final class TagRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function find(int $id, int $userId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM tags WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findAll(int $userId): array {
        $stmt = $this->db->prepare('SELECT * FROM tags WHERE user_id = :user_id ORDER BY name ASC');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function findByArticleId(int $articleId): array {
        $stmt = $this->db->prepare(
            'SELECT t.* FROM tags t
             INNER JOIN article_tags at ON t.id = at.tag_id
             WHERE at.article_id = :article_id
             ORDER BY t.name ASC'
        );
        $stmt->execute(['article_id' => $articleId]);
        return $stmt->fetchAll();
    }

    public function create(int $userId, string $name, string $color): array {
        $stmt = $this->db->prepare(
            'INSERT INTO tags (user_id, name, color, created_at) VALUES (:user_id, :name, :color, :created_at)'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name, 'color' => $color, 'created_at' => gmdate('c')]);
        return $this->find((int) $this->db->lastInsertId(), $userId);
    }

    public function update(int $id, int $userId, string $name, string $color): bool {
        $stmt = $this->db->prepare(
            'UPDATE tags SET name = :name, color = :color WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['name' => $name, 'color' => $color, 'id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->db->prepare('DELETE FROM tags WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function addToArticle(int $articleId, int $tagId): void {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO article_tags (article_id, tag_id) VALUES (:article_id, :tag_id)'
        );
        $stmt->execute(['article_id' => $articleId, 'tag_id' => $tagId]);
    }

    public function removeFromArticle(int $articleId, int $tagId): void {
        $stmt = $this->db->prepare(
            'DELETE FROM article_tags WHERE article_id = :article_id AND tag_id = :tag_id'
        );
        $stmt->execute(['article_id' => $articleId, 'tag_id' => $tagId]);
    }

    public static function toPublicArray(array $tag): array {
        return [
            'id' => (int) $tag['id'],
            'name' => $tag['name'],
            'color' => $tag['color'],
        ];
    }
}
