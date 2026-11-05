CREATE TABLE posting_lines (
    id INTEGER PRIMARY KEY,
    posting_id INTEGER NOT NULL REFERENCES postings(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0)
);

CREATE INDEX idx_posting_lines_posting ON posting_lines(posting_id, id);
