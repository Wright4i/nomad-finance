ALTER TABLE categories ADD COLUMN overspend TEXT NOT NULL DEFAULT 'cover';

CREATE TABLE assignments_next (
    id INTEGER PRIMARY KEY,
    pay_period_id INTEGER NOT NULL REFERENCES pay_periods(id),
    category_id INTEGER NOT NULL REFERENCES categories(id),
    amount_minor INTEGER NOT NULL,
    UNIQUE (pay_period_id, category_id)
);

INSERT INTO assignments_next (id, pay_period_id, category_id, amount_minor)
SELECT id, pay_period_id, category_id, amount_minor FROM assignments;

DROP TABLE assignments;

ALTER TABLE assignments_next RENAME TO assignments;
