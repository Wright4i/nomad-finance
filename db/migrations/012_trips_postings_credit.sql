ALTER TABLE categories ADD COLUMN fuel INTEGER NOT NULL DEFAULT 0;

ALTER TABLE accounts ADD COLUMN planned_payment_minor INTEGER;
ALTER TABLE accounts ADD COLUMN horizon_on TEXT;
ALTER TABLE accounts ADD COLUMN balance_mark_minor INTEGER;
ALTER TABLE accounts ADD COLUMN original_amount_minor INTEGER;

CREATE TABLE trips (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    place_name TEXT NOT NULL,
    note TEXT,
    arrive_on TEXT NOT NULL,
    depart_on TEXT NOT NULL,
    latitude TEXT NOT NULL,
    longitude TEXT NOT NULL,
    account_id INTEGER REFERENCES accounts(id),
    total_minor INTEGER NOT NULL CHECK (total_minor >= 0),
    amount_due_minor INTEGER NOT NULL CHECK (amount_due_minor >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (depart_on >= arrive_on),
    CHECK (amount_due_minor <= total_minor),
    CHECK (total_minor = 0 OR account_id IS NOT NULL)
);

CREATE INDEX idx_trips_user ON trips(user_id, arrive_on, id);

CREATE TABLE postings (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    category_id INTEGER NOT NULL REFERENCES categories(id),
    trip_id INTEGER REFERENCES trips(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
    direction TEXT NOT NULL CHECK (direction IN ('in', 'out')),
    payee TEXT,
    memo TEXT,
    posted_on TEXT NOT NULL,
    lodging_part TEXT CHECK (lodging_part IS NULL OR lodging_part IN ('deposit', 'due')),
    odometer_miles INTEGER,
    price_per_gallon_minor INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_postings_user ON postings(user_id, posted_on, id);
CREATE INDEX idx_postings_trip ON postings(trip_id);
CREATE UNIQUE INDEX idx_postings_lodging ON postings(trip_id, lodging_part) WHERE lodging_part IS NOT NULL;
