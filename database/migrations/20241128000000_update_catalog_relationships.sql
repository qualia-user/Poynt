ALTER TABLE catalog
    ADD COLUMN IF NOT EXISTS metadata JSONB NOT NULL DEFAULT '{}';

CREATE TABLE IF NOT EXISTS category_product (
    catalog_id VARCHAR(255) NOT NULL,
    category_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255) NOT NULL,
    business_id VARCHAR(255) NOT NULL,
    position INTEGER,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (catalog_id, category_id, product_id)
);

CREATE TABLE IF NOT EXISTS category_tax (
    catalog_id VARCHAR(255) NOT NULL,
    category_id VARCHAR(255) NOT NULL,
    tax_id VARCHAR(255) NOT NULL,
    business_id VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (catalog_id, category_id, tax_id)
);
