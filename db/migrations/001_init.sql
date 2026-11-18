CREATE TABLE user (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    base_currency TEXT NOT NULL,
    theme TEXT NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light')),
    created_at TEXT NOT NULL
);

CREATE TABLE login_guard (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT
);

INSERT INTO login_guard (id, failed_count) VALUES (1, 0);

CREATE TABLE accounts (
    id INTEGER PRIMARY KEY,
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

CREATE TABLE bills (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('housing', 'utility', 'subscription', 'insurance', 'tax', 'other')),
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
    vendor_amount_minor INTEGER CHECK (vendor_amount_minor IS NULL OR vendor_amount_minor > 0),
    vendor_currency TEXT,
    recurrence TEXT NOT NULL CHECK (recurrence IN ('once', 'weekly', 'monthly', 'yearly')),
    anchor_date TEXT NOT NULL,
    next_due_date TEXT NOT NULL,
    last_paid_on TEXT,
    autopay INTEGER NOT NULL DEFAULT 0 CHECK (autopay IN (0, 1)),
    notes TEXT,
    status TEXT NOT NULL CHECK (status IN ('active', 'paused', 'paid', 'ended')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (vendor_amount_minor IS NULL AND vendor_currency IS NULL)
        OR (vendor_amount_minor IS NOT NULL AND vendor_currency IS NOT NULL)
    )
);

CREATE INDEX idx_bills_account ON bills(account_id);
CREATE INDEX idx_bills_due ON bills(status, next_due_date);

CREATE TABLE exchange_rates (
    id INTEGER PRIMARY KEY,
    base_currency TEXT NOT NULL,
    currency TEXT NOT NULL,
    rate TEXT NOT NULL,
    effective_date TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (base_currency, currency, effective_date),
    CHECK (base_currency <> currency)
);

CREATE INDEX idx_rates_lookup ON exchange_rates(base_currency, currency, effective_date);
