<?php

declare(strict_types=1);

namespace Merlin\Service\Login;

/**
 * Ergebnis eines erfolgreichen LoginProviderInterface::login()-Aufrufs.
 */
final class LoginResult {
    /**
     * @param array<string,string> $cookies Cookie-Name => Wert (nur
     *        LoginConfig::$persistCookieNames)
     */
    public function __construct(
        public readonly array $cookies,
        /** Frühester Ablauf der zurückgegebenen Cookies, oder null wenn keins ein Max-Age/Expires trug (Session-Cookie). */
        public readonly ?\DateTime $expiresAt,
    ) {
    }
}
