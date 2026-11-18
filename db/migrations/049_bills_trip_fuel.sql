DROP INDEX IF EXISTS idx_bills_trip;

CREATE UNIQUE INDEX idx_bills_trip_lodging ON bills(trip_id) WHERE trip_id IS NOT NULL AND type = 'lodging';

CREATE UNIQUE INDEX idx_bills_trip_fuel ON bills(trip_id) WHERE trip_id IS NOT NULL AND type = 'fuel';
