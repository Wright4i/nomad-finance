ALTER TABLE accounts ADD COLUMN in_budget INTEGER NOT NULL DEFAULT 0;

UPDATE accounts
SET in_budget = 1
WHERE side = 'asset' AND type != 'savings';
