<?php

declare(strict_types=1);

namespace Merlin\Auth;

/**
 * Reversible Verschlüsselung für Paywall-Abo-Zugangsdaten (Db\
 * SiteCredentialRepository) - merlin-nextcloud hat dafür OCP\Security\ICrypto
 * (Instanz-Secret des Nextcloud-Kerns), merlin-server hat kein Äquivalent und
 * bekommt deshalb diese eigenständige Klasse: libsodium secretbox
 * (XSalsa20-Poly1305, authentifiziert) mit einem 32-Byte-Schlüssel aus
 * config.php. PasswordHasher/password_hash() ist hier bewusst NICHT
 * verwendbar - das ist Einweg-Hashing, wir müssen Zugangsdaten aber wieder
 * auslesen können, um damit einzuloggen.
 */
final class CredentialCipher {
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    /**
     * Rohes Konfigurationsfeld, absichtlich erst bei der ersten
     * encrypt()/decrypt()-Nutzung dekodiert und geprüft: CredentialCipher
     * wird als Teil von SiteCredentialService bei JEDER Artikel-Extraktion
     * konstruiert (ContentExtractorService braucht es für die
     * Cookie-Injektion), nicht nur bei tatsächlicher Paywall-Nutzung. Ein
     * eager-Check im Konstruktor würde also jede Installation ohne
     * gesetzten Schlüssel sofort komplett lahmlegen, statt nur den (seltenen)
     * Pfad, der wirklich ver-/entschlüsselt.
     */
    private string $base64Key;
    private ?string $key = null;

    /**
     * @param string $base64Key 32-Byte-Schlüssel, base64-kodiert (siehe
     *        config.sample.php - Erzeugung z. B. über
     *        `base64_encode(sodium_crypto_secretbox_keygen())`).
     */
    public function __construct(string $base64Key) {
        $this->base64Key = $base64Key;
    }

    private function resolveKey(): string {
        if ($this->key !== null) {
            return $this->key;
        }

        $key = base64_decode($this->base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'config.php: credential_cipher_key fehlt oder ist ungültig (32 Byte, base64) - '
                . 'siehe config.sample.php.'
            );
        }

        return $this->key = $key;
    }

    /** @return string Nonce + Ciphertext, base64-kodiert. */
    public function encrypt(string $plaintext): string {
        $key = $this->resolveKey();
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return base64_encode($nonce . $ciphertext);
    }

    /** @throws \RuntimeException wenn $encoded kein gültiges Chiffrat für diesen Schlüssel ist. */
    public function decrypt(string $encoded): string {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= self::NONCE_BYTES) {
            throw new \RuntimeException('Ungültiges verschlüsseltes Feld (Format).');
        }

        $nonce      = substr($raw, 0, self::NONCE_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES);
        $plaintext  = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->resolveKey());

        if ($plaintext === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen (falscher Schlüssel oder manipulierte Daten).');
        }

        return $plaintext;
    }
}
