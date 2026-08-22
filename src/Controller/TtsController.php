<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ArticleRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Service\TtsStreamService;

/**
 * TTS-Proxy: leitet Anfragen vom Client an den lokalen Piper-Daemon weiter.
 * Port von merlin-nextcloud/lib/Controller/TtsController.php - nur die
 * Auth (Basic Auth/Session statt Nextcloud-Login) und das Article-Lookup
 * liegen hier, die eigentliche Extraktions-/Streaming-Logik in TtsStreamService
 * (geteilt mit PublicShareController für Share-Link-TTS).
 */
final class TtsController {
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly TtsStreamService $ttsStream,
    ) {
    }

    /** GET /api/articles/{id}/tts?lang=de - läuft nie normal zurück (siehe TtsStreamService::stream()). */
    public function synthesize(Request $request): void {
        $article = $this->articles->find((int) $request->routeParam('id'), $request->authUserId());
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
}
