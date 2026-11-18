INSERT OR IGNORE INTO acct_period_assignments (
    user_id, acct_period_start, acct_period_length, category_id, amount_minor
)
SELECT grouped.user_id, grouped.window_start, grouped.length, grouped.category_id, grouped.amount
FROM (
    SELECT
        p.user_id AS user_id,
        bounds.window_start AS window_start,
        u.acct_period_length AS length,
        a.category_id AS category_id,
        SUM(a.amount_minor) AS amount
    FROM assignments a
    JOIN pay_periods p ON p.id = a.pay_period_id
    JOIN user u ON u.id = p.user_id
    JOIN (
        SELECT id AS user_id, acct_period_start AS window_start FROM user
        UNION ALL
        SELECT id, CASE acct_period_length
            WHEN '1 week' THEN date(acct_period_start, '-7 days')
            WHEN '2 weeks' THEN date(acct_period_start, '-14 days')
            WHEN '4 weeks' THEN date(acct_period_start, '-28 days')
            WHEN '2 months' THEN date(acct_period_start, '-2 months')
            WHEN '3 months' THEN date(acct_period_start, '-3 months')
            ELSE date(acct_period_start, '-1 month')
        END FROM user
        UNION ALL
        SELECT id, CASE acct_period_length
            WHEN '1 week' THEN date(acct_period_start, '+7 days')
            WHEN '2 weeks' THEN date(acct_period_start, '+14 days')
            WHEN '4 weeks' THEN date(acct_period_start, '+28 days')
            WHEN '2 months' THEN date(acct_period_start, '+2 months')
            WHEN '3 months' THEN date(acct_period_start, '+3 months')
            ELSE date(acct_period_start, '+1 month')
        END FROM user
    ) bounds ON bounds.user_id = u.id
    WHERE a.amount_minor > 0
      AND u.acct_period_start != ''
      AND p.period_start >= bounds.window_start
      AND p.period_start <= CASE u.acct_period_length
            WHEN '1 week' THEN date(bounds.window_start, '+6 days')
            WHEN '2 weeks' THEN date(bounds.window_start, '+13 days')
            WHEN '4 weeks' THEN date(bounds.window_start, '+27 days')
            WHEN '2 months' THEN date(bounds.window_start, '+2 months', '-1 day')
            WHEN '3 months' THEN date(bounds.window_start, '+3 months', '-1 day')
            ELSE date(bounds.window_start, '+1 month', '-1 day')
        END
    GROUP BY p.user_id, bounds.window_start, u.acct_period_length, a.category_id
) grouped;
