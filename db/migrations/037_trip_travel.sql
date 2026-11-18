ALTER TABLE trips ADD COLUMN travel TEXT NOT NULL DEFAULT 'drive' CHECK (travel IN ('drive', 'fly'));
