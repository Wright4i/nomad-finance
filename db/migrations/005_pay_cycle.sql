CREATE TABLE pay_cycles (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES user(id),
    payday TEXT NOT NULL,
    length TEXT NOT NULL CHECK (length IN ('weekly', 'biweekly', 'semimonthly', 'monthly', 'irregular')),
    second_day INTEGER CHECK (second_day IS NULL OR (second_day BETWEEN 1 AND 31)),
    next_payday TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
