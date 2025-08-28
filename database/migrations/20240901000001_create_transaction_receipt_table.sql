CREATE TABLE IF NOT EXISTS transaction_receipt (
    id SERIAL PRIMARY KEY,
    transaction_id VARCHAR(255) NOT NULL REFERENCES transaction(transaction_id) ON DELETE CASCADE,
    data JSON NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
