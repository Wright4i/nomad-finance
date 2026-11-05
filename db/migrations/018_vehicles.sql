CREATE TABLE bills_next (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN (
        'lodging', 'rent', 'electricity', 'water', 'internet', 'cellular', 'gas',
        'groceries', 'restaurants', 'vehicle', 'debt', 'healthcare', 'entertainment',
        'subscriptions', 'shopping', 'travel', 'other'
    )),
    other_label TEXT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    category_id INTEGER REFERENCES categories(id),
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
    id, name, type, other_label, account_id, category_id, amount_minor, vendor_amount_minor, vendor_currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status, created_at, updated_at
)
SELECT
    id,
    name,
    CASE type
        WHEN 'housing' THEN 'rent'
        WHEN 'utility' THEN 'electricity'
        WHEN 'phone' THEN 'cellular'
        WHEN 'subscription' THEN 'subscriptions'
        WHEN 'insurance' THEN 'healthcare'
        WHEN 'medical' THEN 'healthcare'
        WHEN 'transport' THEN 'vehicle'
        WHEN 'education' THEN 'entertainment'
        WHEN 'childcare' THEN 'shopping'
        WHEN 'tax' THEN 'debt'
        ELSE type
    END,
    other_label,
    account_id,
    category_id,
    amount_minor,
    vendor_amount_minor,
    vendor_currency,
    recurrence,
    anchor_date,
    next_due_date,
    last_paid_on,
    autopay,
    notes,
    status,
    created_at,
    updated_at
FROM bills;

DROP TABLE bills;

ALTER TABLE bills_next RENAME TO bills;

CREATE INDEX idx_bills_account ON bills(account_id);

CREATE INDEX idx_bills_due ON bills(status, next_due_date);

CREATE TABLE vehicles (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    color TEXT NOT NULL CHECK (length(color) = 7 AND substr(color, 1, 1) = '#'),
    kind TEXT NOT NULL CHECK (kind IN ('car', 'truck', 'motorcycle', 'rv', 'semi')),
    mpg_min_tenths INTEGER NOT NULL CHECK (mpg_min_tenths > 0),
    mpg_max_tenths INTEGER NOT NULL CHECK (mpg_max_tenths > 0),
    starred INTEGER NOT NULL CHECK (starred IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (mpg_min_tenths < mpg_max_tenths),
    CHECK (length(trim(name)) BETWEEN 1 AND 80)
);

CREATE UNIQUE INDEX idx_vehicles_name ON vehicles(user_id, name COLLATE NOCASE);

CREATE UNIQUE INDEX idx_vehicles_starred ON vehicles(user_id) WHERE starred = 1;

ALTER TABLE postings ADD COLUMN vehicle_id INTEGER REFERENCES vehicles(id);
