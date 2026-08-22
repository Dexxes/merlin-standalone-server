<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ArticleRepository;
use Merlin\Db\HighlightRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

final class HighlightController {
    public function __construct(
        private readonly HighlightRepository $highlights,
        private readonly ArticleRepository $articles,
    ) {
    }

    public function index(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        if ($this->articles->find($articleId, $userId) === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $highlights = $this->highlights->findByArticleId($articleId, $userId);
        return Response::json(array_map(HighlightRepository::toPublicArray(...), $highlights));
    }

    public function create(Request $request): Response {
        $userId = $request->authUserId();
        $articleId = (int) $request->routeParam('articleId');
        if ($this->articles->find($articleId, $userId) === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $required = ['highlightedText', 'startXpath', 'startOffset', 'endXpath', 'endOffset'];
        $data = [];
        foreach ($required as $field) {
            $value = $request->input($field);
            if ($value === null) {
                return Response::json(['error' => "{$field} is required"], 400);
            }
            $data[$field] = $value;
        }
        $data['startOffset'] = (int) $data['startOffset'];
        $data['endOffset'] = (int) $data['endOffset'];
        $data['color'] = (string) $request->input('color', '#ffeb3b');

        $highlight = $this->highlights->create($articleId, $userId, $data);
        return Response::json(HighlightRepository::toPublicArray($highlight), 201);
    }

    public function destroy(Request $request): Response {
        $deleted = $this->highlights->delete((int) $request->routeParam('id'), $request->authUserId());
        return $deleted ? Response::noContent() : Response::json(['error' => 'Not found'], 404);
    }
}
