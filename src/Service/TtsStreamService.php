<?php

declare(strict_types=1);

namespace Merlin\Service;

/**
 * TTS-Streaming: HTML → Plaintext → Piper-Daemon → MP3-Stream direkt an den
 * Client. Port von merlin-nextcloud/lib/Service/TtsStreamService.php - nahezu
 * unverändert, nur die Daemon-URL kommt jetzt aus der Config statt einer
 * Konstante (siehe config/config.sample.php, Schlüssel tts.daemon_url) und
 * Artikel werden als Array (ArticleRepository-Zeile) statt Entity übergeben.
 *
 * Wird sowohl vom authentifizierten Endpunkt (TtsController, Basic Auth/
 * Session) als auch vom öffentlichen Share-Endpunkt (PublicShareController,
 * Token/Passwort) genutzt, damit die sicherheitskritische curl-Proxy-Logik
 * nicht dupliziert wird.
 */
final class TtsStreamService {
    /** Erlaubte Sprachen (müssen mit geladenen Piper-Modellen übereinstimmen) */
    private const SUPPORTED_LANGS = ['de', 'en', 'es', 'fr', 'it'];

    // Verzögerungsstrings, um den Decoder aufzuwärmen (überbrücken den
    // Player-Start auf Client-Seite).
    private const START_TEXT = [
        'de' => 'DODODODODODODODODODODO. ',
        'en' => 'DODODODODODODODODODODODODO. ',
        'es' => 'DODODODODODODODODODODODODODO. ',
        'fr' => 'DODO-KO-KO. ',
        'it' => 'DODODODODODODODODODODODODODODODODO. ',
    ];

    public function __construct(private readonly string $daemonUrl) {
    }

    /**
     * Extrahiert Text aus dem Artikel, spricht ihn per Piper-Daemon und
     * streamt das Ergebnis direkt als MP3 an den Client. Beendet den
     * PHP-Prozess selbst per exit() - läuft daher NIE normal zurück.
     *
     * @param array $article Zeile aus ArticleRepository (title/content/excerpt)
     */
    public function stream(array $article, string $lang = 'de', int $speaker = -1): void {
        // Deutsches Modell hat nur einen brauchbaren Sprecher (Index 7) -
        // unabhängig davon, was der Aufrufer übergeben hat.
        if (strtolower(substr($lang, 0, 2)) === 'de') {
            $speaker = 7;
        }

        // ── 1. Sprache normalisieren ──────────────────────────────────────────
        $lang = strtolower(substr($lang, 0, 2));
        if (!in_array($lang, self::SUPPORTED_LANGS, true)) {
            $lang = 'de';
        }

        // ── 2. HTML → Plaintext ───────────────────────────────────────────────
        $html = $article['content'] ?: ($article['excerpt'] ?? '');
        $plaintext = $this->extractPlainText((string) ($article['title'] ?? ''), (string) $html, $lang);

        if ($plaintext === '') {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Article has no readable text']);
            exit();
        }

        // ── 3. Daemon-Session anlegen (POST /synthesize) ──────────────────────
        // speaker: -1 = nicht übergeben (Modell-Default), >= 0 = expliziter Speaker-Index
        $payload = json_encode(
            $speaker >= 0
                ? ['text' => $plaintext, 'lang' => $lang, 'speaker' => $speaker]
                : ['text' => $plaintext, 'lang' => $lang],
            JSON_THROW_ON_ERROR,
        );

        $ch = curl_init($this->daemonUrl . '/synthesize');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch);

        if ($curlErr !== 0 || $httpCode !== 201) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'TTS daemon unavailable']);
            exit();
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data) || !isset($data['session_id'])) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid daemon response']);
            exit();
        }

        $sessionId = $data['session_id'];

        // ── 4. Output-Buffering deaktivieren + Timeouts anpassen ─────────────
        set_time_limit(0);
        ignore_user_abort(true);
        while (ob_get_level()) {
            ob_end_clean();
        }
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', 'off');
        ob_implicit_flush(true);

        // ── 5. Response-Header ────────────────────────────────────────────────
        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: identity');
        header('Accept-Ranges: none');

        // ── 6. MP3-Stream vom Daemon direkt an den Client pipen ──────────────
        $url = $this->daemonUrl . '/stream/' . $sessionId;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_BUFFERSIZE => 1024,
            CURLOPT_HTTPHEADER => ['Range:'],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data): int {
                if (connection_aborted()) {
                    return -1;
                }
                echo $data;
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $streamErrno = curl_errno($ch);

        // CURLE_WRITE_ERROR (23) = Client hat getrennt → normal, kein Cleanup
        // nötig. Bei jedem anderen curl-Fehler ist die Daemon-Session noch
        // offen → explizit per DELETE freigeben.
        if ($streamErrno !== 0 && $streamErrno !== 23) {
            $delCh = curl_init($this->daemonUrl . '/stream/' . $sessionId);
            curl_setopt_array($delCh, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_exec($delCh);
        }

        // KRITISCH: sofort beenden, bevor der Router noch eigene Response-Bytes
        // anhängt - ohne exit() empfängt der Player ungültige Bytes am Ende
        // des Streams.
        exit();
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function entferneBildunterschriften(string $html): string {
        $html = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/is', '', $html);
        $html = preg_replace('/<img([^>]*)\s(alt|title)=["\'][^"\']*["\']/i', '<img$1', $html);
        $html = preg_replace('/<img([^>]*)\s(alt|title)=["\']\s*["\']/i', '<img$1', $html);
        $html = preg_replace('/<img([^>]*)>\s*([^<]+)/i', '<img$1>', $html);
        return $html;
    }

    private function extractPlainText(string $title, string $html, string $lang = 'de'): string {
        $sep = " , , , ";
        $html = $this->entferneBildunterschriften($html);
        $html = (string) preg_replace('#<h[1-6](\s[^>]*)?>#i', $sep . '. ', $html);
        $html = (string) preg_replace('#</h[1-6]\s*>#i', '. ' . $sep, $html);
        $html = (string) preg_replace(
            '#</(?:p|div|h[1-6]|li|blockquote|section|article)\s*>#i',
            $sep,
            $html,
        );
        $html = (string) preg_replace('#<br\s*/?>#i', $sep, $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = (string) preg_replace('/\s+/', ' ', $text);

        $text = (string) preg_replace(
            '/(' . preg_quote($sep, '/') . '\s*)+/',
            ', ',
            $text,
        );

        $text = (string) preg_replace('/([a-zäöüßA-ZÄÖÜ0-9])([.!?])\s*,\s*/u', '$1$2 ', $text);

        $text = trim($text);

        if ($lang === 'de') {
            $text = $this->expandGermanAbbreviations($text);
        }

        $text = (self::START_TEXT[$lang] ?? self::START_TEXT['de']) . $text;

        return $text;
    }

    /**
     * Löst gängige deutsche Abkürzungen in ausgeschriebene Formen auf,
     * damit Piper sie korrekt vorliest statt zu buchstabieren.
     */
    private function expandGermanAbbreviations(string $text): string {
        $replacements = [
            '/\bz\.\s*B\./u' => 'zum Beispiel',
            '/\bu\.\s*a\./u' => 'unter anderem',
            '/\bd\.\s*h\./u' => 'das heißt',
            '/\bo\.\s*ä\./u' => 'oder ähnlichem',
            '/\bu\.\s*U\./u' => 'unter Umständen',
            '/\bv\.\s*a\./u' => 'vor allem',
            '/\bca\./u' => 'circa',
            '/\bbzw\./u' => 'beziehungsweise',
            '/\busw\./u' => 'und so weiter',
            '/\betc\./u' => 'et cetera',
            '/\bvgl\./iu' => 'vergleiche',
            '/\bggf\./u' => 'gegebenenfalls',
            '/\bevtl\./u' => 'eventuell',
            '/\bbspw\./u' => 'beispielsweise',
            '/\bsog\./u' => 'sogenannte',
            '/\binkl\./u' => 'inklusive',
            '/\bexkl\./u' => 'exklusive',
            '/\bMrd\./u' => 'Milliarden',
            '/\bMio\./u' => 'Millionen',
            '/\bNr\./u' => 'Nummer',
            '/\bProf\./u' => 'Professor',
            '/\bDr\./u' => 'Doktor',
            '/\bvs\./u' => 'versus',
        ];

        return (string) preg_replace(
            array_keys($replacements),
            array_values($replacements),
            $text,
        );
    }
}
