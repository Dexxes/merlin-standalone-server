<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Auth\ApiTokenService;
use Merlin\Db\ApiTokenRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

final class AccountController {
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ApiTokenService $tokenService,
    ) {
    }

    public function listTokens(Request $request): Response {
        $tokens = $this->tokens->findByUser($request->authUserId());
        return Response::json(array_map(ApiTokenRepository::toPublicArray(...), $tokens));
    }

    public function createToken(Request $request): Response {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 400);
        }

        $result = $this->tokenService->create($request->authUserId(), $name);
        return Response::json([
            ...ApiTokenRepository::toPublicArray($result['token']),
            'plainText' => $result['plainText'],
        ], 201);
    }

    public function revokeToken(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $revoked = $this->tokens->revoke($id, $request->authUserId());
        return $revoked ? Response::noContent() : Response::json(['error' => 'Not found'], 404);
    }
}
