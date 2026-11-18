ALTER TABLE user ADD COLUMN span_start TEXT NOT NULL DEFAULT '';

ALTER TABLE user ADD COLUMN span_length TEXT NOT NULL DEFAULT '1 month' CHECK (
    span_length IN ('1 week', '2 weeks', '4 weeks', '1 month', '2 months', '3 months')
);

UPDATE user SET span_start = strftime('%Y-%m-01', 'now') WHERE span_start = '';

ALTER TABLE user DROP COLUMN horizon_days;

CREATE TABLE span_assignments (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    span_start TEXT NOT NULL,
    span_length TEXT NOT NULL,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor >= 0),
    UNIQUE (user_id, span_start, span_length, category_id)
);
