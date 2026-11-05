CREATE TABLE categories_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    category_group TEXT NOT NULL CHECK (category_group IN ('needs', 'wants', 'savings')),
    amount_minor INTEGER NOT NULL DEFAULT 0,
    overspend TEXT NOT NULL DEFAULT 'cover',
    role TEXT NOT NULL DEFAULT 'goal' CHECK (role IN ('goal', 'locked', 'default', 'fuel')),
    bill_type TEXT,
    archived_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (role = 'locked' AND bill_type IS NOT NULL)
        OR (role != 'locked' AND bill_type IS NULL)
    )
);

INSERT INTO categories_next (
    id, user_id, name, category_group, amount_minor, overspend, role, bill_type,
    archived_at, created_at, updated_at
)
SELECT
    id, user_id, name, category_group, amount_minor, overspend, role, NULL,
    archived_at, created_at, updated_at
FROM categories;

DROP TABLE categories;

ALTER TABLE categories_next RENAME TO categories;

CREATE INDEX idx_categories_user ON categories(user_id);

CREATE UNIQUE INDEX idx_categories_locked ON categories(user_id, bill_type) WHERE role = 'locked';

INSERT INTO categories (
    user_id, name, category_group, amount_minor, overspend, role, bill_type, created_at, updated_at
)
SELECT u.id, v.name, v.grp, 0, 'cover', 'locked', v.kind, u.created_at, u.created_at
FROM user u
CROSS JOIN (
    SELECT 'Lodging' AS name, 'needs' AS grp, 'lodging' AS kind
    UNION ALL SELECT 'Rent', 'needs', 'rent'
    UNION ALL SELECT 'Electricity', 'needs', 'electricity'
    UNION ALL SELECT 'Water', 'needs', 'water'
    UNION ALL SELECT 'Fuel', 'needs', 'fuel'
    UNION ALL SELECT 'Propane', 'needs', 'propane'
    UNION ALL SELECT 'Groceries', 'needs', 'groceries'
    UNION ALL SELECT 'Internet', 'needs', 'internet'
    UNION ALL SELECT 'Cellular', 'needs', 'cellular'
    UNION ALL SELECT 'Vehicle', 'needs', 'vehicle'
    UNION ALL SELECT 'Maintenance', 'needs', 'maintenance'
    UNION ALL SELECT 'Insurance', 'needs', 'insurance'
    UNION ALL SELECT 'Roadside', 'needs', 'roadside'
    UNION ALL SELECT 'Healthcare', 'needs', 'healthcare'
    UNION ALL SELECT 'Mailbox', 'needs', 'mailbox'
    UNION ALL SELECT 'Laundry', 'needs', 'laundry'
    UNION ALL SELECT 'Mortgage', 'needs', 'mortgage'
    UNION ALL SELECT 'Storage', 'needs', 'storage'
    UNION ALL SELECT 'Other', 'needs', 'other'
    UNION ALL SELECT 'Restaurants', 'wants', 'restaurants'
    UNION ALL SELECT 'Entertainment', 'wants', 'entertainment'
    UNION ALL SELECT 'Subscriptions', 'wants', 'subscriptions'
    UNION ALL SELECT 'Shopping', 'wants', 'shopping'
    UNION ALL SELECT 'Travel', 'wants', 'travel'
    UNION ALL SELECT 'Memberships', 'wants', 'memberships'
    UNION ALL SELECT 'Software', 'wants', 'software'
    UNION ALL SELECT 'Pets', 'wants', 'pets'
    UNION ALL SELECT 'Misc', 'wants', 'misc'
    UNION ALL SELECT 'Debt', 'savings', 'debt'
    UNION ALL SELECT 'Emergency', 'savings', 'emergency'
    UNION ALL SELECT 'Repairs', 'savings', 'repairs'
    UNION ALL SELECT 'Investments', 'savings', 'investments'
    UNION ALL SELECT 'Liquidities', 'savings', 'liquidities'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM categories c WHERE c.user_id = u.id AND c.bill_type = v.kind
);

UPDATE bills
SET category_id = (
    SELECT locked.id
    FROM categories locked
    JOIN accounts a ON a.user_id = locked.user_id AND a.id = bills.account_id
    WHERE locked.role = 'locked' AND locked.bill_type = bills.type
)
WHERE type = 'fuel'
   OR category_id IN (SELECT id FROM categories WHERE role IN ('default', 'fuel'));

INSERT INTO assignments (pay_period_id, category_id, amount_minor, filled_minor)
SELECT
    a.pay_period_id,
    locked.id,
    CASE
        WHEN p.period_end IS NULL OR p.period_end >= date('now') THEN a.amount_minor - a.filled_minor
        ELSE a.amount_minor
    END,
    0
FROM assignments a
JOIN categories old ON old.id = a.category_id AND old.role IN ('default', 'fuel')
JOIN pay_periods p ON p.id = a.pay_period_id
JOIN categories locked ON locked.user_id = old.user_id AND locked.role = 'locked' AND locked.bill_type = CASE
    WHEN old.role = 'fuel' THEN 'fuel'
    WHEN old.category_group = 'needs' THEN 'other'
    WHEN old.category_group = 'wants' THEN 'misc'
    ELSE 'debt'
END
WHERE CASE
    WHEN p.period_end IS NULL OR p.period_end >= date('now') THEN a.amount_minor - a.filled_minor
    ELSE a.amount_minor
END != 0
ON CONFLICT(pay_period_id, category_id) DO UPDATE SET
    amount_minor = amount_minor + excluded.amount_minor;

DELETE FROM targets
WHERE category_id IN (SELECT id FROM categories WHERE role IN ('default', 'fuel'));

DELETE FROM assignments
WHERE category_id IN (SELECT id FROM categories WHERE role IN ('default', 'fuel'));

DELETE FROM categories WHERE role IN ('default', 'fuel');

UPDATE categories
SET amount_minor = (
    SELECT COALESCE(SUM(a.amount_minor), 0)
    FROM assignments a
    JOIN pay_periods p ON p.id = a.pay_period_id
    WHERE a.category_id = categories.id
      AND p.period_start <= date('now')
      AND (p.period_end IS NULL OR p.period_end >= date('now'))
);

CREATE TABLE accounts_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('checking', 'savings', 'cash', 'credit', 'loan', 'fuel', 'other')),
    side TEXT NOT NULL CHECK (side IN ('asset', 'liability')),
    currency TEXT NOT NULL,
    balance_minor INTEGER NOT NULL CHECK (balance_minor >= 0),
    interest_bps INTEGER CHECK (interest_bps IS NULL OR (interest_bps >= 0 AND interest_bps <= 100000)),
    minimum_due_minor INTEGER CHECK (minimum_due_minor IS NULL OR minimum_due_minor >= 0),
    due_day INTEGER CHECK (due_day IS NULL OR (due_day >= 1 AND due_day <= 31)),
    credit_limit_minor INTEGER CHECK (credit_limit_minor IS NULL OR credit_limit_minor >= 0),
    autopay_choice TEXT NOT NULL DEFAULT 'none' CHECK (autopay_choice IN ('none', 'minimum', 'balance')),
    institution TEXT,
    notes TEXT,
    archived_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO accounts_next (
    id, user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
    due_day, credit_limit_minor, autopay_choice, institution, notes, archived_at, created_at, updated_at
)
SELECT
    id, user_id, name, type, side, currency, balance_minor, interest_bps, minimum_due_minor,
    due_day, credit_limit_minor, 'none', institution, notes, archived_at, created_at, updated_at
FROM accounts;

DROP TABLE accounts;

ALTER TABLE accounts_next RENAME TO accounts;

CREATE INDEX idx_accounts_user ON accounts(user_id);

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
    recurrence TEXT NOT NULL CHECK (recurrence IN ('once', 'weekly', 'monthly', 'yearly')),
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
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
    created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor, source_account_id
)
SELECT
    id, name, type, other_label, account_id, category_id, amount_minor, currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
    created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor, NULL
FROM bills;

DROP TABLE bills;

ALTER TABLE bills_next RENAME TO bills;

CREATE INDEX idx_bills_account ON bills(account_id);

CREATE INDEX idx_bills_due ON bills(status, next_due_date);

CREATE UNIQUE INDEX idx_bills_source ON bills(source_account_id) WHERE source_account_id IS NOT NULL;

ALTER TABLE vehicles ADD COLUMN current_odometer_miles INTEGER NOT NULL DEFAULT 0;

ALTER TABLE vehicles ADD COLUMN origin_odometer_miles INTEGER;

UPDATE vehicles
SET current_odometer_miles = (
    SELECT MAX(b.odometer_miles) FROM bills b
    WHERE b.vehicle_id = vehicles.id AND b.odometer_miles IS NOT NULL
)
WHERE EXISTS (
    SELECT 1 FROM bills b
    WHERE b.vehicle_id = vehicles.id AND b.odometer_miles IS NOT NULL
);

ALTER TABLE pay_cycles ADD COLUMN average_on INTEGER NOT NULL DEFAULT 0 CHECK (average_on IN (0, 1));

ALTER TABLE pay_cycles DROP COLUMN average_months;

CREATE TABLE payroll_entries (
    id INTEGER PRIMARY KEY,
    pay_cycle_id INTEGER NOT NULL REFERENCES pay_cycles(id),
    payday TEXT NOT NULL,
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
    hours_hundredths INTEGER,
    UNIQUE (pay_cycle_id, payday)
);
