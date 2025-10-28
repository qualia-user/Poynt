-- POYNT

-- =========================================================
-- EXISTING TABLES  +  SOURCE URLs
-- =========================================================

-- Source (GET): https://services.poynt.net/businesses
--               https://services.poynt.net/businesses/{businessId}
--   Napomena: Business resurs vraća cijelu hijerarhiju (uklj. stores & storeDevices). 
--   Docs: Poynt API » Businesses (GET /businesses). 
CREATE TABLE business (
  business_id VARCHAR(255) PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  metadata    JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  active      BOOLEAN      NOT NULL DEFAULT FALSE
);

-- Source (GET): preko Business payload-a (stores[] unutar businessa):
--               https://services.poynt.net/businesses/{businessId}
--   (Store objekt je dio hijerarhije Business-a; sadrži i storeDevices[]).
--   Docs: Store model (Store.storeDevices) i Businesses sekcija.
CREATE TABLE store (
  store_id    VARCHAR(255) PRIMARY KEY,
  business_id VARCHAR(255) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  metadata    JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Source (GET): preko Store payload-a (storeDevices[]), dobiveno kroz:
--               https://services.poynt.net/businesses/{businessId}
--   (StoreDevice je dio Store objekta.)
--   Docs: StoreDevice model (atributi) u API referenci.
CREATE TABLE terminal (
  terminal_id VARCHAR(255) PRIMARY KEY,
  store_id    VARCHAR(255) NOT NULL,
  metadata    JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Source (POST – OAuth token, nema GET-a): https://services.poynt.net/token
--   Koristiš za dobiti/refreshati access_token; tablica je interna pohrana.
--   Docs: OAuth » POST /token.
CREATE TABLE app_token (
  business_id   VARCHAR(255) PRIMARY KEY,
  access_token  TEXT           NOT NULL,
  refresh_token TEXT           NOT NULL,
  expires_at    TIMESTAMPTZ    NOT NULL,
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_app_token_expires_at ON app_token(expires_at);

-- Source (POST – OAuth token, nema GET-a): https://services.poynt.net/token
--   Interna pohrana merchant-level tokena (isto kao gore).
--   Docs: OAuth » POST /token.
CREATE TABLE merchant_token (
  business_id   VARCHAR(255) PRIMARY KEY,
  access_token  TEXT           NOT NULL,
  refresh_token TEXT           NOT NULL,
  expires_at    TIMESTAMPTZ    NOT NULL,
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_merchant_token_expires_at ON merchant_token(expires_at);

-- Source (GET – Billing API): https://billing.poynt.net/apps/{appId}/subscriptions?businessId={businessId}
--   Vraća aktivne pretplate za tvoj app; ova tablica je tvoja BI kopija.
--   Docs: “REST API Integration – Get Subscriptions List”.
CREATE TABLE subscription (
  subscription_id     VARCHAR(255)     PRIMARY KEY,
  business_id         VARCHAR(255)     NOT NULL,
  store_id            VARCHAR(255),
  plan_id             VARCHAR(255)     NOT NULL,
  status              VARCHAR(50)      NOT NULL,
  phase               VARCHAR(50)      NOT NULL,
  trial_start_at      TIMESTAMPTZ,
  trial_end_at        TIMESTAMPTZ,
  start_at            TIMESTAMPTZ      NOT NULL,
  current_period_end  TIMESTAMPTZ,
  cancel_at_period_end BOOLEAN         NOT NULL DEFAULT FALSE,
  canceled_at         TIMESTAMPTZ,
  created_at          TIMESTAMPTZ      NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ      NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_subscription_current_period_end ON subscription(current_period_end);
CREATE INDEX idx_subscription_store_status ON subscription(store_id, status);

-- Source: inbound webhooks (tvoj endpoint prima POST od Poynta) – nema Poynt GET-a.
--   Za pregled konfiguracije i isporuka postoje odvojeni GET-ovi (/hooks, /hooks/{id}/deliveries) koje već
--   spremamo u zasebne tablice (hook, hook_delivery) u novom dijelu sheme.
CREATE TABLE webhook_audit (
  id             SERIAL         PRIMARY KEY,
  event_type     VARCHAR(100)   NOT NULL,
  payload        JSONB          NOT NULL,
  headers        JSONB          NOT NULL,
  received_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
  processed      BOOLEAN        NOT NULL DEFAULT FALSE,
  error_message  TEXT
);
CREATE INDEX idx_webhook_audit_event_type ON webhook_audit(event_type);
CREATE INDEX idx_webhook_audit_received_at ON webhook_audit(received_at);


CREATE TABLE log (
    id SERIAL PRIMARY KEY,                  -- Unique ID for each log entry
    request_id UUID NOT NULL,              -- Unique request identifier for grouping logs
    type TEXT,                             -- Log type (e.g., 'activity', 'error', 'access')
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Time of log entry
    level SMALLINT,                        -- Log level (e.g., '1' for DEBUG, '2' for INFO, '3' for ERROR)
    channel VARCHAR(64),                   -- Log channel (e.g., 'app', 'auth', 'db')
    message TEXT,                          -- Log message
    merchant VARCHAR(64),                          -- User ID associated with the log
    url TEXT,                              -- URL of the request (if applicable)
    details JSONB NOT NULL                 -- Flexible details column for log-specific properties
);

CREATE INDEX idx_log_request_id ON log (request_id);
CREATE INDEX idx_log_type ON log (type);
CREATE INDEX idx_log_timestamp ON log (timestamp);
CREATE INDEX idx_log_channel ON log (channel);
CREATE INDEX idx_log_level ON log (level);
CREATE INDEX idx_log_details_jsonb ON log USING gin (details);

CREATE TABLE token_refresh_log (
    id SERIAL PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    token_type VARCHAR(20) NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    success BOOLEAN NOT NULL,
    message TEXT
);

CREATE INDEX idx_token_refresh_log_lookup
    ON token_refresh_log (business_id, token_type, attempted_at);


-- =========================================================
-- NOVE TABLICE + SOURCE GET URL-ovi
-- =========================================================

-- =========================
-- ORDERS
-- =========================
-- Source (GET): /businesses/{businessId}/orders
--               /businesses/{businessId}/orders/{orderId}
CREATE TABLE "order" (
  order_id        VARCHAR(255) PRIMARY KEY,
  business_id     VARCHAR(255) NOT NULL,
  store_id        VARCHAR(255),
  currency        VARCHAR(3),

  -- statuses{}
  status                      VARCHAR(32),
  fulfillment_status          VARCHAR(32),
  transaction_status_summary  VARCHAR(32),

  -- base totals (minor units)
  subtotal_minor        BIGINT,
  discount_total_minor  BIGINT,
  tax_total_minor       BIGINT,
  tip_total_minor       BIGINT,
  fee_total_minor       BIGINT,
  shipping_total_minor  BIGINT,
  net_total_minor       BIGINT,

  tax_exempted    BOOLEAN,
  valid           BOOLEAN,
  accepted        BOOLEAN,
  notes           TEXT,

  -- kontekst/kratka polja
  customer_user_id BIGINT,
  employee_user_id BIGINT,
  store_device_id  VARCHAR(255),
  source           VARCHAR(32),
  source_app       VARCHAR(128),

  -- ugniježđene strukture ostaju u JSONB (nema zasebne tablice za order.transactions[])
  customer_json      JSONB NOT NULL DEFAULT '{}',
  transactions_json  JSONB NOT NULL DEFAULT '[]',
  amounts_json       JSONB NOT NULL DEFAULT '{}',
  context_json       JSONB NOT NULL DEFAULT '{}',
  raw_payload        JSONB NOT NULL DEFAULT '{}',

  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_order_business_updated ON "order"(business_id, updated_at_ext);
CREATE INDEX idx_order_store_status     ON "order"(store_id, status);

-- Source (GET, stavke su dio Order payloada): /businesses/{businessId}/orders/{orderId}
CREATE TABLE order_item (
  order_id         VARCHAR(255) NOT NULL,
  order_item_id    VARCHAR(255) NOT NULL,
  product_id       VARCHAR(255),
  name             VARCHAR(255),
  sku              VARCHAR(255),
  category_id      VARCHAR(255),
  quantity         NUMERIC(18,3),
  unit_price_minor BIGINT,
  discount_minor   BIGINT,
  tax_minor        BIGINT,
  fee_minor        BIGINT,
  unit_of_measure  VARCHAR(32),
  status           VARCHAR(32),
  taxes_json       JSONB NOT NULL DEFAULT '[]',
  raw_payload      JSONB NOT NULL DEFAULT '{}',
  created_at_ext   TIMESTAMPTZ,
  updated_at_ext   TIMESTAMPTZ,
  PRIMARY KEY (order_id, order_item_id)
);
CREATE INDEX idx_order_item_product ON order_item(product_id);

-- Source (GET): /businesses/{businessId}/orders/{orderId}/history
CREATE TABLE order_history (
  order_id   VARCHAR(255) NOT NULL,
  event      VARCHAR(64),
  ts_ext     TIMESTAMPTZ,
  payload    JSONB NOT NULL DEFAULT '{}',
  PRIMARY KEY (order_id, event, ts_ext)
);

-- Source (GET): /businesses/{businessId}/orders/{orderId}/shipments
CREATE TABLE order_shipment (
  order_id         VARCHAR(255) NOT NULL,
  shipment_id      VARCHAR(255) NOT NULL,
  status           VARCHAR(32),
  amount_minor     BIGINT,
  carrier          VARCHAR(64),
  tracking_no      VARCHAR(128),
  fulfill_at_ext   TIMESTAMPTZ,
  shipped_at_ext   TIMESTAMPTZ,
  delivered_at_ext TIMESTAMPTZ,
  payload          JSONB NOT NULL DEFAULT '{}',
  PRIMARY KEY (order_id, shipment_id)
);

-- =========================
-- TRANSACTIONS (kanonski payments feed)
-- =========================
-- Source (GET): /businesses/{businessId}/transactions
--               /businesses/{businessId}/transactions/{transactionId}
CREATE TABLE transaction (
  transaction_id     VARCHAR(255) PRIMARY KEY,
  business_id        VARCHAR(255) NOT NULL,
  store_id           VARCHAR(255),
  order_id           VARCHAR(255),     -- iz references[type='POYNT_ORDER']
  action             VARCHAR(32),      -- SALE/AUTHORIZE/CAPTURE/REFUND/VOID...
  status             VARCHAR(32),      -- APPROVED/DECLINED/VOIDED/...
  settlement_status  VARCHAR(32),
  settled            BOOLEAN,
  partially_approved BOOLEAN,

  -- amounts (minor units)
  txn_amount_minor       BIGINT,
  order_amount_minor     BIGINT,
  tip_amount_minor       BIGINT,
  cashback_amount_minor  BIGINT,
  currency               VARCHAR(3),

  -- card / entry
  card_brand         VARCHAR(32),
  last4              VARCHAR(4),
  entry_mode         VARCHAR(64),

  -- processor
  processor          VARCHAR(64),
  processor_status   VARCHAR(64),
  processor_code     VARCHAR(32),
  approval_code      VARCHAR(32),
  retrieval_ref      VARCHAR(64),
  batch_id           VARCHAR(64),

  customer_user_id   BIGINT,

  references_json    JSONB NOT NULL DEFAULT '[]',
  funding_source     JSONB NOT NULL DEFAULT '{}',
  context_json       JSONB NOT NULL DEFAULT '{}',
  raw_payload        JSONB NOT NULL DEFAULT '{}',

  created_at_ext     TIMESTAMPTZ,
  updated_at_ext     TIMESTAMPTZ,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_tx_business_updated ON transaction(business_id, updated_at_ext);
CREATE INDEX idx_tx_order            ON transaction(order_id);
CREATE INDEX idx_tx_action_status    ON transaction(action, status);

-- Source (GET, opcionalno): /businesses/{businessId}/transactions/{transactionId}/receipt
CREATE TABLE transaction_receipt (
  transaction_id VARCHAR(255) PRIMARY KEY,
  html           TEXT,
  payload        JSONB NOT NULL DEFAULT '{}',
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- =========================
-- CUSTOMERS
-- =========================
-- Source (GET): /businesses/{businessId}/customers        -- (search/list)
--               /businesses/{businessId}/customers/{customerId}
CREATE TABLE customer (
  customer_id     BIGINT PRIMARY KEY,     -- numerički u primjerima
  business_id     VARCHAR(255) NOT NULL,
  first_name      VARCHAR(255),
  last_name       VARCHAR(255),
  emails_json     JSONB NOT NULL DEFAULT '[]',
  phones_json     JSONB NOT NULL DEFAULT '[]',
  attributes      JSONB NOT NULL DEFAULT '{}',
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_customer_business_updated ON customer(business_id, updated_at_ext);

-- =========================
-- BUSINESS USERS
-- =========================
-- Source (GET): /businesses/{businessId}/users
--               /businesses/{businessId}/users/{userId}
CREATE TABLE business_user (
  business_id    VARCHAR(255) NOT NULL,
  user_id        BIGINT       NOT NULL,
  first_name     VARCHAR(255),
  last_name      VARCHAR(255),
  role           VARCHAR(64),
  status         VARCHAR(32),
  credentials    JSONB NOT NULL DEFAULT '[]',
  employment     JSONB NOT NULL DEFAULT '{}',
  raw_payload    JSONB NOT NULL DEFAULT '{}',
  created_at_ext TIMESTAMPTZ,
  updated_at_ext TIMESTAMPTZ,
  PRIMARY KEY (business_id, user_id)
);
CREATE INDEX idx_user_business ON business_user(business_id, role);

-- =========================
-- PRODUCTS & VARIANTS
-- =========================
-- Source (GET): /businesses/{businessId}/products
--               /businesses/{businessId}/products/{productId}
CREATE TABLE product (
  product_id      VARCHAR(255) PRIMARY KEY,
  business_id     VARCHAR(255) NOT NULL,
  name            VARCHAR(255) NOT NULL,
  sku             VARCHAR(255),
  price_minor     BIGINT,
  currency        VARCHAR(3),
  category_id     VARCHAR(255),
  is_active       BOOLEAN,
  attributes      JSONB NOT NULL DEFAULT '{}',
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_product_business_updated ON product(business_id, updated_at_ext);

-- Source (GET): /businesses/{businessId}/products/{productId}/variants
CREATE TABLE product_variant (
  product_id      VARCHAR(255) NOT NULL,
  variant_id      VARCHAR(255) NOT NULL,
  name            VARCHAR(255),
  sku             VARCHAR(255),
  price_minor     BIGINT,
  attributes      JSONB NOT NULL DEFAULT '{}',
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  PRIMARY KEY (product_id, variant_id)
);

-- =========================
-- INVENTORY
-- =========================
-- Source (GET): /businesses/{businessId}/inventory/summary
CREATE TABLE inventory_summary (
  business_id     VARCHAR(255) NOT NULL,
  product_id      VARCHAR(255) NOT NULL,
  total_on_hand   NUMERIC(18,3),
  total_reserved  NUMERIC(18,3),
  updated_at_ext  TIMESTAMPTZ,
  payload         JSONB NOT NULL DEFAULT '{}',
  PRIMARY KEY (business_id, product_id)
);

-- Source (GET): /businesses/{businessId}/inventory?storeId={storeId}
CREATE TABLE inventory (
  business_id     VARCHAR(255) NOT NULL,
  store_id        VARCHAR(255) NOT NULL,
  product_id      VARCHAR(255) NOT NULL,
  on_hand         NUMERIC(18,3),
  reserved        NUMERIC(18,3),
  updated_at_ext  TIMESTAMPTZ,
  payload         JSONB NOT NULL DEFAULT '{}',
  PRIMARY KEY (business_id, store_id, product_id)
);

-- Source (GET): /businesses/{businessId}/inventory/variants?storeId={storeId}
CREATE TABLE variant_inventory (
  business_id     VARCHAR(255) NOT NULL,
  store_id        VARCHAR(255) NOT NULL,
  product_id      VARCHAR(255) NOT NULL,
  variant_id      VARCHAR(255) NOT NULL,
  on_hand         NUMERIC(18,3),
  reserved        NUMERIC(18,3),
  updated_at_ext  TIMESTAMPTZ,
  payload         JSONB NOT NULL DEFAULT '{}',
  PRIMARY KEY (business_id, store_id, product_id, variant_id)
);

-- =========================
-- CATALOGS & CATEGORIES
-- =========================
-- Source (GET): /businesses/{businessId}/catalogs
--               /businesses/{businessId}/catalogs/{catalogId}
--               /businesses/{businessId}/catalogs/{catalogId}/full
CREATE TABLE catalog (
  catalog_id      VARCHAR(255) PRIMARY KEY,
  business_id     VARCHAR(255) NOT NULL,
  name            VARCHAR(255),
  device_id       VARCHAR(255),
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- (korisno kad ingestiraš /full varijantu)
CREATE TABLE catalog_product (
  catalog_id      VARCHAR(255) NOT NULL,
  product_id      VARCHAR(255) NOT NULL,
  position        INTEGER,
  payload         JSONB NOT NULL DEFAULT '{}',
  PRIMARY KEY (catalog_id, product_id)
);

-- Source (GET): /businesses/{businessId}/categories
--               /businesses/{businessId}/categories/{categoryId}
CREATE TABLE category (
  category_id     VARCHAR(255) PRIMARY KEY,
  business_id     VARCHAR(255) NOT NULL,
  name            VARCHAR(255),
  parent_id       VARCHAR(255),
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ
);

-- =========================
-- TAXES
-- =========================
-- Source (GET): /businesses/{businessId}/taxes
--               /businesses/{businessId}/taxes/{taxId}
CREATE TABLE tax (
  tax_id          VARCHAR(255) PRIMARY KEY,
  business_id     VARCHAR(255) NOT NULL,
  name            VARCHAR(255),
  rate_bp         INTEGER,          -- npr. 725 = 7.25%
  scope           VARCHAR(64),      -- product/category/catalog/business
  active          BOOLEAN,
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_tax_business ON tax(business_id, active);

-- =========================
-- PAYLINKS
-- =========================
-- Source (GET): /businesses/{businessId}/paylinks
--               /businesses/{businessId}/paylinks/{paylinkId}
--               /businesses/{businessId}/paylinks/domain/{domain}
CREATE TABLE paylink (
  paylink_id      VARCHAR(255) PRIMARY KEY,
  business_id     VARCHAR(255) NOT NULL,
  url             TEXT,
  vanity_url      TEXT,
  domain          VARCHAR(255),
  title           TEXT,
  description     TEXT,
  status          VARCHAR(32),
  amount_minor    BIGINT,
  currency        VARCHAR(3),
  metadata        JSONB NOT NULL DEFAULT '{}',
  expires_at_ext  TIMESTAMPTZ,
  created_at_ext  TIMESTAMPTZ,
  updated_at_ext  TIMESTAMPTZ,
  raw_payload     JSONB NOT NULL DEFAULT '{}',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE paylink_item (
  paylink_id    VARCHAR(255) NOT NULL,
  business_id   VARCHAR(255) NOT NULL,
  item_ref      VARCHAR(64) NOT NULL,
  item_id       VARCHAR(255),
  name          VARCHAR(255),
  description   TEXT,
  amount_minor  BIGINT,
  currency      VARCHAR(3),
  quantity      NUMERIC(18,3),
  metadata      JSONB NOT NULL DEFAULT '{}',
  payload       JSONB NOT NULL DEFAULT '{}',
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (paylink_id, item_ref)
);
CREATE INDEX idx_paylink_item_business ON paylink_item(business_id);

CREATE TABLE paylink_payment (
  paylink_id       VARCHAR(255) NOT NULL,
  business_id      VARCHAR(255) NOT NULL,
  payment_ref      VARCHAR(64) NOT NULL,
  payment_id       VARCHAR(255),
  status           VARCHAR(64),
  amount_minor     BIGINT,
  currency         VARCHAR(3),
  processed_at_ext TIMESTAMPTZ,
  payload          JSONB NOT NULL DEFAULT '{}',
  created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (paylink_id, payment_ref)
);
CREATE INDEX idx_paylink_payment_business ON paylink_payment(business_id);

CREATE TABLE paylink_link (
  paylink_id  VARCHAR(255) NOT NULL,
  business_id VARCHAR(255) NOT NULL,
  link_ref    VARCHAR(64) NOT NULL,
  rel         VARCHAR(128),
  href        TEXT,
  method      VARCHAR(16),
  payload     JSONB NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (paylink_id, link_ref)
);
CREATE INDEX idx_paylink_link_business ON paylink_link(business_id);

-- =========================
-- HOOKS (konfiguracija) & DELIVERIES (GET strana)
-- =========================
-- Source (GET): /businesses/{businessId}/hooks
--               /businesses/{businessId}/hooks/{hookId}
CREATE TABLE hook (
  hook_id        VARCHAR(255) PRIMARY KEY,
  business_id    VARCHAR(255) NOT NULL,
  url            TEXT,
  event_types    TEXT[],
  status         VARCHAR(32),
  raw_payload    JSONB NOT NULL DEFAULT '{}',
  created_at_ext TIMESTAMPTZ,
  updated_at_ext TIMESTAMPTZ
);

-- Source (GET): /businesses/{businessId}/hooks/{hookId}/deliveries
CREATE TABLE hook_delivery (
  delivery_id      VARCHAR(255) PRIMARY KEY,
  hook_id          VARCHAR(255) NOT NULL,
  business_id      VARCHAR(255) NOT NULL,
  event_type       VARCHAR(128),
  delivered_at_ext TIMESTAMPTZ,
  status           VARCHAR(32),
  http_status      INTEGER,
  retry_count      INTEGER,
  raw_payload      JSONB NOT NULL DEFAULT '{}'
);


select * from log;