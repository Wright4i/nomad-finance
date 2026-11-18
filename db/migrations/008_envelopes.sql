CREATE TABLE pay_periods (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    pay_cycle_id INTEGER NOT NULL REFERENCES pay_cycles(id),
    period_start TEXT NOT NULL,
    period_end TEXT,
    celebrated_at TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (pay_cycle_id, period_start)
);

CREATE INDEX pay_periods_user ON pay_periods (user_id, pay_cycle_id, period_start);

CREATE TABLE assignments (
    id INTEGER PRIMARY KEY,
    pay_period_id INTEGER NOT NULL REFERENCES pay_periods(id),
    category_id INTEGER NOT NULL REFERENCES categories(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor >= 0),
    UNIQUE (pay_period_id, category_id)
);

ALTER TABLE bills ADD COLUMN category_id INTEGER REFERENCES categories(id);
