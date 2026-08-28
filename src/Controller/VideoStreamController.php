<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ArticleRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Service\VideoStreamResolverService;

/**
 * Liefert die aktuell aufgelöste HLS-Stream-URL für Artikel von ARD
 * Mediathek/ZDF/Arte - siehe VideoStreamResolverService-Docblock für den
 * Hintergrund (bewusste, risikobehaftete Produktentscheidung, kein
 * offizieller Embed-Weg). Reine Auflösung pro Request, nichts wird
 * gespeichert/gecacht. Port von merlin-nextclouds VideoStreamController.
 */
final class VideoStreamController {
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly VideoStreamResolverService $videoStreamResolver,
    ) {
    }

    public function resolve(Request $request): Response {
        $article = $this->articles->find((int) $request->routeParam('id'), $request->authUserId());
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $resolved = $this->videoStreamResolver->resolve((string) $article['url']);
        if ($resolved === null) {
            return Response::json(['available' => false]);
        }

        return Response::json([
            'available'    => true,
            'type'         => $resolved['type'],
            'variants'     => $resolved['variants'],
            'defaultIndex' => $resolved['defaultIndex'],
        ]);
    }
}
