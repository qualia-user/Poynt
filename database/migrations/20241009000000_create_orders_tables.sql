CREATE TABLE IF NOT EXISTS "order" (
    order_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255),
    status VARCHAR(255),
    is_tax_inclusive BOOLEAN,
    customer_json JSONB,
    transactions_json JSONB,
    totals_json JSONB,
    taxes_json JSONB,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS order_item (
    order_item_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    name VARCHAR(255),
    quantity INTEGER,
    price_amount INTEGER,
    taxes_json JSONB,
    payload JSONB,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS order_history (
    order_history_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    status VARCHAR(255),
    payload JSONB,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS order_shipment (
    order_shipment_id VARCHAR(255) PRIMARY KEY,
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    status VARCHAR(255),
    taxes_json JSONB,
    payload JSONB,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_order_business_id ON "order"(business_id);
CREATE INDEX IF NOT EXISTS idx_order_store_id ON "order"(store_id);
CREATE INDEX IF NOT EXISTS idx_order_item_order_id ON order_item(order_id);
CREATE INDEX IF NOT EXISTS idx_order_history_order_id ON order_history(order_id);
CREATE INDEX IF NOT EXISTS idx_order_shipment_order_id ON order_shipment(order_id);
