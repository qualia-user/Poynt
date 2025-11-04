ALTER TABLE catalog_product
    ADD COLUMN IF NOT EXISTS created_at_ext TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS updated_at_ext TIMESTAMPTZ;

ALTER TABLE catalog_product
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

ALTER TABLE catalog_product
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS catalog_product_tax (
    catalog_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255) NOT NULL,
    tax_id VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (catalog_id, product_id, tax_id)
);
