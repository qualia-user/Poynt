CREATE TABLE IF NOT EXISTS transaction_receipt (
    transaction_id VARCHAR(255) PRIMARY KEY REFERENCES transaction(transaction_id) ON DELETE CASCADE,
    html TEXT,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
