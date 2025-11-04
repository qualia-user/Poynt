CREATE TABLE IF NOT EXISTS catalog_available_discount (
    catalog_id VARCHAR(255) NOT NULL,
    discount_id VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (catalog_id, discount_id)
);
