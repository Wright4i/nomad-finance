ALTER TABLE user ADD COLUMN horizon_days INTEGER NOT NULL DEFAULT 60 CHECK (horizon_days IN (30, 60));

ALTER TABLE user ADD COLUMN fuel_price_minor INTEGER CHECK (fuel_price_minor IS NULL OR fuel_price_minor > 0);

ALTER TABLE pay_cycles ADD COLUMN hours_mode TEXT NOT NULL DEFAULT 'typed' CHECK (hours_mode IN ('typed', 'weekdays'));

ALTER TABLE pay_cycles ADD COLUMN average_months INTEGER CHECK (average_months IS NULL OR average_months BETWEEN 3 AND 6);

ALTER TABLE postings ADD COLUMN pay_cycle_id INTEGER REFERENCES pay_cycles(id);

ALTER TABLE trips ADD COLUMN balance_due_on TEXT;

DROP TABLE caps;

ALTER TABLE accounts DROP COLUMN planned_payment_minor;

ALTER TABLE accounts DROP COLUMN horizon_on;

ALTER TABLE accounts DROP COLUMN balance_mark_minor;

ALTER TABLE accounts DROP COLUMN original_amount_minor;
