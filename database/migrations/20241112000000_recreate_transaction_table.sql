DROP TABLE IF EXISTS transaction_receipt;
DROP TABLE IF EXISTS transaction;

CREATE TABLE transaction (
    transaction_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255),
    store_device_id VARCHAR(255),
    employee_user_id BIGINT,
    signature_required BOOLEAN,
    signature_captured BOOLEAN,
    pin_captured BOOLEAN,
    adjusted BOOLEAN,
    amounts_adjusted BOOLEAN,
    auth_only BOOLEAN,
    partially_approved BOOLEAN,
    action_void BOOLEAN,
    voided BOOLEAN,
    settled BOOLEAN,
    reversal_void BOOLEAN,
    action VARCHAR(32),
    status VARCHAR(32),
    settlement_status VARCHAR(32),
    transaction_instruction VARCHAR(64),
    source VARCHAR(32),
    source_app VARCHAR(255),
    mcc VARCHAR(16),
    customer_user_id BIGINT,
    customer_language VARCHAR(16),
    customer_opted_no_tip BOOLEAN,
    txn_amount_minor BIGINT,
    order_amount_minor BIGINT,
    tip_amount_minor BIGINT,
    cashback_amount_minor BIGINT,
    currency VARCHAR(3),
    approved_amount_minor BIGINT,
    processor VARCHAR(64),
    acquirer VARCHAR(64),
    processor_status VARCHAR(64),
    processor_code VARCHAR(32),
    approval_code VARCHAR(32),
    retrieval_ref VARCHAR(64),
    batch_id VARCHAR(64),
    processor_transaction_id VARCHAR(255),
    references_json JSONB NOT NULL DEFAULT '[]',
    links_json JSONB NOT NULL DEFAULT '[]',
    funding_source JSONB NOT NULL DEFAULT '{}',
    context_json JSONB NOT NULL DEFAULT '{}',
    processor_options JSONB NOT NULL DEFAULT '{}',
    processor_response JSONB NOT NULL DEFAULT '{}',
    amounts_json JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tx_business_updated ON transaction (business_id, updated_at_ext);
CREATE INDEX IF NOT EXISTS idx_tx_status ON transaction (status);
CREATE INDEX IF NOT EXISTS idx_tx_action ON transaction (action);

CREATE TABLE transaction_receipt (
    transaction_id VARCHAR(255) PRIMARY KEY REFERENCES transaction(transaction_id) ON DELETE CASCADE,
    html TEXT,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
