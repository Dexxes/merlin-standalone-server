<?php

declare(strict_types=1);

namespace Merlin\I18n;

use Merlin\Auth\SessionService;
use Merlin\Db\UserSettingsRepository;
use Merlin\Http\Request;

/**
 * Ermittelt die aktive Sprache für einen Request. Reihenfolge (erste
 * unterstützte Quelle gewinnt): persönliche Einstellung des eingeloggten
 * Nutzers (user_settings, Key "language") > PHP-Session (deckt
 * ausgeloggte Seiten ab: Login/Registrierung/Passwort/Public-Share) >
 * Accept-Language-Header > DEFAULT. Der explizite Sprachwechsel selbst
 * (GET /lang/{code}) schreibt in Session bzw. user_settings und läuft NICHT
 * über resolve() - das bleibt bewusst eine reine Lesefunktion ohne
 * Seiteneffekte, siehe PageController::setLanguage().
 */
final class LocaleResolver {
    public const array SUPPORTED = ['de', 'en'];

    // Bisheriges Verhalten war Deutsch-only - als Default unauffällig für
    // bestehende Installationen ohne gespeicherte Präferenz.
    public const string DEFAULT = 'de';

    public static function resolve(
        Request $request,
        SessionService $sessions,
        ?UserSettingsRepository $userSettings = null,
        ?int $userId = null,
    ): string {
        if ($userId !== null && $userSettings !== null) {
            $stored = $userSettings->getAllForUser($userId)['language'] ?? null;
            if (is_string($stored) && in_array($stored, self::SUPPORTED, true)) {
                return $stored;
            }
        }

        $sessionLocale = $sessions->language();
        if ($sessionLocale !== null && in_array($sessionLocale, self::SUPPORTED, true)) {
            return $sessionLocale;
        }

        foreach (self::parseAcceptLanguage((string) $request->header('accept-language')) as $tag) {
            if (in_array($tag, self::SUPPORTED, true)) {
                return $tag;
            }
        }

        return self::DEFAULT;
    }

    /**
     * Sprachcodes aus dem Accept-Language-Header in Präferenzreihenfolge
     * (q-Werte berücksichtigt), Regionen abgeschnitten (de-AT -> de).
     *
     * @return list<string>
     */
    private static function parseAcceptLanguage(string $header): array {
        if (trim($header) === '') {
            return [];
        }
        $entries = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = explode(';q=', $part);
            $tag = strtolower(explode('-', trim($pieces[0]))[0]);
            $q = isset($pieces[1]) ? (float) $pieces[1] : 1.0;
            $entries[] = [$tag, $q];
        }
        usort($entries, static fn (array $a, array $b) => $b[1] <=> $a[1]);

        $result = [];
        foreach ($entries as [$tag]) {
            if (!in_array($tag, $result, true)) {
                $result[] = $tag;
            }
        }
        return $result;
    }
}
