CREATE TABLE user_next (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    base_currency TEXT NOT NULL,
    theme TEXT NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light')),
    role TEXT NOT NULL CHECK (role IN ('owner', 'demo')),
    created_at TEXT NOT NULL
);

INSERT INTO user_next (id, name, password_hash, base_currency, theme, role, created_at)
SELECT id, name, password_hash, base_currency, theme, 'owner', created_at FROM user;

DROP TABLE user;

ALTER TABLE user_next RENAME TO user;

CREATE TABLE login_guard_next (
    user_id INTEGER PRIMARY KEY REFERENCES user(id),
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT
);

INSERT INTO login_guard_next (user_id, failed_count, locked_until)
SELECT u.id,
       COALESCE((SELECT failed_count FROM login_guard LIMIT 1), 0),
       (SELECT locked_until FROM login_guard LIMIT 1)
FROM user u;

DROP TABLE login_guard;

ALTER TABLE login_guard_next RENAME TO login_guard;

CREATE TABLE accounts_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('checking', 'savings', 'cash', 'credit', 'loan', 'other')),
    side TEXT NOT NULL CHECK (side IN ('asset', 'liability')),
    currency TEXT NOT NULL,
    balance_minor INTEGER NOT NULL CHECK (balance_minor >= 0),
    interest_bps INTEGER CHECK (interest_bps IS NULL OR (interest_bps >= 0 AND interest_bps <= 100000)),
    minimum_due_minor INTEGER CHECK (minimum_due_minor IS NULL OR minimum_due_minor >= 0),
    due_day INTEGER CHECK (due_day IS NULL OR (due_day >= 1 AND due_day <= 31)),
    credit_limit_minor INTEGER CHECK (credit_limit_minor IS NULL OR credit_limit_minor >= 0),
    institution TEXT,
    notes TEXT,
    archived_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO accounts_next (
    id, user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
    due_day, credit_limit_minor, institution, notes, archived_at, created_at, updated_at
)
SELECT
    id, (SELECT id FROM user WHERE role = 'owner'), name, type, side, currency, balance_minor,
    interest_bps, minimum_due_minor, due_day, credit_limit_minor, institution, notes,
    archived_at, created_at, updated_at
FROM accounts;

DROP TABLE accounts;

ALTER TABLE accounts_next RENAME TO accounts;

CREATE INDEX idx_accounts_user ON accounts(user_id);

CREATE TABLE exchange_rates_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    base_currency TEXT NOT NULL,
    currency TEXT NOT NULL,
    rate TEXT NOT NULL,
    effective_date TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, base_currency, currency, effective_date),
    CHECK (base_currency <> currency)
);

INSERT INTO exchange_rates_next (id, user_id, base_currency, currency, rate, effective_date, created_at)
SELECT id, (SELECT id FROM user WHERE role = 'owner'), base_currency, currency, rate, effective_date, created_at
FROM exchange_rates;

DROP TABLE exchange_rates;

ALTER TABLE exchange_rates_next RENAME TO exchange_rates;

CREATE INDEX idx_rates_lookup ON exchange_rates(user_id, base_currency, currency, effective_date);
