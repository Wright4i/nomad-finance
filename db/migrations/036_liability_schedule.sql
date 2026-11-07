CREATE TABLE bills_next (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN (
        'lodging', 'rent', 'electricity', 'water', 'fuel', 'propane', 'groceries', 'internet', 'cellular',
        'vehicle', 'maintenance', 'insurance', 'roadside', 'healthcare', 'mailbox', 'laundry', 'mortgage',
        'storage', 'other', 'restaurants', 'entertainment', 'subscriptions', 'shopping', 'travel',
        'memberships', 'software', 'pets', 'misc', 'debt', 'emergency', 'repairs', 'investments', 'liquidities'
    )),
    other_label TEXT,
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    category_id INTEGER NOT NULL REFERENCES categories(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
    currency TEXT NOT NULL,
    recurrence TEXT NOT NULL CHECK (recurrence IN ('once', 'daily', 'weekly', 'monthly', 'yearly')),
    every_count INTEGER NOT NULL DEFAULT 1,
    anchor_date TEXT NOT NULL,
    next_due_date TEXT NOT NULL,
    last_paid_on TEXT,
    autopay INTEGER NOT NULL DEFAULT 0 CHECK (autopay IN (0, 1)),
    notes TEXT,
    status TEXT NOT NULL CHECK (status IN ('active', 'paused', 'paid', 'archived', 'hidden')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    vehicle_id INTEGER REFERENCES vehicles(id),
    odometer_miles INTEGER,
    price_per_gallon_minor INTEGER,
    source_account_id INTEGER REFERENCES accounts(id),
    trip_id INTEGER REFERENCES trips(id),
    CHECK (
        (type NOT IN ('other', 'misc') AND other_label IS NULL)
        OR (
            type IN ('other', 'misc')
            AND other_label IS NOT NULL
            AND length(trim(other_label)) BETWEEN 1 AND 80
        )
    )
);

INSERT INTO bills_next (
    id, name, type, other_label, account_id, category_id, amount_minor, currency,
    recurrence, every_count, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
    created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor, source_account_id, trip_id
)
SELECT
    id, name, type, other_label, account_id, category_id, amount_minor, currency,
    recurrence, every_count, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
    created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor, source_account_id, trip_id
FROM bills;

DROP TABLE bills;

ALTER TABLE bills_next RENAME TO bills;

CREATE INDEX idx_bills_account ON bills(account_id);

CREATE INDEX idx_bills_due ON bills(status, next_due_date);

CREATE UNIQUE INDEX idx_bills_source ON bills(source_account_id) WHERE source_account_id IS NOT NULL;

CREATE UNIQUE INDEX idx_bills_trip ON bills(trip_id) WHERE trip_id IS NOT NULL;

ALTER TABLE accounts ADD COLUMN bank_autopay INTEGER NOT NULL DEFAULT 0;

ALTER TABLE accounts ADD COLUMN bill_recurrence TEXT NOT NULL DEFAULT 'monthly';

ALTER TABLE accounts ADD COLUMN bill_every INTEGER NOT NULL DEFAULT 1;

UPDATE accounts SET bank_autopay = 1 WHERE autopay_choice IN ('minimum', 'balance');
