CREATE TABLE IF NOT EXISTS "order" (
    order_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255),
    currency VARCHAR(3),
    status VARCHAR(32),
    fulfillment_status VARCHAR(32),
    transaction_status_summary VARCHAR(32),
    subtotal_minor BIGINT,
    discount_total_minor BIGINT,
    tax_total_minor BIGINT,
    tip_total_minor BIGINT,
    fee_total_minor BIGINT,
    shipping_total_minor BIGINT,
    net_total_minor BIGINT,
    tax_exempted BOOLEAN,
    valid BOOLEAN,
    accepted BOOLEAN,
    notes TEXT,
    customer_user_id BIGINT,
    employee_user_id BIGINT,
    store_device_id VARCHAR(255),
    source VARCHAR(32),
    source_app VARCHAR(128),
    customer_json JSONB NOT NULL DEFAULT '{}',
    transactions_json JSONB NOT NULL DEFAULT '[]',
    amounts_json JSONB NOT NULL DEFAULT '{}',
    context_json JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS order_item (
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    order_item_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255),
    name VARCHAR(255),
    sku VARCHAR(255),
    category_id VARCHAR(255),
    quantity NUMERIC(18,3),
    unit_price_minor BIGINT,
    discount_minor BIGINT,
    tax_minor BIGINT,
    fee_minor BIGINT,
    unit_of_measure VARCHAR(32),
    status VARCHAR(32),
    taxes_json JSONB NOT NULL DEFAULT '[]',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (order_id, order_item_id)
);

CREATE TABLE IF NOT EXISTS order_history (
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    event VARCHAR(64),
    ts_ext TIMESTAMPTZ,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (order_id, event, ts_ext)
);

CREATE TABLE IF NOT EXISTS order_shipment (
    order_id VARCHAR(255) NOT NULL REFERENCES "order"(order_id) ON DELETE CASCADE,
    shipment_id VARCHAR(255) NOT NULL,
    status VARCHAR(32),
    amount_minor BIGINT,
    carrier VARCHAR(64),
    tracking_no VARCHAR(128),
    fulfill_at_ext TIMESTAMPTZ,
    shipped_at_ext TIMESTAMPTZ,
    delivered_at_ext TIMESTAMPTZ,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (order_id, shipment_id)
);

CREATE INDEX IF NOT EXISTS idx_order_business_updated ON "order" (business_id, updated_at_ext);
CREATE INDEX IF NOT EXISTS idx_order_store_status ON "order" (store_id, status);
CREATE INDEX IF NOT EXISTS idx_order_item_product ON order_item (product_id);
