CREATE TABLE IF NOT EXISTS transaction (
    transaction_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255),
    order_id VARCHAR(255),
    action VARCHAR(32),
    status VARCHAR(32),
    settlement_status VARCHAR(32),
    settled BOOLEAN,
    partially_approved BOOLEAN,
    txn_amount_minor BIGINT,
    order_amount_minor BIGINT,
    tip_amount_minor BIGINT,
    cashback_amount_minor BIGINT,
    currency VARCHAR(3),
    card_brand VARCHAR(32),
    last4 VARCHAR(4),
    entry_mode VARCHAR(64),
    processor VARCHAR(64),
    processor_status VARCHAR(64),
    processor_code VARCHAR(32),
    approval_code VARCHAR(32),
    retrieval_ref VARCHAR(64),
    batch_id VARCHAR(64),
    customer_user_id BIGINT,
    references_json JSONB NOT NULL DEFAULT '[]',
    funding_source JSONB NOT NULL DEFAULT '{}',
    context_json JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tx_business_updated ON transaction (business_id, updated_at_ext);
CREATE INDEX IF NOT EXISTS idx_tx_order ON transaction (order_id);
CREATE INDEX IF NOT EXISTS idx_tx_action_status ON transaction (action, status);
