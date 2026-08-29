<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/**
 * PDO-Port von merlin-nextcloud/lib/Db/ArticleMapper.php - gleiche Query-
 * Semantik (Filter, Sortierung je View, Counts), nur PDO statt NCs
 * QueryBuilder.
 */
final class ArticleRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function find(int $id, int $userId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM articles WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array{is_read?: bool, is_favorite?: bool, is_archived?: bool, tag_id?: int, category?: string} $filters
     */
    public function findAll(int $userId, array $filters, int $limit, int $offset): array {
        $sql = 'SELECT a.* FROM articles a';
        $params = ['user_id' => $userId];

        if (isset($filters['tag_id'])) {
            $sql .= ' INNER JOIN article_tags at ON a.id = at.article_id AND at.tag_id = :tag_id';
            $params['tag_id'] = $filters['tag_id'];
        }

        $sql .= ' WHERE a.user_id = :user_id';

        if (isset($filters['is_read'])) {
            $sql .= ' AND a.is_read = :is_read';
            $params['is_read'] = $filters['is_read'] ? 1 : 0;
        }
        // is_favorite ist NULL (nicht favorisiert) oder ein Zeitstempel -
        // daher IS [NOT] NULL statt Gleichheitsvergleich.
        if (isset($filters['is_favorite'])) {
            $sql .= $filters['is_favorite'] ? ' AND a.is_favorite IS NOT NULL' : ' AND a.is_favorite IS NULL';
        }
        if (isset($filters['is_archived'])) {
            $sql .= ' AND a.is_archived = :is_archived';
            $params['is_archived'] = $filters['is_archived'] ? 1 : 0;
        }
        if (isset($filters['category'])) {
            $sql .= ' AND a.category = :category';
            $params['category'] = $filters['category'];
        }
        if (isset($filters['not_category'])) {
            $sql .= ' AND (a.category IS NULL OR a.category != :not_category)';
            $params['not_category'] = $filters['not_category'];
        }

        // Favoriten-Ansicht: chronologisch nach Favorisierungszeitpunkt sortieren.
        // Archiv-Ansicht: nach Archivierungszeitpunkt. Sonst: nach Erstellungsdatum.
        if (!empty($filters['is_favorite'])) {
            $sql .= ' ORDER BY a.is_favorite DESC';
        } elseif (!empty($filters['is_archived'])) {
            $sql .= ' ORDER BY a.archived_at DESC';
        } else {
            $sql .= ' ORDER BY a.created_at DESC';
        }

        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function search(int $userId, string $term, int $limit, int $offset): array {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        $stmt = $this->db->prepare(
            'SELECT * FROM articles
             WHERE user_id = :user_id AND is_archived = 0
               AND (title LIKE :like ESCAPE \'\\\' OR excerpt LIKE :like ESCAPE \'\\\'
                    OR author LIKE :like ESCAPE \'\\\' OR site_name LIKE :like ESCAPE \'\\\')
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':like', $like, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Summiert die Bytegröße aller Textspalten der Artikel eines Nutzers
     * (LENGTH(CAST(... AS BLOB)) liefert in SQLite die Bytelänge statt der
     * Zeichenanzahl - relevant bei Umlauten/Emoji im Artikeltext). Dient der
     * Speicherverbrauchs-Anzeige in den iOS-Einstellungen.
     *
     * @return array{count: int, bytes: int}
     */
    public function getStorageStats(int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(
                LENGTH(CAST(title AS BLOB)) + LENGTH(CAST(content AS BLOB)) +
                LENGTH(CAST(COALESCE(excerpt, '') AS BLOB)) + LENGTH(CAST(COALESCE(author, '') AS BLOB)) +
                LENGTH(CAST(COALESCE(site_name, '') AS BLOB)) + LENGTH(CAST(url AS BLOB)) +
                LENGTH(CAST(COALESCE(image_url, '') AS BLOB))
            ), 0) AS bytes
            FROM articles WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return ['count' => (int) $row['cnt'], 'bytes' => (int) $row['bytes']];
    }

    public function getCounts(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT is_read, is_favorite, is_archived, category FROM articles WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        // Seiten/Videos sind die obersten Kategorien (category = "Video" oder
        // nicht), Unread/Favorites/Archived darunter je Kategorie gezählt -
        // siehe getCounts() in merlin-nextcloud/lib/Db/ArticleMapper.php.
        $counts = [
            'pages' => ['total' => 0, 'unread' => 0, 'favorites' => 0, 'archived' => 0],
            'videos' => ['total' => 0, 'unread' => 0, 'favorites' => 0, 'archived' => 0],
        ];

        foreach ($stmt->fetchAll() as $row) {
            $group = $row['category'] === 'Video' ? 'videos' : 'pages';
            $isArchived = (bool) (int) $row['is_archived'];
            $read = (bool) (int) $row['is_read'];
            $favorite = $row['is_favorite'] !== null;

            if ($isArchived) {
                $counts[$group]['archived']++;
            } else {
                $counts[$group]['total']++;
                if (!$read) {
                    $counts[$group]['unread']++;
                }
            }
            if ($favorite) {
                $counts[$group]['favorites']++;
            }
        }

        return $counts;
    }

    public function insertPlaceholder(int $userId, string $url, string $title, string $siteName): int {
        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO articles (
                user_id, url, title, content, excerpt, author, site_name, image_url,
                is_read, is_favorite, is_archived, is_processing, reading_time,
                created_at, updated_at
            ) VALUES (
                :user_id, :url, :title, \'\', \'\', \'\', :site_name, \'\',
                0, NULL, 0, 1, 0,
                :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'url' => $url,
            'title' => $title,
            'site_name' => $siteName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{url: string, title: string, content: string, excerpt: ?string,
     *              author: ?string, siteName: ?string, imageUrl: ?string,
     *              readingTime: int, publishedAt: ?string, category: ?string} $extracted
     */
    public function applyExtractionResult(int $id, array $extracted): void {
        $stmt = $this->db->prepare(
            'UPDATE articles SET
                url = :url, title = :title, content = :content, excerpt = :excerpt,
                author = :author, site_name = :site_name, image_url = :image_url,
                reading_time = :reading_time, published_at = COALESCE(:published_at, published_at),
                category = COALESCE(:category, category), is_processing = 0, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'url' => $extracted['url'],
            'title' => $extracted['title'],
            'content' => $extracted['content'],
            'excerpt' => $extracted['excerpt'],
            'author' => $extracted['author'],
            'site_name' => $extracted['siteName'],
            'image_url' => $extracted['imageUrl'],
            'reading_time' => $extracted['readingTime'],
            'published_at' => $extracted['publishedAt'] ?: null,
            'category' => $extracted['category'] ?: null,
            'updated_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function clearProcessing(int $id): void {
        $stmt = $this->db->prepare('UPDATE articles SET is_processing = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Markiert einen Artikel wieder als "in Bearbeitung" - genutzt beim
     * manuellen Retry einer zuvor fehlgeschlagenen Extraktion (siehe
     * ArticleController::retryExtraction()), damit der Client wieder auf
     * `isProcessing` pollt statt den permanent leeren Inhalt anzuzeigen.
     */
    public function setProcessing(int $id): void {
        $stmt = $this->db->prepare(
            'UPDATE articles SET is_processing = 1, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'updated_at' => gmdate('c')]);
    }

    /**
     * Signalisiert, dass die Extraktion an einer Paywall gescheitert ist, für
     * die der Nutzer keine (gültigen) Zugangsdaten hinterlegt hat - siehe
     * Service\Login\PaywallLoginRequiredException. requires_login_domain !==
     * null ist die Grundlage für den Login-Dialog im Client (siehe
     * PLATFORMS.md).
     */
    public function markRequiresLogin(int $id, string $domain, string $loginPage): void {
        $stmt = $this->db->prepare(
            'UPDATE articles SET is_processing = 0, requires_login_domain = :domain, requires_login_page = :page WHERE id = :id'
        );
        $stmt->execute(['domain' => $domain, 'page' => $loginPage, 'id' => $id]);
    }

    /**
     * Setzt is_processing für Artikel zurück, die (z.B. durch einen
     * abgestürzten PHP-Prozess) länger als $ageMinutes in Bearbeitung
     * hängengeblieben sind - Pendant zu ArticleMapper::clearStuckProcessing().
     */
    public function clearStuckProcessing(int $userId, int $ageMinutes = 5): void {
        $cutoff = gmdate('c', time() - $ageMinutes * 60);
        $stmt = $this->db->prepare(
            'UPDATE articles SET is_processing = 0
             WHERE user_id = :user_id AND is_processing = 1 AND updated_at < :cutoff'
        );
        $stmt->execute(['user_id' => $userId, 'cutoff' => $cutoff]);
    }

    public function update(int $id, int $userId, array $fields): bool {
        if ($fields === []) {
            return true;
        }

        $set = [];
        $params = ['id' => $id, 'user_id' => $userId];
        foreach ($fields as $column => $value) {
            $set[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        $set[] = 'updated_at = :updated_at';
        $params['updated_at'] = gmdate('c');

        $stmt = $this->db->prepare(
            'UPDATE articles SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->db->prepare('DELETE FROM articles WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function toPublicArray(array $article): array {
        return [
            'id' => (int) $article['id'],
            'userId' => (int) $article['user_id'],
            'url' => $article['url'],
            'title' => $article['title'],
            'content' => $article['content'],
            'excerpt' => $article['excerpt'],
            'author' => $article['author'],
            'siteName' => $article['site_name'],
            'imageUrl' => $article['image_url'],
            'isRead' => (bool) $article['is_read'],
            'isFavorite' => $article['is_favorite'] ?? false,
            'isArchived' => (bool) $article['is_archived'],
            'isProcessing' => (bool) $article['is_processing'],
            'readingTime' => (int) $article['reading_time'],
            'category' => $article['category'],
            'publishedAt' => $article['published_at'],
            'createdAt' => $article['created_at'],
            'updatedAt' => $article['updated_at'],
            'archivedAt' => $article['archived_at'],
            'scrollProgress' => (float) $article['scroll_progress'],
            'scrollUpdatedAt' => (int) $article['scroll_updated_at'],
            // null im Normalfall. Gesetzt: Client soll einen Login-Dialog für
            // requiresLoginDomain anbieten (requiresLoginPage als Info-Link),
            // danach das Speichern erneut anstoßen (POST /api/articles erneut,
            // kein dedizierter Retry-Endpunkt).
            'requiresLoginDomain' => $article['requires_login_domain'] ?? null,
            'requiresLoginPage' => $article['requires_login_page'] ?? null,
        ];
    }
}
