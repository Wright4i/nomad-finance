ALTER TABLE categories ADD COLUMN role TEXT NOT NULL DEFAULT 'goal' CHECK (role IN ('default', 'fuel', 'goal'));

INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
SELECT u.id, 'Needs Default', 'needs', 0, 'cover', 'default', u.created_at, u.created_at
FROM user u
WHERE NOT EXISTS (
    SELECT 1 FROM categories c WHERE c.user_id = u.id AND c.role = 'default' AND c.category_group = 'needs'
);

INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
SELECT u.id, 'Wants Default', 'wants', 0, 'cover', 'default', u.created_at, u.created_at
FROM user u
WHERE NOT EXISTS (
    SELECT 1 FROM categories c WHERE c.user_id = u.id AND c.role = 'default' AND c.category_group = 'wants'
);

INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
SELECT u.id, 'Savings Default', 'savings', 0, 'cover', 'default', u.created_at, u.created_at
FROM user u
WHERE NOT EXISTS (
    SELECT 1 FROM categories c WHERE c.user_id = u.id AND c.role = 'default' AND c.category_group = 'savings'
);

INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, role, created_at, updated_at)
SELECT u.id, 'Fuel Bucket', 'needs', 0, 'cover', 'fuel', u.created_at, u.created_at
FROM user u
WHERE NOT EXISTS (
    SELECT 1 FROM categories c WHERE c.user_id = u.id AND c.role = 'fuel'
);

UPDATE postings
SET category_id = (
    SELECT c.id FROM categories c WHERE c.user_id = postings.user_id AND c.role = 'fuel'
)
WHERE category_id IN (SELECT id FROM categories WHERE fuel = 1);

UPDATE posting_lines
SET category_id = (
    SELECT fuel.id
    FROM categories old
    JOIN categories fuel ON fuel.user_id = old.user_id AND fuel.role = 'fuel'
    WHERE old.id = posting_lines.category_id
)
WHERE category_id IN (SELECT id FROM categories WHERE fuel = 1);

ALTER TABLE categories DROP COLUMN fuel;

CREATE UNIQUE INDEX idx_categories_default ON categories(user_id, category_group) WHERE role = 'default';

CREATE UNIQUE INDEX idx_categories_fuel ON categories(user_id) WHERE role = 'fuel';

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
    status TEXT NOT NULL CHECK (status IN ('active', 'paused', 'paid')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
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
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status, created_at, updated_at
)
SELECT
    b.id,
    b.name,
    CASE b.type WHEN 'gas' THEN 'fuel' ELSE b.type END,
    CASE WHEN b.type IN ('other', 'misc') THEN b.other_label ELSE NULL END,
    b.account_id,
    CASE
        WHEN CASE b.type WHEN 'gas' THEN 'fuel' ELSE b.type END = 'fuel' THEN (
            SELECT c.id FROM categories c
            JOIN accounts a ON a.user_id = c.user_id
            WHERE a.id = b.account_id AND c.role = 'fuel'
        )
        WHEN b.category_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM categories c
            WHERE c.id = b.category_id
              AND c.role != 'fuel'
              AND c.category_group = CASE CASE b.type WHEN 'gas' THEN 'fuel' ELSE b.type END
                WHEN 'lodging' THEN 'needs' WHEN 'rent' THEN 'needs' WHEN 'electricity' THEN 'needs'
                WHEN 'water' THEN 'needs' WHEN 'fuel' THEN 'needs' WHEN 'propane' THEN 'needs'
                WHEN 'groceries' THEN 'needs' WHEN 'internet' THEN 'needs' WHEN 'cellular' THEN 'needs'
                WHEN 'vehicle' THEN 'needs' WHEN 'maintenance' THEN 'needs' WHEN 'insurance' THEN 'needs'
                WHEN 'roadside' THEN 'needs' WHEN 'healthcare' THEN 'needs' WHEN 'mailbox' THEN 'needs'
                WHEN 'laundry' THEN 'needs' WHEN 'mortgage' THEN 'needs' WHEN 'storage' THEN 'needs'
                WHEN 'other' THEN 'needs'
                WHEN 'restaurants' THEN 'wants' WHEN 'entertainment' THEN 'wants' WHEN 'subscriptions' THEN 'wants'
                WHEN 'shopping' THEN 'wants' WHEN 'travel' THEN 'wants' WHEN 'memberships' THEN 'wants'
                WHEN 'software' THEN 'wants' WHEN 'pets' THEN 'wants' WHEN 'misc' THEN 'wants'
                ELSE 'savings'
              END
        ) THEN b.category_id
        ELSE (
            SELECT c.id FROM categories c
            JOIN accounts a ON a.user_id = c.user_id
            WHERE a.id = b.account_id AND c.role = 'default' AND c.category_group = CASE CASE b.type WHEN 'gas' THEN 'fuel' ELSE b.type END
                WHEN 'lodging' THEN 'needs' WHEN 'rent' THEN 'needs' WHEN 'electricity' THEN 'needs'
                WHEN 'water' THEN 'needs' WHEN 'fuel' THEN 'needs' WHEN 'propane' THEN 'needs'
                WHEN 'groceries' THEN 'needs' WHEN 'internet' THEN 'needs' WHEN 'cellular' THEN 'needs'
                WHEN 'vehicle' THEN 'needs' WHEN 'maintenance' THEN 'needs' WHEN 'insurance' THEN 'needs'
                WHEN 'roadside' THEN 'needs' WHEN 'healthcare' THEN 'needs' WHEN 'mailbox' THEN 'needs'
                WHEN 'laundry' THEN 'needs' WHEN 'mortgage' THEN 'needs' WHEN 'storage' THEN 'needs'
                WHEN 'other' THEN 'needs'
                WHEN 'restaurants' THEN 'wants' WHEN 'entertainment' THEN 'wants' WHEN 'subscriptions' THEN 'wants'
                WHEN 'shopping' THEN 'wants' WHEN 'travel' THEN 'wants' WHEN 'memberships' THEN 'wants'
                WHEN 'software' THEN 'wants' WHEN 'pets' THEN 'wants' WHEN 'misc' THEN 'wants'
                ELSE 'savings'
            END
        )
    END,
    CASE
        WHEN b.vendor_amount_minor IS NOT NULL AND b.vendor_currency IS NOT NULL THEN b.vendor_amount_minor
        ELSE b.amount_minor
    END,
    CASE
        WHEN b.vendor_amount_minor IS NOT NULL AND b.vendor_currency IS NOT NULL THEN b.vendor_currency
        ELSE (SELECT a.currency FROM accounts a WHERE a.id = b.account_id)
    END,
    b.recurrence,
    b.anchor_date,
    b.next_due_date,
    b.last_paid_on,
    b.autopay,
    b.notes,
    b.status,
    b.created_at,
    b.updated_at
FROM bills b;

DROP TABLE bills;

ALTER TABLE bills_next RENAME TO bills;

CREATE INDEX idx_bills_account ON bills(account_id);

CREATE INDEX idx_bills_due ON bills(status, next_due_date);

ALTER TABLE assignments ADD COLUMN filled_minor INTEGER NOT NULL DEFAULT 0;
