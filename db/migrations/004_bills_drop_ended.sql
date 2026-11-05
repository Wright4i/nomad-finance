UPDATE bills SET status = 'paused' WHERE status = 'ended';

CREATE TABLE bills_next (
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
    status TEXT NOT NULL CHECK (status IN ('active', 'paused', 'paid')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (vendor_amount_minor IS NULL AND vendor_currency IS NULL)
        OR (vendor_amount_minor IS NOT NULL AND vendor_currency IS NOT NULL)
    )
);

INSERT INTO bills_next (
    id, name, type, account_id, amount_minor, vendor_amount_minor, vendor_currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status, created_at, updated_at
)
SELECT
    id, name, type, account_id, amount_minor, vendor_amount_minor, vendor_currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status, created_at, updated_at
FROM bills;

DROP TABLE bills;

ALTER TABLE bills_next RENAME TO bills;

CREATE INDEX idx_bills_account ON bills(account_id);

CREATE INDEX idx_bills_due ON bills(status, next_due_date);
