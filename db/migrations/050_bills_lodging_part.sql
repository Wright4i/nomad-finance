ALTER TABLE bills ADD COLUMN lodging_part TEXT CHECK (lodging_part IS NULL OR lodging_part IN ('deposit', 'due'));

UPDATE bills SET lodging_part = 'due' WHERE trip_id IS NOT NULL AND type = 'lodging';

DROP INDEX IF EXISTS idx_bills_trip_lodging;

CREATE UNIQUE INDEX idx_bills_trip_lodging ON bills(trip_id, lodging_part)
    WHERE trip_id IS NOT NULL AND type = 'lodging' AND lodging_part IS NOT NULL;
