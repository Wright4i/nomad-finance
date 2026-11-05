ALTER TABLE bills ADD COLUMN vehicle_id INTEGER REFERENCES vehicles(id);

ALTER TABLE bills ADD COLUMN odometer_miles INTEGER;

ALTER TABLE bills ADD COLUMN price_per_gallon_minor INTEGER;

INSERT INTO bills (
    name, type, other_label, account_id, category_id, amount_minor, currency,
    recurrence, anchor_date, next_due_date, last_paid_on, autopay, notes, status,
    created_at, updated_at, vehicle_id, odometer_miles, price_per_gallon_minor
)
SELECT
    CASE WHEN p.payee IS NOT NULL AND trim(p.payee) != '' THEN p.payee ELSE 'Fuel' END,
    'fuel',
    NULL,
    p.account_id,
    COALESCE(
        (SELECT c.id FROM categories c WHERE c.user_id = p.user_id AND c.role = 'fuel' LIMIT 1),
        p.category_id
    ),
    p.amount_minor,
    'USD',
    'once',
    p.posted_on,
    p.posted_on,
    p.posted_on,
    0,
    p.memo,
    'paid',
    p.created_at,
    p.updated_at,
    p.vehicle_id,
    p.odometer_miles,
    p.price_per_gallon_minor
FROM postings p
LEFT JOIN categories cat ON cat.id = p.category_id
WHERE p.direction = 'out'
  AND (
      cat.role = 'fuel'
      OR p.odometer_miles IS NOT NULL
      OR p.price_per_gallon_minor IS NOT NULL
  );

DROP TABLE posting_lines;

DROP TABLE postings;

DROP TABLE rate_quotes;

DROP TABLE rate_polls;

DROP TABLE exchange_rates;

UPDATE accounts SET currency = 'USD';

UPDATE bills SET currency = 'USD';

UPDATE user SET base_currency = 'USD';
