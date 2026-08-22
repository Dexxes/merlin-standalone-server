CREATE TABLE user_settings (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (user_id, key)
);

CREATE TABLE article_shares (
    id INTEGER PRIMARY KEY,
    article_id INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token TEXT NOT NULL UNIQUE,
    password_hash TEXT,
    expires_at TEXT,
    failed_unlock_attempts INTEGER NOT NULL DEFAULT 0,
    last_failed_unlock_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (article_id, user_id)
);
CREATE INDEX idx_article_shares_token ON article_shares(token);
