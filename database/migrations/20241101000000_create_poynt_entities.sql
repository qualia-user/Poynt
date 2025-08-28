CREATE TABLE IF NOT EXISTS "order" (
    order_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255),
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS order_item (
    order_item_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    product_id VARCHAR(255),
    product_variant_id VARCHAR(255),
    name VARCHAR(255),
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS order_history (
    order_history_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    status VARCHAR(255),
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS order_shipment (
    order_shipment_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS transaction (
    transaction_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) REFERENCES "order"(order_id) ON DELETE SET NULL,
    business_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS transaction_receipt (
    transaction_receipt_id SERIAL PRIMARY KEY,
    transaction_id VARCHAR(255) NOT NULL REFERENCES transaction(transaction_id) ON DELETE CASCADE,
    data JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS customer (
    customer_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS business_user (
    business_user_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS product (
    product_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS product_variant (
    product_variant_id VARCHAR(255) PRIMARY KEY,
    product_id VARCHAR(255) NOT NULL REFERENCES product(product_id) ON DELETE CASCADE,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS inventory_summary (
    inventory_summary_id VARCHAR(255) PRIMARY KEY,
    product_id VARCHAR(255) NOT NULL REFERENCES product(product_id) ON DELETE CASCADE,
    product_variant_id VARCHAR(255) REFERENCES product_variant(product_variant_id) ON DELETE CASCADE,
    quantity INT NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS inventory (
    inventory_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS variant_inventory (
    variant_inventory_id VARCHAR(255) PRIMARY KEY,
    inventory_id VARCHAR(255) NOT NULL REFERENCES inventory(inventory_id) ON DELETE CASCADE,
    product_variant_id VARCHAR(255) NOT NULL REFERENCES product_variant(product_variant_id) ON DELETE CASCADE,
    quantity INT NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    UNIQUE(inventory_id, product_variant_id)
);

CREATE TABLE IF NOT EXISTS catalog (
    catalog_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS catalog_product (
    catalog_product_id VARCHAR(255) PRIMARY KEY,
    catalog_id VARCHAR(255) NOT NULL REFERENCES catalog(catalog_id) ON DELETE CASCADE,
    product_id VARCHAR(255) NOT NULL REFERENCES product(product_id) ON DELETE CASCADE,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    UNIQUE(catalog_id, product_id)
);

CREATE TABLE IF NOT EXISTS category (
    category_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    parent_category_id VARCHAR(255) REFERENCES category(category_id) ON DELETE SET NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS tax (
    tax_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    rate NUMERIC(5,2),
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS paylink (
    paylink_id VARCHAR(255) PRIMARY KEY,
    transaction_id VARCHAR(255) REFERENCES transaction(transaction_id) ON DELETE SET NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS hook (
    hook_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS hook_delivery (
    hook_delivery_id VARCHAR(255) PRIMARY KEY,
    hook_id VARCHAR(255) NOT NULL REFERENCES hook(hook_id) ON DELETE CASCADE,
    status VARCHAR(255),
    metadata JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_order_business_id ON "order"(business_id);
CREATE INDEX IF NOT EXISTS idx_order_store_id ON "order"(store_id);
CREATE INDEX IF NOT EXISTS idx_order_item_order_id ON order_item(order_id);
CREATE INDEX IF NOT EXISTS idx_order_history_order_id ON order_history(order_id);
CREATE INDEX IF NOT EXISTS idx_order_shipment_order_id ON order_shipment(order_id);
CREATE INDEX IF NOT EXISTS idx_transaction_order_id ON transaction(order_id);
CREATE INDEX IF NOT EXISTS idx_transaction_business_id ON transaction(business_id);
CREATE INDEX IF NOT EXISTS idx_transaction_receipt_transaction_id ON transaction_receipt(transaction_id);
CREATE INDEX IF NOT EXISTS idx_customer_business_id ON customer(business_id);
CREATE INDEX IF NOT EXISTS idx_business_user_business_id ON business_user(business_id);
CREATE INDEX IF NOT EXISTS idx_product_business_id ON product(business_id);
CREATE INDEX IF NOT EXISTS idx_product_variant_product_id ON product_variant(product_id);
CREATE INDEX IF NOT EXISTS idx_inventory_summary_product_id ON inventory_summary(product_id);
CREATE INDEX IF NOT EXISTS idx_inventory_summary_variant_id ON inventory_summary(product_variant_id);
CREATE INDEX IF NOT EXISTS idx_inventory_business_id ON inventory(business_id);
CREATE INDEX IF NOT EXISTS idx_variant_inventory_inventory_id ON variant_inventory(inventory_id);
CREATE INDEX IF NOT EXISTS idx_variant_inventory_variant_id ON variant_inventory(product_variant_id);
CREATE INDEX IF NOT EXISTS idx_catalog_business_id ON catalog(business_id);
CREATE INDEX IF NOT EXISTS idx_catalog_product_catalog_id ON catalog_product(catalog_id);
CREATE INDEX IF NOT EXISTS idx_catalog_product_product_id ON catalog_product(product_id);
CREATE INDEX IF NOT EXISTS idx_category_business_id ON category(business_id);
CREATE INDEX IF NOT EXISTS idx_category_parent_category_id ON category(parent_category_id);
CREATE INDEX IF NOT EXISTS idx_tax_business_id ON tax(business_id);
CREATE INDEX IF NOT EXISTS idx_paylink_transaction_id ON paylink(transaction_id);
CREATE INDEX IF NOT EXISTS idx_hook_business_id ON hook(business_id);
CREATE INDEX IF NOT EXISTS idx_hook_delivery_hook_id ON hook_delivery(hook_id);
