ALTER TABLE bills ADD COLUMN trip_id INTEGER REFERENCES trips(id);

CREATE UNIQUE INDEX idx_bills_trip ON bills(trip_id) WHERE trip_id IS NOT NULL;
