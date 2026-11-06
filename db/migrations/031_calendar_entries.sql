CREATE TABLE calendar_entries (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    text TEXT NOT NULL,
    start_on TEXT NOT NULL,
    end_on TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (end_on >= start_on)
);

CREATE INDEX idx_calendar_entries_user ON calendar_entries(user_id, start_on, end_on);
