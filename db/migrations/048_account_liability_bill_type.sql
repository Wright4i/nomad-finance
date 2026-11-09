ALTER TABLE accounts ADD COLUMN liability_bill_type TEXT NOT NULL DEFAULT 'debt'
    CHECK (liability_bill_type IN ('debt', 'vehicle'));
