ALTER TABLE paylink
    ADD COLUMN IF NOT EXISTS url TEXT;

ALTER TABLE paylink
    ADD COLUMN IF NOT EXISTS vanity_url TEXT;

ALTER TABLE paylink
    ADD COLUMN IF NOT EXISTS title TEXT;

ALTER TABLE paylink
    ADD COLUMN IF NOT EXISTS description TEXT;

ALTER TABLE paylink
    ADD COLUMN IF NOT EXISTS expires_at_ext TIMESTAMPTZ;

ALTER TABLE paylink
    ADD COLUMN IF NOT EXISTS raw_payload JSONB NOT NULL DEFAULT '{}';

CREATE TABLE IF NOT EXISTS paylink_item (
    paylink_id VARCHAR(255) NOT NULL,
    business_id VARCHAR(255) NOT NULL,
    item_ref VARCHAR(64) NOT NULL,
    item_id VARCHAR(255),
    name VARCHAR(255),
    description TEXT,
    amount_minor BIGINT,
    currency VARCHAR(3),
    quantity NUMERIC(18, 3),
    metadata JSONB NOT NULL DEFAULT '{}',
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (paylink_id, item_ref)
);

CREATE INDEX IF NOT EXISTS idx_paylink_item_business ON paylink_item (business_id);

CREATE TABLE IF NOT EXISTS paylink_payment (
    paylink_id VARCHAR(255) NOT NULL,
    business_id VARCHAR(255) NOT NULL,
    payment_ref VARCHAR(64) NOT NULL,
    payment_id VARCHAR(255),
    status VARCHAR(64),
    amount_minor BIGINT,
    currency VARCHAR(3),
    processed_at_ext TIMESTAMPTZ,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (paylink_id, payment_ref)
);

CREATE INDEX IF NOT EXISTS idx_paylink_payment_business ON paylink_payment (business_id);

CREATE TABLE IF NOT EXISTS paylink_link (
    paylink_id VARCHAR(255) NOT NULL,
    business_id VARCHAR(255) NOT NULL,
    link_ref VARCHAR(64) NOT NULL,
    rel VARCHAR(128),
    href TEXT,
    method VARCHAR(16),
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (paylink_id, link_ref)
);

CREATE INDEX IF NOT EXISTS idx_paylink_link_business ON paylink_link (business_id);
