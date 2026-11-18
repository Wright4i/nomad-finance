ALTER TABLE user ADD COLUMN motion_assigned INTEGER NOT NULL DEFAULT 1 CHECK (motion_assigned IN (0, 1));

ALTER TABLE user ADD COLUMN motion_note INTEGER NOT NULL DEFAULT 1 CHECK (motion_note IN (0, 1));

ALTER TABLE user ADD COLUMN motion_level INTEGER NOT NULL DEFAULT 1 CHECK (motion_level IN (0, 1));

ALTER TABLE user ADD COLUMN motion_scrim INTEGER NOT NULL DEFAULT 1 CHECK (motion_scrim IN (0, 1));

ALTER TABLE user ADD COLUMN motion_dialog INTEGER NOT NULL DEFAULT 1 CHECK (motion_dialog IN (0, 1));

ALTER TABLE user ADD COLUMN motion_ticker INTEGER NOT NULL DEFAULT 1 CHECK (motion_ticker IN (0, 1));

ALTER TABLE user ADD COLUMN motion_stagger INTEGER NOT NULL DEFAULT 1 CHECK (motion_stagger IN (0, 1));
