<?php

declare(strict_types=1);

namespace Merlin\I18n;

use Merlin\Auth\SessionService;
use Merlin\Db\UserSettingsRepository;
use Merlin\Http\Request;

/**
 * Lädt die von tools/i18n/export.py generierten lang/{locale}.php-Dateien
 * (flaches Array, Dot-Key -> String bzw. ['one'=>..,'other'=>..] für
 * Pluralformen) und übersetzt Keys mit {name}-Platzhaltern. Anders als
 * merlin-nextclouds gettext-Ansatz (EN-Literal = Key) ist der Key hier ein
 * sprechender Dot-Pfad, weil es keine gettext-Infrastruktur gibt - siehe
 * "Sonderfall merlin-server" in localization/schema.md.
 */
final class Translator {
    /** @var array<string, string|array<string, string>> */
    private readonly array $strings;

    public function __construct(private readonly string $locale) {
        $file = __DIR__ . "/lang/{$this->locale}.php";
        /** @var array<string, string|array<string, string>> $strings */
        $strings = is_file($file) ? require $file : [];
        $this->strings = $strings;
    }

    /**
     * Baut den Translator für den aktuellen Request in einem Schritt
     * (LocaleResolver::resolve() + Konstruktion) - der übliche Aufruf aus
     * Controllern.
     */
    public static function forRequest(
        Request $request,
        SessionService $sessions,
        ?UserSettingsRepository $userSettings = null,
        ?int $userId = null,
    ): self {
        return new self(LocaleResolver::resolve($request, $sessions, $userSettings, $userId));
    }

    public function locale(): string {
        return $this->locale;
    }

    /** @param array<string, scalar> $params */
    public function t(string $key, array $params = []): string {
        $value = $this->strings[$key] ?? $key;
        if (is_array($value)) {
            $value = $value['other'] ?? $value['one'] ?? $key;
        }
        return $this->interpolate((string) $value, $params);
    }

    /** CLDR one/other, ergänzt {count} automatisch (siehe schema.md-Plural-Konvention). */
    public function n(string $key, int $count): string {
        $value = $this->strings[$key] ?? $key;
        if (is_array($value)) {
            $form = $count === 1
                ? ($value['one'] ?? $value['other'] ?? $key)
                : ($value['other'] ?? $value['one'] ?? $key);
        } else {
            $form = $value;
        }
        return $this->interpolate((string) $form, ['count' => $count]);
    }

    /**
     * Whitelisteter Teilausschnitt für die Einbettung als JSON in
     * <script>-Blöcke (siehe partials/header.php `I18N`-Objekt) - bewusst
     * kein globaler Dump aller Keys, damit die Seite nicht unnötig aufbläht.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public function forJs(array $keys): array {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->t($key);
        }
        return $out;
    }

    /** @param array<string, scalar> $params */
    private function interpolate(string $text, array $params): string {
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}
