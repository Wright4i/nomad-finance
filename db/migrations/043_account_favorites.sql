ALTER TABLE accounts ADD COLUMN gas_favorite INTEGER NOT NULL DEFAULT 0;
ALTER TABLE accounts ADD COLUMN shopping_favorite INTEGER NOT NULL DEFAULT 0;
ALTER TABLE accounts ADD COLUMN asset_favorite INTEGER NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX accounts_one_gas_favorite ON accounts(user_id) WHERE gas_favorite = 1;
CREATE UNIQUE INDEX accounts_one_shopping_favorite ON accounts(user_id) WHERE shopping_favorite = 1;
CREATE UNIQUE INDEX accounts_one_asset_favorite ON accounts(user_id) WHERE asset_favorite = 1;
