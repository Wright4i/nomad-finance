UPDATE bills
SET last_paid_on = next_due_date, updated_at = datetime('now')
WHERE recurrence = 'once'
  AND status = 'paid'
  AND last_paid_on IS NOT NULL
  AND last_paid_on != next_due_date;
