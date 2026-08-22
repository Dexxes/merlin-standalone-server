<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ArticleRepository;
use Merlin\Db\TagRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

final class TagController {
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ArticleRepository $articles,
    ) {
    }

    public function index(Request $request): Response {
        $tags = $this->tags->findAll($request->authUserId());
        return Response::json(array_map(TagRepository::toPublicArray(...), $tags));
    }

    public function create(Request $request): Response {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 400);
        }
        $color = (string) $request->input('color', '#0082c9');

        $tag = $this->tags->create($request->authUserId(), $name, $color);
        return Response::json(TagRepository::toPublicArray($tag), 201);
    }

    public function update(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $userId = $request->authUserId();
        $existing = $this->tags->find($id, $userId);
        if ($existing === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $name = trim((string) $request->input('name', $existing['name']));
        $color = (string) $request->input('color', $existing['color']);

        $this->tags->update($id, $userId, $name, $color);
        return Response::json(TagRepository::toPublicArray($this->tags->find($id, $userId)));
    }

    public function destroy(Request $request): Response {
        $deleted = $this->tags->delete((int) $request->routeParam('id'), $request->authUserId());
        return $deleted ? Response::noContent() : Response::json(['error' => 'Not found'], 404);
    }

    public function addToArticle(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        $tagId = (int) $request->routeParam('tagId');

        // Beide Ownership-Prüfungen sind nötig, sonst könnte ein Nutzer per
        // erratener Artikel-ID Tags an fremde Artikel hängen (IDOR).
        if ($this->tags->find($tagId, $userId) === null || $this->articles->find($articleId, $userId) === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $this->tags->addToArticle($articleId, $tagId);
        return Response::noContent();
    }

    public function removeFromArticle(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        $tagId = (int) $request->routeParam('tagId');

        if ($this->tags->find($tagId, $userId) === null || $this->articles->find($articleId, $userId) === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $this->tags->removeFromArticle($articleId, $tagId);
        return Response::noContent();
    }
}
