CREATE TABLE login_flow_tokens (
    id INTEGER PRIMARY KEY,
    flow_token TEXT NOT NULL UNIQUE,
    poll_token TEXT NOT NULL UNIQUE,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    api_token_plaintext TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE INDEX idx_login_flow_tokens_expires ON login_flow_tokens(expires_at);
