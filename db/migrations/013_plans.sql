CREATE TABLE caps (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    category_id INTEGER REFERENCES categories(id),
    category_group TEXT CHECK (category_group IS NULL OR category_group IN ('needs', 'wants', 'savings')),
    percent INTEGER NOT NULL CHECK (percent BETWEEN 1 AND 100),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (category_id IS NOT NULL AND category_group IS NULL)
        OR (category_id IS NULL AND category_group IS NOT NULL)
    )
);

CREATE UNIQUE INDEX idx_caps_name ON caps(user_id, name COLLATE NOCASE);
