<?php

declare(strict_types=1);

namespace Merlin\Service;

use Merlin\Db\ContentFilterRepository;
use Merlin\Db\UserSettingsRepository;

/**
 * Port von merlin-nextcloud/lib/Service/SupportBoxService.php: Daten für die
 * Support-Infobox ("Dir gefällt der Artikel von …? Überlege ein Abo
 * abzuschließen oder zu spenden"), die die Clients beim Rendern zwischen
 * zwei Absätze setzen.
 *
 * Warum ein Datenfeld und kein HTML im gespeicherten Content: ein eingefügter
 * Absatz würde Highlight-XPaths verschieben, vom TTS mitgelesen, in Export und
 * Share-Link mitgeliefert und wäre nach dem späteren Hinterlegen von
 * Zugangsdaten nicht mehr entfernbar.
 *
 * Quellen in der (gemergten) Domain-Config:
 *   <paywall><subscribe url="…"/></paywall>   - Abo-Seite
 *   <metadata><donations url="…"/></metadata> - Spenden-Seite
 */
final class SupportBoxService {
    public const DEFAULT_ACCENT = '#FF3B30';

    public function __construct(
        private readonly ContentFilterRepository $filters,
        private readonly SiteCredentialService $siteCredentials,
        private readonly UserSettingsRepository $userSettings,
    ) {
    }

    /**
     * Box für den eingeloggten Leser: entfällt, wenn er für die Seite einen
     * aktiven Abo-Login hinterlegt hat.
     *
     * @param array<string,mixed> $article Zeile aus ArticleRepository::find()
     * @return array{siteName:string,subscribeUrl:?string,donationsUrl:?string,accentColor:string,iconUrl:?string}|null
     */
    public function forReader(array $article, int $userId): ?array {
        return $this->build($article, $userId, true);
    }

    /**
     * Box für die öffentliche Share-Ansicht: immer, unabhängig vom Login des
     * Erstellers; Akzentfarbe des Erstellers ($ownerUserId).
     *
     * @param array<string,mixed> $article Zeile aus ArticleRepository::find()
     * @return array{siteName:string,subscribeUrl:?string,donationsUrl:?string,accentColor:string,iconUrl:?string}|null
     */
    public function forShare(array $article, int $ownerUserId): ?array {
        return $this->build($article, $ownerUserId, false);
    }

    /**
     * @param array<string,mixed> $article
     * @return array{siteName:string,subscribeUrl:?string,donationsUrl:?string,accentColor:string,iconUrl:?string}|null
     */
    private function build(array $article, int $userId, bool $hideWithLogin): ?array {
        $domain = $this->filters->normalizeUrlDomain((string) ($article['url'] ?? ''));
        if ($domain === '') {
            return null;
        }

        $config = $this->filters->getMerged($domain, (string) $userId);
        if ($config === null) {
            return null;
        }

        $subscribeUrl = $this->firstUrl($config->xpath('paywall/subscribe') ?: []);
        $donationsUrl = $this->firstUrl($config->xpath('metadata/donations') ?: []);
        if ($subscribeUrl === null && $donationsUrl === null) {
            return null;
        }

        if ($hideWithLogin && $this->siteCredentials->hasActiveLogin($userId, $domain)) {
            return null;
        }

        $siteName = trim((string) ($article['site_name'] ?? ''));

        return [
            'siteName' => $siteName !== '' ? $siteName : $domain,
            'subscribeUrl' => $subscribeUrl,
            'donationsUrl' => $donationsUrl,
            'accentColor' => $this->accentColor($userId),
            'iconUrl' => $this->iconUrl($article),
        ];
    }

    /**
     * Erste gültige absolute http(s)-URL aus den url-Attributen. Der Validator
     * prüft das schon beim Speichern; hier nochmal, weil die URL in ein
     * href-Attribut der Clients wandert (kein javascript:/data:).
     *
     * @param iterable<\SimpleXMLElement> $rules
     */
    private function firstUrl(iterable $rules): ?string {
        foreach ($rules as $rule) {
            $candidate = trim((string) ($rule['url'] ?? ''));
            if ($candidate === '') {
                continue;
            }
            $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
            if (($scheme === 'http' || $scheme === 'https') && filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Icon der konkreten Artikelseite (beim Extrahieren gelesen, siehe
     * ContentExtractorService::extractSiteIconUrl()). Bei Artikeln aus der Zeit
     * vor der Spalte site_icon_url: /favicon.ico der Origin - ohne zusätzlichen
     * Request beim Öffnen; ein nicht ladbares Bild blendet der Client aus.
     */
    private function iconUrl(array $article): ?string {
        $stored = trim((string) ($article['site_icon_url'] ?? ''));
        if ($stored !== '' && $this->isHttpUrl($stored)) {
            return $stored;
        }
        $parts = parse_url((string) ($article['url'] ?? ''));
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/favicon.ico';
    }

    private function isHttpUrl(string $candidate): bool {
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        return ($scheme === 'http' || $scheme === 'https') && filter_var($candidate, FILTER_VALIDATE_URL) !== false;
    }

    private function accentColor(int $userId): string {
        $value = $this->userSettings->getAllForUser($userId)['accentColor'] ?? self::DEFAULT_ACCENT;
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : self::DEFAULT_ACCENT;
    }
}
