CREATE TABLE vehicles_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('car', 'truck', 'motorcycle', 'rv', 'semi')),
    mpg_min_tenths INTEGER NOT NULL CHECK (mpg_min_tenths > 0),
    mpg_max_tenths INTEGER NOT NULL CHECK (mpg_max_tenths > 0),
    starred INTEGER NOT NULL CHECK (starred IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (mpg_min_tenths < mpg_max_tenths),
    CHECK (length(trim(name)) BETWEEN 1 AND 80)
);

INSERT INTO vehicles_next (
    id, user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred, created_at, updated_at
)
SELECT
    id, user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred, created_at, updated_at
FROM vehicles;

DROP TABLE vehicles;

ALTER TABLE vehicles_next RENAME TO vehicles;

CREATE UNIQUE INDEX idx_vehicles_name ON vehicles(user_id, name COLLATE NOCASE);

CREATE UNIQUE INDEX idx_vehicles_starred ON vehicles(user_id) WHERE starred = 1;
