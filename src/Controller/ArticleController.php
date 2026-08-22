<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ArticleRepository;
use Merlin\Db\TagRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Service\ContentExtractorService;
use Merlin\Service\ExportService;
use Psr\Log\LoggerInterface;

/**
 * Port von merlin-nextcloud/lib/Controller/ArticleController.php. Die
 * Async-Extraktion (register_shutdown_function + fastcgi_finish_request,
 * isProcessing-Flag statt SSE) ist reines PHP-FPM-Verhalten und 1:1
 * übernommen - siehe Plan.
 */
final class ArticleController {
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly TagRepository $tags,
        private readonly ContentExtractorService $contentExtractor,
        private readonly ExportService $exportService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function counts(Request $request): Response {
        return Response::json($this->articles->getCounts($request->authUserId()));
    }

    public function index(Request $request): Response {
        $userId = $request->authUserId();
        $this->articles->clearStuckProcessing($userId);

        $filters = array_filter([
            'is_read' => $request->queryBool('isRead'),
            'is_favorite' => $request->queryBool('isFavorite'),
            'is_archived' => $request->queryBool('isArchived'),
            'tag_id' => $request->queryInt('tagId'),
            'category' => $request->query('category'),
        ], fn($value) => $value !== null);

        $rows = $this->articles->findAll(
            $userId,
            $filters,
            $request->queryInt('limit', 50),
            $request->queryInt('offset', 0),
        );

        return Response::json(array_map(fn($row) => $this->withTags($row), $rows));
    }

    public function search(Request $request): Response {
        $term = (string) $request->query('term', '');
        $rows = $this->articles->search(
            $request->authUserId(),
            $term,
            $request->queryInt('limit', 20),
            $request->queryInt('offset', 0),
        );
        return Response::json(array_map(fn($row) => $this->withTags($row), $rows));
    }

    public function show(Request $request): Response {
        $article = $this->articles->find((int) $request->routeParam('id'), $request->authUserId());
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        return Response::json($this->withTags($article));
    }

    public function create(Request $request): Response {
        $url = (string) $request->input('url', '');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return Response::json(['error' => 'A valid url is required'], 400);
        }
        // Vom Client mitgeschicktes gerendertes HTML (Browser-Erweiterungen) -
        // erspart bei Paywall-/JS-Seiten den eigenen Server-Fetch, siehe
        // ExtensionController::add() in merlin-nextcloud für das Nextcloud-Pendant.
        $html = $request->input('html');
        // Optionaler Titel-/Autor-Override (z. B. Betreff/Absender einer als HTML
        // gesendeten E-Mail) - gewinnt nach der Extraktion, siehe scheduleExtraction().
        $title = $request->input('title');
        $author = $request->input('author');

        $userId = $request->authUserId();
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $articleId = $this->articles->insertPlaceholder($userId, $url, $title !== null && $title !== '' ? $title : $host, $host);

        $tagIds = array_values(array_filter(array_map('intval', $request->inputArray('tagIds')), fn($v) => $v > 0));
        foreach ($tagIds as $tagId) {
            $tag = $this->tags->find($tagId, $userId);
            if ($tag !== null) {
                $this->tags->addToArticle($articleId, $tagId);
            }
        }

        $this->scheduleExtraction($articleId, $url, $userId, $html, $title, $author);

        $article = $this->articles->find($articleId, $userId);
        return Response::json($this->withTags($article), 202);
    }

    /**
     * Extraktion läuft nach fastcgi_finish_request() weiter, damit der
     * Client sofort eine Antwort bekommt (Platzhalter-Artikel, isProcessing).
     * Siehe merlin-nextcloud/lib/Controller/ArticleController.php:209-264.
     */
    private function scheduleExtraction(int $articleId, string $url, int $userId, ?string $html = null, ?string $title = null, ?string $author = null): void {
        $articles = $this->articles;
        $extractor = $this->contentExtractor;
        $logger = $this->logger;

        register_shutdown_function(static function () use ($articleId, $url, $userId, $html, $title, $author, $articles, $extractor, $logger): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            set_time_limit(120);

            try {
                // Mitgeschicktes HTML nutzen statt eigenem Fetch, wenn vorhanden -
                // spiegelt ExtensionController::add() in merlin-nextcloud.
                $extracted = ($html !== null && $html !== '')
                    ? $extractor->extractFromHtml($url, $html, (string) $userId)
                    : $extractor->extract($url, null, (string) $userId);
                // Caller-supplied title/author (z. B. Betreff/Absender einer Mail) gewinnen
                // gegen die Extraktion - siehe ExtensionController::add() in merlin-nextcloud.
                if ($title !== null && $title !== '') {
                    $extracted['title'] = $title;
                }
                if ($author !== null && $author !== '') {
                    $extracted['author'] = $author;
                }
                $articles->applyExtractionResult($articleId, [
                    'url' => $extracted['url'] ?? $url,
                    'title' => $extracted['title'],
                    'content' => $extracted['content'],
                    'excerpt' => $extracted['excerpt'],
                    'author' => $extracted['author'],
                    'siteName' => $extracted['siteName'],
                    'imageUrl' => $extracted['imageUrl'],
                    'readingTime' => $extracted['readingTime'],
                    'publishedAt' => $extracted['publishedAt'] ? $extracted['publishedAt']->format('c') : null,
                    'category' => $extracted['category'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $logger->error('article extraction failed', ['articleId' => $articleId, 'exception' => $e]);
                $articles->clearProcessing($articleId);
            }
        });
    }

    public function update(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $userId = $request->authUserId();

        $fields = [];
        foreach (['title' => 'title', 'content' => 'content', 'excerpt' => 'excerpt'] as $input => $column) {
            $value = $request->input($input);
            if ($value !== null) {
                $fields[$column] = $value;
            }
        }

        if (!$this->articles->update($id, $userId, $fields)) {
            return Response::json(['error' => 'Not found'], 404);
        }
        return Response::json($this->withTags($this->articles->find($id, $userId)));
    }

    public function destroy(Request $request): Response {
        $deleted = $this->articles->delete((int) $request->routeParam('id'), $request->authUserId());
        return $deleted ? Response::noContent() : Response::json(['error' => 'Not found'], 404);
    }

    public function toggleRead(Request $request): Response {
        return $this->toggleBoolField($request, 'is_read', 'isRead');
    }

    public function toggleArchive(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $userId = $request->authUserId();
        $article = $this->articles->find($id, $userId);
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $archived = !(bool) $article['is_archived'];
        $this->articles->update($id, $userId, [
            'is_archived' => $archived ? 1 : 0,
            'archived_at' => $archived ? gmdate('c') : null,
        ]);
        return Response::json($this->withTags($this->articles->find($id, $userId)));
    }

    public function toggleFavorite(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $userId = $request->authUserId();
        $article = $this->articles->find($id, $userId);
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $isFavorite = $article['is_favorite'] !== null;
        $this->articles->update($id, $userId, [
            'is_favorite' => $isFavorite ? null : gmdate('c'),
        ]);
        return Response::json($this->withTags($this->articles->find($id, $userId)));
    }

    public function updateProgress(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $userId = $request->authUserId();

        $progress = (float) $request->input('scrollProgress', '0');
        $updatedAt = (int) $request->input('scrollUpdatedAt', '0');

        $article = $this->articles->find($id, $userId);
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        // Last-write-wins über den Client-Zeitstempel, nicht über die
        // Server-Ankunftszeit - ein Gerät, das offline eine ältere Position
        // nachreicht, darf eine neuere nicht überschreiben.
        if ($updatedAt < (int) $article['scroll_updated_at']) {
            return Response::json($this->withTags($article));
        }

        $this->articles->update($id, $userId, [
            'scroll_progress' => max(0.0, min(1.0, $progress)),
            'scroll_updated_at' => $updatedAt,
        ]);
        return Response::json($this->withTags($this->articles->find($id, $userId)));
    }

    private function toggleBoolField(Request $request, string $column, string $inputName): Response {
        $id = (int) $request->routeParam('id');
        $userId = $request->authUserId();
        $article = $this->articles->find($id, $userId);
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $value = $request->input($inputName);
        $newValue = $value === null ? !((bool) $article[$column]) : filter_var($value, FILTER_VALIDATE_BOOLEAN);

        $this->articles->update($id, $userId, [$column => $newValue ? 1 : 0]);
        return Response::json($this->withTags($this->articles->find($id, $userId)));
    }

    public function exportHtml(Request $request): Response {
        $article = $this->articles->find((int) $request->routeParam('id'), $request->authUserId());
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $filename = substr((string) preg_replace('/\s+/', '_', (string) preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $article['title'])), 0, 100);
        return Response::download($this->exportService->exportHtml($article), ($filename !== '' ? $filename : 'article') . '.html', 'text/html');
    }

    private function withTags(array $article): array {
        $data = ArticleRepository::toPublicArray($article);
        $data['tags'] = array_map(TagRepository::toPublicArray(...), $this->tags->findByArticleId((int) $article['id']));
        return $data;
    }
}
