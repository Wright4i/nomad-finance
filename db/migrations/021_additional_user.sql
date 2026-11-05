CREATE TABLE user_next (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    base_currency TEXT NOT NULL,
    theme TEXT NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light')),
    role TEXT NOT NULL CHECK (role IN ('owner', 'demo', 'user')),
    created_at TEXT NOT NULL,
    horizon_days INTEGER NOT NULL DEFAULT 60 CHECK (horizon_days IN (30, 60)),
    fuel_price_minor INTEGER CHECK (fuel_price_minor IS NULL OR fuel_price_minor > 0)
);

INSERT INTO user_next (
    id, name, password_hash, base_currency, theme, role, created_at, horizon_days, fuel_price_minor
)
SELECT
    id, name, password_hash, base_currency, theme, role, created_at, horizon_days, fuel_price_minor
FROM user;

DROP TABLE user;

ALTER TABLE user_next RENAME TO user;
