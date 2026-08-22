<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\LoginFlowRepository;
use Merlin\Db\UserRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

/**
 * Login-Flow-v2-Klon: bildet Nextclouds Login-Flow-v2-JSON nach (POST
 * /index.php/login/v2 → { login, poll: { token, endpoint } }, danach Polling
 * bis { server, loginName, appPassword }), damit iOS/Android/Firefox/Chrome/
 * Thunderbird ihre bestehende Login-Flow-Logik unverändert gegen
 * merlin-server nutzen können - nur die Start-URL unterscheidet sich
 * clientseitig je Backend-Typ. Der HTML-Formular-Teil (GET/POST
 * /login/v2/flow/{token}) liegt in PageController, da er dasselbe
 * Template wie /login wiederverwendet.
 */
final class LoginFlowController {
    private const TTL_SECONDS = 600; // 10 Minuten, wie der 5-Minuten-Timeout der Clients grosszügig bemessen

    public function __construct(
        private readonly LoginFlowRepository $flows,
        private readonly UserRepository $users,
    ) {
    }

    public function start(Request $request): Response {
        $this->flows->deleteExpired();

        $flowToken = bin2hex(random_bytes(32));
        $pollToken = bin2hex(random_bytes(32));
        $expiresAt = gmdate('c', time() + self::TTL_SECONDS);
        $this->flows->create($flowToken, $pollToken, $expiresAt);

        $base = $this->baseUrl($request);

        return Response::json([
            'login' => "{$base}/login/v2/flow/{$flowToken}",
            'poll' => [
                'token' => $pollToken,
                'endpoint' => "{$base}/login/v2/poll",
            ],
        ]);
    }

    public function poll(Request $request): Response {
        $pollToken = (string) $request->input('token', '');
        $flow = $pollToken === '' ? null : $this->flows->findByPollToken($pollToken);

        if ($flow === null || $flow['user_id'] === null || $flow['expires_at'] < gmdate('c')) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $user = $this->users->findById((int) $flow['user_id']);
        if ($user === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        // Single-Use wie Nextclouds Poll-Endpunkt: ein zweiter Poll-Versuch
        // mit demselben Token darf das Klartext-Token nicht erneut liefern.
        $this->flows->delete((int) $flow['id']);

        return Response::json([
            'server' => $this->baseUrl($request),
            'loginName' => $user['username'],
            'appPassword' => $flow['api_token_plaintext'],
        ]);
    }

    private function baseUrl(Request $request): string {
        $scheme = $request->isHttps() ? 'https' : 'http';
        $basePath = Response::getBasePath();
        return "{$scheme}://{$request->host()}{$basePath}";
    }
}
