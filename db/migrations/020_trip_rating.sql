ALTER TABLE trips ADD COLUMN rating_half_stars INTEGER CHECK (rating_half_stars IS NULL OR (rating_half_stars >= 0 AND rating_half_stars <= 10));

ALTER TABLE trips ADD COLUMN rating_comment TEXT;
