<?php

declare(strict_types=1);

namespace Merlin\Service\Login;

use Merlin\Db\SiteCredential;
use Merlin\Service\Http\SsrfSafeResolver;
use Psr\Log\LoggerInterface;

/**
 * Login-Provider für Piano-ID-basierte Zeitungsportale, deren Login-Formular
 * über eine eigene, domain-gehostete /ajax/login-Route läuft statt direkt
 * gegen Pianos id.piano.io (type="piano-json-form" in der <login>-Sektion,
 * siehe content-filters/tagesspiegel.de.xml).
 *
 * Ablauf (per HAR-Aufzeichnung des Tagesspiegel-Logins reverse-engineered,
 * siehe Konversation, die dieses Feature angelegt hat):
 *
 *   1. GET  {page}            – liefert per Set-Cookie ein frisches
 *                                XSRF-TOKEN + aboportal_session (CSRF-Priming).
 *   2. POST {ajax-endpoint}   – Content-Type: application/json, X-CSRF-TOKEN/
 *                                X-XSRF-TOKEN = XSRF-TOKEN-Cookie aus Schritt 1
 *                                (Double-Submit-Cookie-CSRF), Body ist ein
 *                                generisches Form-Schema-JSON (AuthForm/
 *                                AuthBlock) mit E-Mail/Passwort als Feldwerte.
 *                                Die interessanten Daten stehen NICHT im
 *                                Response-Body, sondern in dessen
 *                                Set-Cookie-Headern (sso_token, sso_user_data,
 *                                authId, __utp bei Tagesspiegel - Max-Age
 *                                jeweils ~365 Tage, domain=tagesspiegel.de,
 *                                gilt also auch für www.tagesspiegel.de).
 *
 * WARTUNG: Das JSON-Body-Schema in buildLoginBody() ist 1:1 aus einem echten
 * Request kopiert (Labels/Validierungstexte inklusive). Ändert die Seite ihr
 * Formular (neues Pflichtfeld, anderes Feld-Layout), bricht der Login mit
 * STATUS_LOGIN_FLOW_BROKEN ab - erster Blick dann: HAR-Aufzeichnung eines
 * frischen Logins im Browser, Diff gegen buildLoginBody().
 */
class PianoJsonFormLoginProvider implements LoginProviderInterface {
    use SsrfSafeResolver;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Merlin-Content-Extractor';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function login(string $username, string $password, LoginConfig $config): LoginResult {
        if ($config->service === '') {
            throw new LoginFailedException(
                'Login-Konfiguration unvollständig: service-Attribut fehlt in <login>',
                SiteCredential::STATUS_LOGIN_FLOW_BROKEN
            );
        }

        $primingCookies = $this->primeCsrfCookies($config->page);
        $xsrfToken      = $primingCookies['XSRF-TOKEN'] ?? null;
        if ($xsrfToken === null) {
            throw new LoginFailedException(
                'Kein XSRF-TOKEN-Cookie von der Login-Seite erhalten - Login-Ablauf hat sich vermutlich geändert',
                SiteCredential::STATUS_LOGIN_FLOW_BROKEN
            );
        }

        [$status, $responseCookies] = $this->postLogin($config, $primingCookies, $xsrfToken, $username, $password);

        if ($status === 401 || $status === 403 || $status === 422) {
            throw new LoginFailedException(
                'Login abgelehnt (HTTP ' . $status . ') - Zugangsdaten vermutlich falsch',
                SiteCredential::STATUS_INVALID_CREDENTIALS
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new LoginFailedException(
                'Unerwarteter HTTP-Status beim Login: ' . $status,
                SiteCredential::STATUS_LOGIN_FLOW_BROKEN
            );
        }

        $cookies   = [];
        $expiresAt = null;
        foreach ($config->persistCookieNames as $name) {
            if (!isset($responseCookies[$name])) {
                continue;
            }
            $cookies[$name] = $responseCookies[$name]['value'];
            if ($responseCookies[$name]['expiresAt'] !== null) {
                if ($expiresAt === null || $responseCookies[$name]['expiresAt'] < $expiresAt) {
                    $expiresAt = $responseCookies[$name]['expiresAt'];
                }
            }
        }

        if ($cookies === []) {
            throw new LoginFailedException(
                'Login-Response enthielt keinen der erwarteten Session-Cookies - Login-Ablauf hat sich vermutlich geändert',
                SiteCredential::STATUS_LOGIN_FLOW_BROKEN
            );
        }

        return new LoginResult($cookies, $expiresAt);
    }

    /**
     * Schritt 1: GET der Login-Seite, nur um das CSRF-Cookie-Paar
     * (XSRF-TOKEN, aboportal_session) einzusammeln - der HTML-Body wird nicht
     * ausgewertet.
     *
     * @return array<string,string> Cookie-Name => Wert
     */
    private function primeCsrfCookies(string $pageUrl): array {
        [, $headers] = $this->safeRequest('GET', $pageUrl, [], null);
        $cookies = [];
        foreach ($this->parseSetCookies($headers) as $name => $cookie) {
            $cookies[$name] = $cookie['value'];
        }
        return $cookies;
    }

    /**
     * Schritt 2: der eigentliche Login-POST.
     *
     * @param array<string,string> $primingCookies
     * @return array{0:int,1:array<string,array{value:string,expiresAt:?\DateTime}>}
     */
    private function postLogin(LoginConfig $config, array $primingCookies, string $xsrfToken, string $username, string $password): array {
        $body = $this->buildLoginBody($config, $username, $password);

        $cookieHeader = implode('; ', array_map(
            static fn (string $name, string $value): string => $name . '=' . $value,
            array_keys($primingCookies),
            array_values($primingCookies)
        ));

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'X-CSRF-TOKEN: ' . $xsrfToken,
            'X-XSRF-TOKEN: ' . $xsrfToken,
            'Origin: ' . $this->originOf($config->ajaxEndpointUrl),
            'Referer: ' . $config->page,
        ];
        if ($cookieHeader !== '') {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }

        [$status, $responseHeaders] = $this->safeRequest('POST', $config->ajaxEndpointUrl, $headers, $body);

        return [$status, $this->parseSetCookies($responseHeaders)];
    }

    /**
     * Formular-Schema-JSON, 1:1 aus einer echten Tagesspiegel-Login-Anfrage
     * übernommen (siehe Klassen-Docblock) - nur email/password/service/action
     * sind Variablen, der Rest sind Labels/Validierungstexte der Seite selbst.
     */
    private function buildLoginBody(LoginConfig $config, string $username, string $password): string {
        $action = parse_url($config->ajaxEndpointUrl, PHP_URL_PATH) ?: '/ajax/login';

        return json_encode([
            'submitState'             => 'PENDING',
            'responseMessage'         => '',
            'validated'               => true,
            'sap_id'                  => 0,
            'asyncFieldHasApproved'   => false,
            'is_email_approved'       => false,
            'behavior'                => null,
            'client_secret'           => null,
            'submitHooks'             => [],
            'action'                  => $action,
            'layout'                  => 'AuthForm',
            'service'                 => $config->service,
            'modal'                   => '',
            'webview'                 => '',
            'source_url'              => '',
            'is_app'                  => '',
            'name'                    => 'formLogin',
            'trackingEvent'           => 'login',
            'trackingId'              => null,
            'blocks'                  => [
                [
                    'name'          => 'AuthBlock',
                    'vueComponent'  => 'AuthBlock',
                    'fields'        => [
                        [
                            'name'             => 'email',
                            'type'             => 'email',
                            'value'            => $username,
                            'default_value'    => null,
                            'label'            => 'E-Mail',
                            'checkValidation'  => true,
                            'autofocus'        => true,
                            'size'             => 'large',
                            'autocomplete'     => 'username',
                            'validations'      => [
                                ['name' => 'required', 'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse im Format name@domain.de ein.'],
                                ['name' => 'email', 'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse im Format name@domain.de ein.'],
                            ],
                        ],
                        [
                            'name'          => 'password',
                            'type'          => 'password',
                            'value'         => $password,
                            'default_value' => null,
                            'label'         => 'Passwort',
                            'size'          => 'large',
                            'autocomplete'  => 'current-password',
                            'validations'   => [
                                ['name' => 'required', 'message' => 'Bitte geben Sie Ihr Passwort ein.'],
                            ],
                        ],
                    ],
                    'child_blocks'  => [],
                    'block_options' => [],
                ],
                [
                    'name'          => 'AuthSubmitBlock',
                    'vueComponent'  => 'AuthSubmitBlock',
                    'title'         => '',
                    'fields'        => [
                        ['name' => 'submit_input', 'type' => 'submit', 'value' => null, 'default_value' => null, 'label' => 'Anmelden'],
                    ],
                    'child_blocks'  => [],
                    'block_options' => ['hasSummary' => false, 'additionalText' => null],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function originOf(string $url): string {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        return $scheme . '://' . $host;
    }

    /**
     * Führt einen einzelnen, SSRF-geprüften Request aus (kein Redirect-
     * Following - beide Schritte des Login-Ablaufs liefern laut Aufzeichnung
     * direkt 200; eine 3xx-Antwort ist ein Zeichen für einen geänderten
     * Ablauf und wird als LoginFailedException(STATUS_LOGIN_FLOW_BROKEN)
     * behandelt).
     *
     * @param list<string> $headers
     * @return array{0:int,1:string} HTTP-Status, rohe Response-Header
     */
    private function safeRequest(string $method, string $url, array $headers, ?string $body): array {
        $parsed = parse_url($url);
        $host   = $parsed['host'] ?? '';
        $port   = $parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80);

        $ips   = $this->assertPublicHostAndResolve($url);
        $pins  = $this->buildResolvePin($host, (int) $port, $ips);

        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['User-Agent: ' . self::USER_AGENT]),
            CURLOPT_RESOLVE        => $pins,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new LoginFailedException('Login-Request fehlgeschlagen: ' . $error, SiteCredential::STATUS_LOGIN_FLOW_BROKEN);
        }

        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $response, 0, $headerSize);

        if ($status >= 300 && $status < 400) {
            $this->logger->warning('Paywall-Login: unerwarteter Redirect, Login-Ablauf vermutlich geändert', ['url' => $url, 'status' => $status]);
        }

        return [$status, $rawHeaders];
    }

    /**
     * @return array<string,array{value:string,expiresAt:?\DateTime}>
     */
    private function parseSetCookies(string $rawHeaders): array {
        $cookies = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $value = trim(substr($line, strlen('Set-Cookie:')));
            $parts = explode(';', $value);
            $nameValue = array_shift($parts) ?? '';
            if (!str_contains($nameValue, '=')) {
                continue;
            }
            [$name, $val] = explode('=', $nameValue, 2);
            $name = trim($name);
            $val  = trim($val);

            $expiresAt = null;
            foreach ($parts as $attr) {
                $attr = trim($attr);
                if (stripos($attr, 'Max-Age=') === 0) {
                    $seconds = (int) substr($attr, strlen('Max-Age='));
                    $expiresAt = (new \DateTime())->modify('+' . $seconds . ' seconds');
                } elseif (stripos($attr, 'Expires=') === 0 && $expiresAt === null) {
                    $parsed = \DateTime::createFromFormat(\DateTimeInterface::RFC7231, substr($attr, strlen('Expires=')));
                    if ($parsed !== false) {
                        $expiresAt = $parsed;
                    }
                }
            }

            $cookies[$name] = ['value' => $val, 'expiresAt' => $expiresAt];
        }
        return $cookies;
    }
}
