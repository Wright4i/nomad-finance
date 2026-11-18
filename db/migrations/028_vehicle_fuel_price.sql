ALTER TABLE vehicles ADD COLUMN fuel_price_minor INTEGER CHECK (fuel_price_minor IS NULL OR fuel_price_minor > 0);

UPDATE vehicles
SET fuel_price_minor = (
    SELECT user.fuel_price_minor
    FROM user
    WHERE user.id = vehicles.user_id
)
WHERE EXISTS (
    SELECT 1
    FROM user
    WHERE user.id = vehicles.user_id
      AND user.fuel_price_minor IS NOT NULL
      AND user.fuel_price_minor > 0
);

ALTER TABLE user DROP COLUMN fuel_price_minor;
