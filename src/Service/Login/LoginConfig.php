<?php

declare(strict_types=1);

namespace Merlin\Service\Login;

/**
 * Geparste <login>-Sektion einer Bundle-Domain-Config (z. B. content-filters/
 * tagesspiegel.de.xml). Bewusst BUNDLE-ONLY: <login> ist kein Teil von
 * ContentFilterSchema::SECTIONS und läuft deshalb nie durch
 * ContentFilterMerger/-Validator - weder Admin noch Nutzer können darüber
 * einen eigenen Login-Endpoint definieren (das wäre ein SSRF-/Credential-
 * Exfiltrations-Vektor: ein Nutzer könnte sonst sein eigenes Passwort-Feld an
 * eine beliebige URL schicken lassen). Gelesen wird ausschliesslich das rohe
 * Bundle-XML (ContentFilterRepository::readBundle()), siehe
 * SiteCredentialService::loadLoginConfig().
 */
final class LoginConfig {
    /**
     * @param list<string> $persistCookieNames
     */
    public function __construct(
        public readonly string $type,
        public readonly string $page,
        public readonly string $ajaxEndpointUrl,
        public readonly array $persistCookieNames,
        /** Anbieter-Kennung innerhalb einer Plattform, z. B. Pianos "service"-Feld ("tsp" für Tagesspiegel). Optional, providerspezifisch. */
        public readonly string $service = '',
        /**
         * Optionales PCRE-Pattern, das im rohen HTML einer Artikelseite auf
         * "diese Seite ist (noch) hinter der Paywall" prüft, z. B.
         * '/"showPaywall"\s*:\s*true/' bei Tagesspiegel. Erkennt
         * ContentExtractorService das Pattern trotz gesetztem Session-Cookie
         * (oder mangels Zugangsdaten) im HTML, wirft es
         * PaywallLoginRequiredException statt einen leeren/kaputten Artikel
         * zu liefern. null, wenn die Domain keinen Marker konfiguriert hat
         * (Paywall-Erkennung entfällt dann, Cookie-Injektion bleibt aktiv).
         */
        public readonly ?string $paywallMarkerPattern = null,
    ) {
    }

    public static function fromXml(\SimpleXMLElement $login): self {
        $ajax = $login->{'ajax-endpoint'};
        $persistCookieNames = [];
        foreach ($login->{'persist-cookie'} as $cookie) {
            $name = trim((string) ($cookie['name'] ?? ''));
            if ($name !== '') {
                $persistCookieNames[] = $name;
            }
        }

        $markerNode = $login->{'paywall-marker'};
        $marker     = isset($markerNode['regex']) ? trim((string) $markerNode['regex']) : '';

        return new self(
            type: trim((string) ($login['type'] ?? '')),
            page: trim((string) ($login['page'] ?? '')),
            ajaxEndpointUrl: trim((string) ($ajax['url'] ?? '')),
            persistCookieNames: $persistCookieNames,
            service: trim((string) ($login['service'] ?? '')),
            paywallMarkerPattern: $marker !== '' ? $marker : null,
        );
    }

    public function isValid(): bool {
        return $this->type !== ''
            && filter_var($this->page, FILTER_VALIDATE_URL) !== false
            && filter_var($this->ajaxEndpointUrl, FILTER_VALIDATE_URL) !== false
            && $this->persistCookieNames !== [];
    }
}
