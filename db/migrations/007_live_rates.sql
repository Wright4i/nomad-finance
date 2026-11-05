CREATE TABLE exchange_rates_next (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    base_currency TEXT NOT NULL,
    currency TEXT NOT NULL,
    rate TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, base_currency, currency),
    CHECK (base_currency <> currency)
);

INSERT INTO exchange_rates_next (user_id, base_currency, currency, rate, created_at, updated_at)
SELECT e.user_id, e.base_currency, e.currency, e.rate, e.created_at, e.created_at
FROM exchange_rates e
JOIN (
    SELECT user_id, base_currency, currency, MAX(effective_date) AS effective_date
    FROM exchange_rates
    GROUP BY user_id, base_currency, currency
) latest
    ON latest.user_id = e.user_id
    AND latest.base_currency = e.base_currency
    AND latest.currency = e.currency
    AND latest.effective_date = e.effective_date;

DROP TABLE exchange_rates;

ALTER TABLE exchange_rates_next RENAME TO exchange_rates;

CREATE TABLE rate_quotes (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES user(id),
    base_currency TEXT NOT NULL,
    currency TEXT NOT NULL,
    rate TEXT NOT NULL,
    fetched_at TEXT NOT NULL,
    UNIQUE (user_id, base_currency, currency),
    CHECK (base_currency <> currency)
);

CREATE TABLE rate_polls (
    user_id INTEGER NOT NULL,
    base_currency TEXT NOT NULL,
    attempted_at TEXT NOT NULL,
    ok INTEGER NOT NULL CHECK (ok IN (0, 1)),
    PRIMARY KEY (user_id, base_currency),
    FOREIGN KEY (user_id) REFERENCES user(id)
);
