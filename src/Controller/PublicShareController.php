<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Auth\SessionService;
use Merlin\Db\ArticleRepository;
use Merlin\Db\ArticleShareRepository;
use Merlin\Db\HighlightRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\I18n\Translator;
use Merlin\Service\ContentExtractorService;
use Merlin\Service\TtsStreamService;

/**
 * Öffentliche Auslieferung eines per Share-Link freigegebenen Artikels - KEIN
 * Login nötig. Port von merlin-nextcloud/lib/Controller/PublicShareController.php.
 * Der Token aus der URL ist die einzige Berechtigung; alle Lookups gehen über
 * ArticleShareRepository::findByToken(), NIE über eine vom Client mitgeschickte
 * article_id/user_id (IDOR-Schutz).
 *
 * Passwort-Unlock wird in der PHP-Session gemerkt (SessionService), Backoff
 * gegen Brute-Force über den failed_unlock_attempts-Zähler auf der
 * Share-Zeile selbst (Ersatz für Nextclouds IThrottler-Bordmittel).
 */
final class PublicShareController {
    public function __construct(
        private readonly ArticleShareRepository $shares,
        private readonly ArticleRepository $articles,
        private readonly HighlightRepository $highlights,
        private readonly TtsStreamService $ttsStream,
        private readonly SessionService $sessions,
    ) {
    }

    /** HTML-Shell für die öffentliche Ansicht (Zustandslogik läuft komplett im Browser-JS). */
    public function show(Request $request): Response {
        return Response::html(
            $this->render('public_share', $request, ['token' => (string) $request->routeParam('token')]),
            200,
            ['Content-Security-Policy' => ContentExtractorService::videoEmbedFrameSrcHeader()],
        );
    }

    public function unlock(Request $request): Response {
        $token = (string) $request->routeParam('token');
        $share = $this->shares->findByToken($token);
        if ($share === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        if (ArticleShareRepository::isExpired($share)) {
            return Response::json(['error' => 'Expired'], 410);
        }
        if (!ArticleShareRepository::hasPassword($share)) {
            return Response::json(['unlocked' => true]);
        }

        // Backoff wächst mit der Zahl vorheriger Fehlversuche - auf 5s gedeckelt,
        // damit ein Timeout beim Client nicht als Serverfehler missverstanden wird.
        $attempts = (int) $share['failed_unlock_attempts'];
        if ($attempts > 0) {
            sleep(min($attempts, 5));
        }

        $password = (string) $request->input('password', '');
        if (!password_verify($password, (string) $share['password_hash'])) {
            $this->shares->registerFailedUnlock((int) $share['id']);
            return Response::json(['error' => 'Invalid password'], 403);
        }

        $this->shares->resetFailedUnlock((int) $share['id']);
        $this->sessions->markShareTokenUnlocked($token);

        return Response::json(['unlocked' => true]);
    }

    /** Artikeldaten + Highlights für die öffentliche Ansicht. */
    public function data(Request $request): Response {
        $token = (string) $request->routeParam('token');
        $result = $this->resolveAccessibleShare($token);
        if ($result instanceof Response) {
            return $result;
        }
        $share = $result;

        $article = $this->articles->find((int) $share['article_id'], (int) $share['user_id']);
        if ($article === null) {
            // Artikel wurde gelöscht, Share-Zeile aber (noch) nicht aufgeräumt.
            return Response::json(['error' => 'Not found'], 404);
        }

        $highlights = $this->highlights->findByArticleId((int) $article['id'], (int) $share['user_id']);

        return Response::json([
            'title' => $article['title'],
            'excerpt' => $article['excerpt'],
            'author' => $article['author'],
            'siteName' => $article['site_name'],
            'content' => $article['content'],
            'url' => $article['url'],
            'publishedAt' => $article['published_at'],
            'readingTime' => (int) $article['reading_time'],
            'highlights' => array_map(HighlightRepository::toPublicArray(...), $highlights),
        ]);
    }

    /** TTS-Streaming für den geteilten Artikel - läuft nie normal zurück. */
    public function tts(Request $request): void {
        $token = (string) $request->routeParam('token');
        $result = $this->resolveAccessibleShare($token);
        if ($result instanceof Response) {
            http_response_code($result->status());
            header('Content-Type: application/json');
            echo $result->body();
            exit();
        }
        $share = $result;

        $article = $this->articles->find((int) $share['article_id'], (int) $share['user_id']);
        if ($article === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Article not found']);
            exit();
        }

        $lang = (string) $request->query('lang', 'de');
        $speaker = $request->queryInt('speaker', -1);
        $this->ttsStream->stream($article, $lang, $speaker);
    }

    /**
     * Löst den Token auf und prüft Ablauf + Passwort-Unlock in einem Rutsch.
     * Rückgabe ist entweder die gültige Share-Zeile ODER eine fertige
     * Fehler-Response (404/410/401) - Aufrufer muss nur `instanceof Response` prüfen.
     */
    private function resolveAccessibleShare(string $token): array|Response {
        $share = $this->shares->findByToken($token);
        if ($share === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        if (ArticleShareRepository::isExpired($share)) {
            return Response::json(['error' => 'Expired'], 410);
        }
        if (ArticleShareRepository::hasPassword($share) && !$this->sessions->hasUnlockedShareToken($token)) {
            return Response::json(['locked' => true, 'hasPassword' => true], 401);
        }
        return $share;
    }

    private function render(string $template, Request $request, array $vars = []): string {
        // Kein UserSettingsRepository nötig - die öffentliche Ansicht kennt
        // keinen eingeloggten Nutzer, Session + Accept-Language reichen (siehe
        // LocaleResolver).
        $vars['t'] = Translator::forRequest($request, $this->sessions);
        $vars['requestPath'] = $request->path();
        extract($vars);
        ob_start();
        include __DIR__ . "/../../templates/{$template}.php";
        return (string) ob_get_clean();
    }
}
