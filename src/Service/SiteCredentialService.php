<?php

declare(strict_types=1);

namespace Merlin\Service;

use Merlin\Auth\CredentialCipher;
use Merlin\Db\ContentFilterRepository;
use Merlin\Db\SiteCredential;
use Merlin\Db\SiteCredentialRepository;
use Merlin\Service\Login\LoginConfig;
use Merlin\Service\Login\LoginFailedException;
use Merlin\Service\Login\LoginProviderInterface;
use Merlin\Service\Login\LoginResult;
use Merlin\Service\Login\PianoJsonFormLoginProvider;
use Psr\Log\LoggerInterface;

/**
 * Port von merlin-nextcloud/lib/Service/SiteCredentialService.php - gleiche
 * Orchestrierung (verschlüsselte Ablage, Login-Ausführung, Cache mit
 * automatischer Erneuerung), aber gegen das PDO-Repository statt einer
 * Entity/QBMapper-Klasse: Zeilen sind Arrays, encrypt/decrypt läuft über
 * Auth\CredentialCipher statt OCP\Security\ICrypto.
 */
final class SiteCredentialService {
    /** type-Attribut der <login>-Sektion => zuständiger Provider. */
    private const PROVIDERS = [
        'piano-json-form' => PianoJsonFormLoginProvider::class,
    ];

    public function __construct(
        private readonly SiteCredentialRepository $repository,
        private readonly ContentFilterRepository $filterRepository,
        private readonly CredentialCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * <login>-Konfiguration der Bundle-Domain-Config, oder null wenn die
     * Domain keine Paywall-Login-Unterstützung hat. BUNDLE-ONLY (siehe
     * LoginConfig-Docblock) - liest bewusst readBundle() statt getMerged(),
     * damit weder Admin- noch User-Custom-Filter einen Login-Endpoint
     * definieren können.
     */
    public function loadLoginConfig(string $domain): ?LoginConfig {
        $raw = $this->filterRepository->readBundle($domain);
        if ($raw === null) {
            return null;
        }

        try {
            $xml = new \SimpleXMLElement($raw, LIBXML_NONET | LIBXML_NOENT);
        } catch (\Throwable $e) {
            return null;
        }

        if (!isset($xml->login)) {
            return null;
        }

        $config = LoginConfig::fromXml($xml->login);
        return $config->isValid() ? $config : null;
    }

    /**
     * Gültige, gecachte Session-Cookies für $userId/$domain, oder null wenn
     * es keine gibt (nie hinterlegt, abgelaufen, oder letzter Login-Versuch
     * fehlgeschlagen). Löst KEINEN Login aus - das übernimmt
     * ensureValidCookies().
     *
     * @return array<string,string>|null
     */
    public function getCachedCookies(int $userId, string $domain): ?array {
        $row = $this->repository->find($userId, $domain);
        if ($row === null || $row['session_cookies_enc'] === null) {
            return null;
        }
        if ($row['last_login_status'] !== SiteCredential::STATUS_OK) {
            return null;
        }
        if ($row['cookie_expires_at'] !== null && $row['cookie_expires_at'] < gmdate('c')) {
            return null;
        }

        try {
            $decrypted = $this->cipher->decrypt($row['session_cookies_enc']);
            return json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('site-credentials: gespeicherte Session-Cookies konnten nicht entschlüsselt werden', [
                'domain' => $domain,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Cookies aus dem Cache, oder - falls abgelaufen/fehlend und
     * Zugangsdaten hinterlegt sind - ein frischer Login-Versuch. Wird pro
     * Artikel-Fetch höchstens einmal aufgerufen (ContentExtractorService
     * cached selbst nicht erneut), damit ein einzelner Extraktions-Request
     * nie mehrfach gegen die Paywall-Seite einloggt.
     *
     * @return array<string,string>|null null, wenn keine (nutzbaren)
     *         Zugangsdaten hinterlegt sind ODER der Login-Versuch fehlschlug
     *         (Grund steht dann in last_login_status).
     */
    public function ensureValidCookies(int $userId, string $domain, LoginConfig $config): ?array {
        $cached = $this->getCachedCookies($userId, $domain);
        if ($cached !== null) {
            return $cached;
        }

        $row = $this->repository->find($userId, $domain);
        if ($row === null) {
            return null;
        }

        try {
            $username = $this->cipher->decrypt($row['username_enc']);
            $password = $this->cipher->decrypt($row['password_enc']);
        } catch (\Throwable $e) {
            $this->logger->error('site-credentials: Zugangsdaten konnten nicht entschlüsselt werden', [
                'domain' => $domain,
                'exception' => $e,
            ]);
            return null;
        }

        try {
            $result = $this->login($username, $password, $config);
        } catch (LoginFailedException $e) {
            $this->repository->updateLoginResult($userId, $domain, null, null, $e->reason);
            $this->logger->warning('site-credentials: Login fehlgeschlagen', [
                'domain' => $domain,
                'reason' => $e->reason,
            ]);
            return null;
        }

        $this->repository->updateLoginResult(
            $userId,
            $domain,
            $this->cipher->encrypt(json_encode($result->cookies, JSON_THROW_ON_ERROR)),
            $result->expiresAt?->format('c'),
            SiteCredential::STATUS_OK,
        );

        return $result->cookies;
    }

    /**
     * Legt Zugangsdaten für $userId/$domain an oder überschreibt sie, und
     * führt sofort einen Login-Versuch aus (für die "Testen"-Aktion in der
     * Personal-Settings-UI).
     *
     * @throws \InvalidArgumentException wenn die Domain keine <login>-Config hat
     * @throws LoginFailedException wenn der Login-Versuch fehlschlägt
     */
    public function saveAndLogin(int $userId, string $domain, string $username, string $password): void {
        $config = $this->loadLoginConfig($domain);
        if ($config === null) {
            throw new \InvalidArgumentException('Domain unterstützt keinen Paywall-Login: ' . $domain);
        }

        $usernameEnc = $this->cipher->encrypt($username);
        $passwordEnc = $this->cipher->encrypt($password);

        try {
            $result = $this->login($username, $password, $config);
            $this->repository->upsert($userId, $domain, [
                'usernameEnc' => $usernameEnc,
                'passwordEnc' => $passwordEnc,
                'sessionCookiesEnc' => $this->cipher->encrypt(json_encode($result->cookies, JSON_THROW_ON_ERROR)),
                'cookieExpiresAt' => $result->expiresAt?->format('c'),
                'lastLoginStatus' => SiteCredential::STATUS_OK,
                'lastLoginAt' => gmdate('c'),
            ]);
        } catch (LoginFailedException $e) {
            $this->repository->upsert($userId, $domain, [
                'usernameEnc' => $usernameEnc,
                'passwordEnc' => $passwordEnc,
                'sessionCookiesEnc' => null,
                'cookieExpiresAt' => null,
                'lastLoginStatus' => $e->reason,
                'lastLoginAt' => gmdate('c'),
            ]);
            throw $e;
        }
    }

    public function delete(int $userId, string $domain): void {
        $this->repository->deleteByUserAndDomain($userId, $domain);
    }

    /**
     * @return list<array{domain:string,status:string,lastLoginAt:?string}>
     */
    public function listForUser(int $userId): array {
        return array_map(
            static fn (array $row): array => SiteCredentialRepository::toPublicArray($row),
            $this->repository->findAllForUser($userId)
        );
    }

    private function login(string $username, string $password, LoginConfig $config): LoginResult {
        $providerClass = self::PROVIDERS[$config->type] ?? null;
        if ($providerClass === null) {
            throw new LoginFailedException('Unbekannter Login-Typ: ' . $config->type, SiteCredential::STATUS_LOGIN_FLOW_BROKEN);
        }

        /** @var LoginProviderInterface $provider */
        $provider = new $providerClass($this->logger);
        return $provider->login($username, $password, $config);
    }
}
