<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ArticleRepository;
use Merlin\Db\ArticleShareRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

/**
 * Verwaltung von öffentlichen Share-Links für Artikel - Port von
 * merlin-nextcloud/lib/Controller/ShareController.php. Die eigentliche
 * öffentliche Auslieferung (Lesen ohne Login) übernimmt PublicShareController;
 * dieser Controller läuft wie alle anderen /api/*-Endpunkte hinter AuthMiddleware.
 */
final class ShareController {
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ArticleShareRepository $shares,
    ) {
    }

    public function show(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        if ($this->articles->find($articleId, $userId) === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $share = $this->shares->findByArticleId($articleId, $userId);
        if ($share === null) {
            return Response::json(['enabled' => false]);
        }
        return Response::json(['enabled' => true] + ArticleShareRepository::toPublicArray($share, $this->buildUrl($request, $share['token'])));
    }

    /**
     * Idempotent: existiert bereits ein Link, wird dieser unverändert
     * zurückgegeben statt einen zweiten anzulegen.
     */
    public function create(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        if ($this->articles->find($articleId, $userId) === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $existing = $this->shares->findByArticleId($articleId, $userId);
        if ($existing !== null) {
            return Response::json(['enabled' => true] + ArticleShareRepository::toPublicArray($existing, $this->buildUrl($request, $existing['token'])));
        }

        $password = $request->input('password');
        $expiresAt = $this->parseExpiresAt($request->input('expiresAt'));

        $share = $this->shares->create(
            $articleId,
            $userId,
            $this->newToken(),
            $password !== null && $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            $expiresAt,
        );

        return Response::json(['enabled' => true] + ArticleShareRepository::toPublicArray($share, $this->buildUrl($request, $share['token'])), 201);
    }

    /**
     * Passwort und/oder Ablaufdatum ändern. `password`/`expiresAt` = null oder
     * leerer String entfernt Passwortschutz/Ablauf; wird ein Feld im
     * Request-Body gar nicht mitgeschickt, bleibt es unverändert -
     * Unterscheidung über ein Sentinel statt zusätzlicher Bool-Flags,
     * identisch zum Nextcloud-Original.
     */
    public function update(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');

        $share = $this->shares->findByArticleId($articleId, $userId);
        if ($share === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $unset = "\0__unset__\0";
        $fields = [];

        $password = $request->input('password', $unset);
        if ($password !== $unset) {
            $fields['password_hash'] = $password !== null && $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        }

        $expiresAt = $request->input('expiresAt', $unset);
        if ($expiresAt !== $unset) {
            $fields['expires_at'] = $this->parseExpiresAt($expiresAt);
        }

        $saved = $this->shares->update((int) $share['id'], $fields);
        return Response::json(['enabled' => true] + ArticleShareRepository::toPublicArray($saved, $this->buildUrl($request, $saved['token'])));
    }

    /** Token austauschen - alter Link wird sofort ungültig, Passwort/Ablauf bleiben erhalten. */
    public function regenerate(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');

        $share = $this->shares->findByArticleId($articleId, $userId);
        if ($share === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $saved = $this->shares->update((int) $share['id'], ['token' => $this->newToken()]);
        return Response::json(['enabled' => true] + ArticleShareRepository::toPublicArray($saved, $this->buildUrl($request, $saved['token'])));
    }

    public function destroy(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        $this->shares->deleteByArticleId($articleId, $userId);
        return Response::noContent();
    }

    private function newToken(): string {
        return bin2hex(random_bytes(16));
    }

    private function parseExpiresAt(?string $expiresAt): ?string {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }
        try {
            return (new \DateTime($expiresAt))->format('c');
        } catch (\Exception) {
            return null;
        }
    }

    private function buildUrl(Request $request, string $token): string {
        $scheme = $request->isHttps() ? 'https' : 'http';
        $basePath = Response::getBasePath();
        return "{$scheme}://{$request->host()}{$basePath}/s/{$token}";
    }
}
