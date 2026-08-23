<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/**
 * PDO-Port von merlin-nextcloud/lib/Db/SiteCredentialMapper.php - arbeitet
 * wie die übrigen merlin-server-Repositories direkt mit Zeilen-Arrays statt
 * einer Entity/QBMapper-Klasse. Konsument ist Service\SiteCredentialService,
 * das für Verschlüsselung (Auth\CredentialCipher) und Login-Orchestrierung
 * zuständig ist - dieses Repository kennt nur Speichern/Lesen der bereits
 * verschlüsselten *_enc-Spalten.
 */
final class SiteCredentialRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function find(int $userId, string $domain): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM site_credentials WHERE user_id = :user_id AND domain = :domain'
        );
        $stmt->execute(['user_id' => $userId, 'domain' => $domain]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findAllForUser(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM site_credentials WHERE user_id = :user_id ORDER BY domain ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Legt eine Zeile an oder aktualisiert sie (SELECT-dann-INSERT/UPDATE,
     * analog ContentFilterRepository::upsert() in diesem Repo).
     *
     * @param array{
     *   usernameEnc: string, passwordEnc: string,
     *   sessionCookiesEnc: ?string, cookieExpiresAt: ?string,
     *   lastLoginStatus: string, lastLoginAt: ?string
     * } $data
     */
    public function upsert(int $userId, string $domain, array $data): void {
        $existing = $this->find($userId, $domain);

        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO site_credentials (
                    user_id, domain, username_enc, password_enc, session_cookies_enc,
                    cookie_expires_at, last_login_status, last_login_at, created_at
                ) VALUES (
                    :user_id, :domain, :username_enc, :password_enc, :session_cookies_enc,
                    :cookie_expires_at, :last_login_status, :last_login_at, :created_at
                )'
            );
            $stmt->execute([
                'user_id' => $userId,
                'domain' => $domain,
                'username_enc' => $data['usernameEnc'],
                'password_enc' => $data['passwordEnc'],
                'session_cookies_enc' => $data['sessionCookiesEnc'],
                'cookie_expires_at' => $data['cookieExpiresAt'],
                'last_login_status' => $data['lastLoginStatus'],
                'last_login_at' => $data['lastLoginAt'],
                'created_at' => gmdate('c'),
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE site_credentials SET
                username_enc = :username_enc, password_enc = :password_enc,
                session_cookies_enc = :session_cookies_enc, cookie_expires_at = :cookie_expires_at,
                last_login_status = :last_login_status, last_login_at = :last_login_at
             WHERE user_id = :user_id AND domain = :domain'
        );
        $stmt->execute([
            'username_enc' => $data['usernameEnc'],
            'password_enc' => $data['passwordEnc'],
            'session_cookies_enc' => $data['sessionCookiesEnc'],
            'cookie_expires_at' => $data['cookieExpiresAt'],
            'last_login_status' => $data['lastLoginStatus'],
            'last_login_at' => $data['lastLoginAt'],
            'user_id' => $userId,
            'domain' => $domain,
        ]);
    }

    /**
     * Aktualisiert nur den Login-Ergebnis-Teil einer bereits bestehenden
     * Zeile (Retry über ensureValidCookies(), ohne username/password erneut
     * anzufassen).
     */
    public function updateLoginResult(int $userId, string $domain, ?string $sessionCookiesEnc, ?string $cookieExpiresAt, string $lastLoginStatus): void {
        $stmt = $this->db->prepare(
            'UPDATE site_credentials SET
                session_cookies_enc = :session_cookies_enc, cookie_expires_at = :cookie_expires_at,
                last_login_status = :last_login_status, last_login_at = :last_login_at
             WHERE user_id = :user_id AND domain = :domain'
        );
        $stmt->execute([
            'session_cookies_enc' => $sessionCookiesEnc,
            'cookie_expires_at' => $cookieExpiresAt,
            'last_login_status' => $lastLoginStatus,
            'last_login_at' => gmdate('c'),
            'user_id' => $userId,
            'domain' => $domain,
        ]);
    }

    public function deleteByUserAndDomain(int $userId, string $domain): void {
        $stmt = $this->db->prepare(
            'DELETE FROM site_credentials WHERE user_id = :user_id AND domain = :domain'
        );
        $stmt->execute(['user_id' => $userId, 'domain' => $domain]);
    }

    /** Aufgerufen von AdminController::deleteUser(), analog ContentFilterRepository::deleteAllUserCustom(). */
    public function deleteAllForUser(int $userId): void {
        $stmt = $this->db->prepare('DELETE FROM site_credentials WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    public static function toPublicArray(array $row): array {
        return [
            'domain' => $row['domain'],
            'status' => $row['last_login_status'],
            'lastLoginAt' => $row['last_login_at'],
        ];
    }
}
