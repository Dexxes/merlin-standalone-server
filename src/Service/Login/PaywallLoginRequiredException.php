<?php

declare(strict_types=1);

namespace Merlin\Service\Login;

/**
 * Eine Domain mit <login>-Unterstützung liefert weiterhin Paywall-Inhalt
 * (paywall-marker-Pattern hat gegriffen), obwohl entweder keine
 * Zugangsdaten hinterlegt sind oder der letzte Login-Versuch fehlschlug.
 *
 * Wird von ArticleController in eine eindeutige, maschinenlesbare
 * "Login erforderlich"-API-Antwort übersetzt (HTTP 428), auf der alle
 * Clients einen Login-Dialog aufbauen können - siehe PLATFORMS.md.
 */
class PaywallLoginRequiredException extends \Exception {
    public function __construct(
        public readonly string $domain,
        public readonly string $loginPage,
    ) {
        parent::__construct('Artikel liegt hinter einer Paywall, für die keine gültigen Zugangsdaten hinterlegt sind: ' . $domain);
    }
}
