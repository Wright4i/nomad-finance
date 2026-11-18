CREATE TABLE categories (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    category_group TEXT NOT NULL CHECK (category_group IN ('needs', 'wants', 'savings')),
    amount_minor INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_categories_user ON categories(user_id);
