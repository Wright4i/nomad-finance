ALTER TABLE accounts ADD COLUMN starred INTEGER NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX accounts_one_star ON accounts(user_id) WHERE starred = 1;
