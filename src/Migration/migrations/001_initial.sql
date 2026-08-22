CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'user')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at TEXT
);
CREATE INDEX idx_api_tokens_user ON api_tokens(user_id);

CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT
);
CREATE INDEX idx_password_reset_tokens_user ON password_reset_tokens(user_id);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE articles (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    excerpt TEXT,
    author TEXT,
    site_name TEXT,
    image_url TEXT,
    is_read INTEGER NOT NULL DEFAULT 0,
    is_favorite TEXT,
    is_archived INTEGER NOT NULL DEFAULT 0,
    is_processing INTEGER NOT NULL DEFAULT 0,
    reading_time INTEGER NOT NULL DEFAULT 0,
    category TEXT,
    published_at TEXT,
    archived_at TEXT,
    scroll_progress REAL NOT NULL DEFAULT 0,
    scroll_updated_at INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_articles_user ON articles(user_id);
CREATE INDEX idx_articles_user_archived ON articles(user_id, is_archived);
CREATE INDEX idx_articles_user_favorite ON articles(user_id, is_favorite);
CREATE INDEX idx_articles_user_created ON articles(user_id, created_at);

CREATE TABLE tags (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#0082c9',
    created_at TEXT NOT NULL,
    UNIQUE (user_id, name)
);

CREATE TABLE article_tags (
    article_id INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (article_id, tag_id)
);
CREATE INDEX idx_article_tags_tag ON article_tags(tag_id);

CREATE TABLE highlights (
    id INTEGER PRIMARY KEY,
    article_id INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    highlighted_text TEXT NOT NULL,
    start_xpath TEXT NOT NULL,
    start_offset INTEGER NOT NULL,
    end_xpath TEXT NOT NULL,
    end_offset INTEGER NOT NULL,
    color TEXT NOT NULL DEFAULT '#ffeb3b',
    created_at TEXT NOT NULL
);
CREATE INDEX idx_highlights_article ON highlights(article_id);
