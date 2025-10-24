CREATE TABLE IF NOT EXISTS business (
    business_id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    active BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS store (
    store_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS terminal (
    terminal_id VARCHAR(255) PRIMARY KEY,
    store_id VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS app_token (
    business_id VARCHAR(255) PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_app_token_expires_at ON app_token (expires_at);

CREATE TABLE IF NOT EXISTS merchant_token (
    business_id VARCHAR(255) PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_merchant_token_expires_at ON merchant_token (expires_at);

CREATE TABLE IF NOT EXISTS subscription (
    subscription_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255) NOT NULL,
    plan_id VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL,
    phase VARCHAR(50) NOT NULL,
    trial_start_at TIMESTAMPTZ,
    trial_end_at TIMESTAMPTZ,
    start_at TIMESTAMPTZ NOT NULL,
    current_period_end TIMESTAMPTZ,
    end_at TIMESTAMPTZ,
    cancel_at_period_end BOOLEAN NOT NULL DEFAULT FALSE,
    canceled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_subscription_current_period_end ON subscription (current_period_end);
CREATE INDEX IF NOT EXISTS idx_subscription_store_status ON subscription (store_id, status);

CREATE TABLE IF NOT EXISTS webhook_audit (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}',
    headers JSONB NOT NULL DEFAULT '{}',
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT
);
CREATE INDEX IF NOT EXISTS idx_webhook_audit_event_type ON webhook_audit (event_type);
CREATE INDEX IF NOT EXISTS idx_webhook_audit_received_at ON webhook_audit (received_at);

CREATE TABLE IF NOT EXISTS log (
    id SERIAL PRIMARY KEY,
    request_id UUID NOT NULL,
    type TEXT,
    timestamp TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    level SMALLINT,
    channel VARCHAR(64),
    message TEXT,
    merchant VARCHAR(64),
    url TEXT,
    details JSONB NOT NULL DEFAULT '{}'
);
CREATE INDEX IF NOT EXISTS idx_log_request_id ON log (request_id);
CREATE INDEX IF NOT EXISTS idx_log_type ON log (type);
CREATE INDEX IF NOT EXISTS idx_log_timestamp ON log (timestamp);
CREATE INDEX IF NOT EXISTS idx_log_channel ON log (channel);
CREATE INDEX IF NOT EXISTS idx_log_level ON log (level);
CREATE INDEX IF NOT EXISTS idx_log_details_jsonb ON log USING GIN (details);

CREATE TABLE IF NOT EXISTS token_refresh_log (
    id SERIAL PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    token_type VARCHAR(20) NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    success BOOLEAN NOT NULL,
    message TEXT
);
CREATE INDEX IF NOT EXISTS idx_token_refresh_log_lookup ON token_refresh_log (business_id, token_type, attempted_at);

CREATE TABLE IF NOT EXISTS customer (
    customer_id BIGINT PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    emails_json JSONB NOT NULL DEFAULT '[]',
    phones_json JSONB NOT NULL DEFAULT '[]',
    attributes JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_customer_business_updated ON customer (business_id, updated_at_ext);

CREATE TABLE IF NOT EXISTS business_user (
    business_id VARCHAR(255) NOT NULL,
    user_id BIGINT NOT NULL,
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    role VARCHAR(64),
    status VARCHAR(32),
    credentials JSONB NOT NULL DEFAULT '[]',
    employment JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (business_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_user_business ON business_user (business_id, role);

CREATE TABLE IF NOT EXISTS product (
    product_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(255),
    price_minor BIGINT,
    currency VARCHAR(3),
    category_id VARCHAR(255),
    is_active BOOLEAN,
    attributes JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_product_business_updated ON product (business_id, updated_at_ext);

CREATE TABLE IF NOT EXISTS product_variant (
    product_id VARCHAR(255) NOT NULL,
    variant_id VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    sku VARCHAR(255),
    price_minor BIGINT,
    attributes JSONB NOT NULL DEFAULT '{}',
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (product_id, variant_id)
);

CREATE TABLE IF NOT EXISTS inventory_summary (
    business_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255) NOT NULL,
    total_on_hand NUMERIC(18,3),
    total_reserved NUMERIC(18,3),
    updated_at_ext TIMESTAMPTZ,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (business_id, product_id)
);

CREATE TABLE IF NOT EXISTS inventory (
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255) NOT NULL,
    on_hand NUMERIC(18,3),
    reserved NUMERIC(18,3),
    updated_at_ext TIMESTAMPTZ,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (business_id, store_id, product_id)
);

CREATE TABLE IF NOT EXISTS variant_inventory (
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255) NOT NULL,
    variant_id VARCHAR(255) NOT NULL,
    on_hand NUMERIC(18,3),
    reserved NUMERIC(18,3),
    updated_at_ext TIMESTAMPTZ,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (business_id, store_id, product_id, variant_id)
);

CREATE TABLE IF NOT EXISTS catalog (
    catalog_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    device_id VARCHAR(255),
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS catalog_product (
    catalog_id VARCHAR(255) NOT NULL,
    product_id VARCHAR(255) NOT NULL,
    position INTEGER,
    payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (catalog_id, product_id)
);

CREATE TABLE IF NOT EXISTS category (
    category_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    parent_id VARCHAR(255),
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tax (
    tax_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    rate_bp INTEGER,
    scope VARCHAR(64),
    active BOOLEAN,
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tax_business ON tax (business_id, active);

CREATE TABLE IF NOT EXISTS paylink (
    paylink_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    domain VARCHAR(255),
    status VARCHAR(32),
    amount_minor BIGINT,
    currency VARCHAR(3),
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS hook (
    hook_id VARCHAR(255) PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    url TEXT,
    event_types TEXT[],
    status VARCHAR(32),
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at_ext TIMESTAMPTZ,
    updated_at_ext TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS hook_delivery (
    delivery_id VARCHAR(255) PRIMARY KEY,
    hook_id VARCHAR(255) NOT NULL,
    business_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(128),
    delivered_at_ext TIMESTAMPTZ,
    status VARCHAR(32),
    http_status INTEGER,
    retry_count INTEGER,
    raw_payload JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
