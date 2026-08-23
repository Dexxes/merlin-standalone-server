-- Verschlüsselte Paywall-Abo-Zugangsdaten je Nutzer/Domain (z. B. Tagesspiegel
-- Plus), siehe Db\SiteCredentialRepository und Service\SiteCredentialService.
-- username/password und der gewonnene Session-Cookie-Satz liegen verschlüsselt
-- (Auth\CredentialCipher, sodium_crypto_secretbox) in *_enc-Spalten - nie im
-- Klartext in der DB. Port von merlin-nextclouds Migration
-- Version1000Date20240101000021 (merlin_site_cred).
CREATE TABLE site_credentials (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    username_enc TEXT NOT NULL,
    password_enc TEXT NOT NULL,
    -- JSON-Objekt (Cookie-Name => Wert) der zuletzt per Login gewonnenen
    -- Session-Cookies, verschlüsselt. NULL, solange noch kein Login geglückt ist.
    session_cookies_enc TEXT,
    -- Ablaufzeit des kürzesten der gespeicherten Cookies (Max-Age der
    -- Login-Response) - danach gilt der Satz als abgelaufen und ein
    -- erneuter Login wird versucht, siehe SiteCredentialService.
    cookie_expires_at TEXT,
    -- SiteCredential::STATUS_* - Grund für UI-Statusanzeige ("Zugangsdaten
    -- prüfen") ohne dass das Passwort erneut angezeigt werden müsste.
    last_login_status TEXT NOT NULL DEFAULT 'pending',
    last_login_at TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, domain)
);
