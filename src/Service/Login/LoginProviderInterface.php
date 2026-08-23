<?php

declare(strict_types=1);

namespace Merlin\Service\Login;

/**
 * Ein LoginProvider führt den Login-Ablauf EINES Paywall-Anbieter-Typs aus
 * (z. B. "piano-json-form" für Piano-ID-basierte Zeitungen wie
 * tagesspiegel.de) und liefert die Session-Cookies zurück, die danach beim
 * Artikel-Fetch mitgeschickt werden. Welcher Provider für eine Domain
 * zuständig ist, bestimmt das type-Attribut ihrer <login>-Sektion, siehe
 * LoginConfig und SiteCredentialService::PROVIDERS.
 */
interface LoginProviderInterface {
    /**
     * @throws LoginFailedException bei falschen Zugangsdaten oder wenn der
     *         Login-Ablauf der Seite nicht (mehr) durchlaufen werden konnte.
     */
    public function login(string $username, string $password, LoginConfig $config): LoginResult;
}
