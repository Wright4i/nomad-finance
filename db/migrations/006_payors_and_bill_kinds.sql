CREATE TABLE pay_cycles_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    payor TEXT NOT NULL COLLATE NOCASE,
    payday TEXT NOT NULL,
    length TEXT NOT NULL CHECK (length IN ('weekly', 'biweekly', 'semimonthly', 'monthly', 'irregular')),
    second_day INTEGER CHECK (second_day IS NULL OR (second_day BETWEEN 1 AND 31)),
    next_payday TEXT,
    archived_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO pay_cycles_next (
    id, user_id, payor, payday, length, second_day, next_payday, archived_at, created_at, updated_at
)
SELECT id, user_id, 'Payor 1', payday, length, second_day, next_payday, NULL, created_at, updated_at
FROM pay_cycles;

DROP TABLE pay_cycles;

ALTER TABLE pay_cycles_next RENAME TO pay_cycles;

CREATE UNIQUE INDEX idx_pay_cycles_payor ON pay_cycles(user_id, payor);

CREATE TABLE bills_next (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN (
        'housing', 'utility', 'phone', 'subscription', 'insurance', 'medical',
        'transport', 'education', 'childcare', 'tax', 'other'
    )),
    other_label TEXT,
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
    ),
    CHECK (
        (type != 'other' AND other_label IS NULL)
        OR (
            type = 'other'
            AND other_label IS NOT NULL
            AND length(trim(other_label)) BETWEEN 1 AND 80
        )
    )
);

INSERT INTO bills_next (
    id, name, type, other_label, account_id, amount_minor, vendor_amount_minor, vendor_currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status, created_at, updated_at
)
SELECT
    id, name, type,
    CASE WHEN type = 'other' THEN 'Other' ELSE NULL END,
    account_id, amount_minor, vendor_amount_minor, vendor_currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status, created_at, updated_at
FROM bills;

DROP TABLE bills;

ALTER TABLE bills_next RENAME TO bills;

CREATE INDEX idx_bills_account ON bills(account_id);

CREATE INDEX idx_bills_due ON bills(status, next_due_date);
