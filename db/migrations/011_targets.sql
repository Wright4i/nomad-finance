CREATE TABLE targets (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    category_id INTEGER NOT NULL UNIQUE REFERENCES categories(id),
    kind TEXT NOT NULL,
    amount_minor INTEGER,
    target_date TEXT,
    pay_cycle_id INTEGER REFERENCES pay_cycles(id),
    celebrated_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX targets_user ON targets (user_id);
