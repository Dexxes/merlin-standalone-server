<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Service\Login\LoginFailedException;
use Merlin\Service\SiteCredentialService;

/**
 * Port von merlin-nextcloud/lib/Controller/SiteCredentialController.php:
 * Personal-API für Paywall-Abo-Zugangsdaten (z. B. Tagesspiegel Plus). Jeder
 * eingeloggte Nutzer verwaltet seine eigenen, privaten Zugangsdaten je Domain
 * - $userId kommt aus $request->authUserId(), nie aus einem Request-
 * Parameter (analog UserContentFilterController).
 *
 * Passwörter werden NIE zurückgegeben - weder im Klartext noch verschlüsselt.
 */
final class SiteCredentialController {
    public function __construct(
        private readonly SiteCredentialService $service,
    ) {
    }

    public function index(Request $request): Response {
        return Response::json($this->service->listForUser($request->authUserId()));
    }

    /**
     * Legt Zugangsdaten für $domain an/überschreibt sie und führt sofort
     * einen Login-Versuch aus, damit die UI direkt Rückmeldung geben kann.
     */
    public function update(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return Response::json(['message' => 'Benutzername und Passwort sind erforderlich.'], 400);
        }

        try {
            $this->service->saveAndLogin($request->authUserId(), $domain, $username, $password);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['message' => $e->getMessage()], 400);
        } catch (LoginFailedException $e) {
            // Zugangsdaten sind trotzdem gespeichert (siehe
            // SiteCredentialService::saveAndLogin()), damit ein späterer
            // automatischer Retry ohne erneute Eingabe funktioniert, falls
            // der Fehlschlag nur vorübergehend war (z. B. Seite kurz down).
            return Response::json(['message' => $e->getMessage(), 'reason' => $e->reason], 401);
        }

        return Response::json(['domain' => $domain, 'status' => 'ok']);
    }

    public function destroy(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        $this->service->delete($request->authUserId(), $domain);
        return Response::json(['domain' => $domain, 'deleted' => true]);
    }
}
