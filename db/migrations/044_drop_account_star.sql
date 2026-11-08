UPDATE accounts
SET asset_favorite = 1
WHERE starred = 1
  AND side = 'asset'
  AND archived_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM accounts other
    WHERE other.user_id = accounts.user_id AND other.asset_favorite = 1
  );

UPDATE bills
SET account_id = (
    SELECT fav.id FROM accounts fav
    JOIN accounts source ON source.id = bills.source_account_id
    WHERE fav.user_id = source.user_id AND fav.asset_favorite = 1 AND fav.archived_at IS NULL
)
WHERE source_account_id IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM accounts fav
    JOIN accounts source ON source.id = bills.source_account_id
    WHERE fav.user_id = source.user_id AND fav.asset_favorite = 1 AND fav.archived_at IS NULL
  );

DROP INDEX IF EXISTS accounts_one_star;

ALTER TABLE accounts DROP COLUMN starred;
