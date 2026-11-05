ALTER TABLE pay_cycles ADD COLUMN pay_kind TEXT NOT NULL DEFAULT 'fixed';

ALTER TABLE pay_cycles ADD COLUMN rate_minor INTEGER NOT NULL DEFAULT 0;

ALTER TABLE pay_cycles ADD COLUMN hours_hundredths INTEGER;
