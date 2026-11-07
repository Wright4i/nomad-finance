ALTER TABLE user RENAME COLUMN span_start TO acct_period_start;

ALTER TABLE user RENAME COLUMN span_length TO acct_period_length;

ALTER TABLE span_assignments RENAME TO acct_period_assignments;

ALTER TABLE acct_period_assignments RENAME COLUMN span_start TO acct_period_start;

ALTER TABLE acct_period_assignments RENAME COLUMN span_length TO acct_period_length;
