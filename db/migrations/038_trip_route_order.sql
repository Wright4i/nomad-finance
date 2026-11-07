ALTER TABLE trips ADD COLUMN route_order INTEGER NOT NULL DEFAULT 0;

UPDATE trips
SET route_order = (
    SELECT COUNT(*)
    FROM trips AS earlier
    WHERE earlier.user_id = trips.user_id
      AND (
        earlier.arrive_on < trips.arrive_on
        OR (earlier.arrive_on = trips.arrive_on AND earlier.depart_on < trips.depart_on)
        OR (
            earlier.arrive_on = trips.arrive_on
            AND earlier.depart_on = trips.depart_on
            AND earlier.place_name COLLATE NOCASE < trips.place_name COLLATE NOCASE
        )
        OR (
            earlier.arrive_on = trips.arrive_on
            AND earlier.depart_on = trips.depart_on
            AND earlier.place_name COLLATE NOCASE = trips.place_name COLLATE NOCASE
            AND earlier.id < trips.id
        )
      )
);

CREATE INDEX idx_trips_route ON trips(user_id, route_order);
