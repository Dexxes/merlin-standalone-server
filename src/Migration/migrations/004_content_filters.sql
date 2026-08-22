CREATE TABLE content_filters (
    id INTEGER PRIMARY KEY,
    scope TEXT NOT NULL CHECK (scope IN ('admin', 'user')),
    user_id INTEGER NOT NULL DEFAULT 0,
    domain TEXT NOT NULL,
    xml TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by INTEGER NOT NULL,
    UNIQUE (scope, domain, user_id)
);
CREATE INDEX idx_content_filters_scope_user ON content_filters(scope, user_id);
