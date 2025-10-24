create database oauth_integration;
create scheme poynt;

CREATE TABLE platform (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255),
    properties JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE business (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255),
    platform_id UUID REFERENCES platform(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE merchant (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),                     -- Unique identifier for the merchant
    business_id VARCHAR(255) UNIQUE NOT NULL,                          -- Unique identifier for the business
    store_id VARCHAR(255) NOT NULL,                                    -- Store identifier
    name VARCHAR(255) NOT NULL,                                        -- Business/merchant name
    legal_name VARCHAR(255),                                           -- Legal business name
    phone_number VARCHAR(15) NULL,                                     -- Phone number
    email VARCHAR(255),                                                -- Business email
    website_url TEXT,                                                  -- Business website URL
    street_address TEXT,                                               -- Street address
    city VARCHAR(255),                                                 -- City
    state VARCHAR(255),                                                -- State
    postal_code VARCHAR(20),                                           -- Postal code
    country VARCHAR(255),                                              -- Country
    timezone VARCHAR(255),                                             -- Timezone of the business
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,                    -- Record creation timestamp
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP                     -- Record update timestamp (manually updated in queries)
);
DROP TABLE merchant CASCADE;


CREATE TABLE token (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    merchant_id UUID REFERENCES merchant(id),
    access_token TEXT,
    refresh_token TEXT,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM token;

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

select * from log order by id desc limit 100;

select * from merchant;


CREATE TABLE merchant (
    business_id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

select * from merchant
-- drop table merchant cascade;


CREATE TABLE business_stores ();



CREATE TABLE app_token (
  business_id   VARCHAR(255)     NOT NULL,
  access_token  TEXT             NOT NULL,
  refresh_token TEXT             NOT NULL,
  expires_at    TIMESTAMPTZ      NOT NULL,
  
  created_at    TIMESTAMPTZ      NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ      NOT NULL DEFAULT now()
);

CREATE INDEX idx_app_token_expires_at
  ON app_token(expires_at);


-- drop table app_token cascade;




CREATE TABLE merchant_token (
  business_id   VARCHAR(255)     NOT NULL,
  
  access_token  TEXT             NOT NULL,
  refresh_token TEXT             NOT NULL,
  expires_at    TIMESTAMPTZ      NOT NULL,
  
  created_at    TIMESTAMPTZ      NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ      NOT NULL DEFAULT now()
  
  --PRIMARY KEY (business_id),
  --FOREIGN KEY (business_id)
    --REFERENCES merchant(business_id)
);

-- drop table merchant_token cascade;


-- select * from merchant_token;
CREATE INDEX idx_merchant_token_expires_at
  ON merchant_token(expires_at);





-----------------------------------------------------------------

DROP TABLE IF EXISTS terminal CASCADE;
DROP TABLE IF EXISTS merchant_token CASCADE;
DROP TABLE IF EXISTS app_token CASCADE;
DROP TABLE IF EXISTS store CASCADE;
DROP TABLE IF EXISTS business CASCADE;

-- 1) BUSINESS table (root of the hierarchy)
CREATE TABLE business (
  business_id VARCHAR(255) PRIMARY KEY,   -- Poynts business ID
  name        VARCHAR(255) NOT NULL,
  metadata    JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  active BOOLEAN NOT NULL DEFAULT FALSE
);


select * from business;

-- 2) STORE table (child of business, no foreign-key enforced)
CREATE TABLE store (
  store_id    VARCHAR(255) PRIMARY KEY,   -- Poynts store ID
  business_id VARCHAR(255) NOT NULL,      -- belongs to a business, but not an FK
  name        VARCHAR(255) NOT NULL,
  metadata    JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- 3) (Optional) TERMINAL table (child of store, no FK enforced)
CREATE TABLE terminal (
  terminal_id VARCHAR(255) PRIMARY KEY,   -- Poynts terminal ID
  store_id    VARCHAR(255) NOT NULL,      -- belongs to a store, but not an FK
  metadata    JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- 4) APP_TOKEN table (app-level token, keyed by business_id only)
CREATE TABLE app_token (
  business_id   VARCHAR(255) PRIMARY KEY,  -- no foreign-key enforced
  access_token  TEXT           NOT NULL,
  refresh_token TEXT           NOT NULL,
  expires_at    TIMESTAMPTZ    NOT NULL,
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_app_token_expires_at
  ON app_token(expires_at);

-- 5) MERCHANT_TOKEN table (merchant-level token, keyed by business_id only)
CREATE TABLE merchant_token (
  business_id   VARCHAR(255) PRIMARY KEY,  -- no foreign-key enforced
  access_token  TEXT           NOT NULL,
  refresh_token TEXT           NOT NULL,
  expires_at    TIMESTAMPTZ    NOT NULL,
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ    NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_merchant_token_expires_at
  ON merchant_token(expires_at);

-- 6) SUBSCRIPTION table
CREATE TABLE subscription (
  subscription_id     VARCHAR(255)     PRIMARY KEY,    -- Poynts subscription UUID or my own generated UUID
  business_id         VARCHAR(255)     NOT NULL,       -- which business this store belongs to
  store_id            VARCHAR(255)     NOT NULL,       -- which specific store this subscription is for
  plan_id             VARCHAR(255)     NOT NULL,       -- e.g. "free_trial", "basic_monthly", "pro_annual", etc.
  status              VARCHAR(50)      NOT NULL,       -- e.g. "trialing", "active", "past_due", "canceled"
  phase               VARCHAR(50)      NOT NULL,       -- e.g. "trial", "paid", "canceled", "grace_period"
  trial_start_at      TIMESTAMPTZ,                     -- when the trial began (if applicable)
  trial_end_at        TIMESTAMPTZ,                     -- when the trial will (or did) end
  start_at            TIMESTAMPTZ     NOT NULL,       -- when this subscription record became effective
  current_period_end  TIMESTAMPTZ,                     -- when the current billing period ends
  end_at              TIMESTAMPTZ,                     -- when the subscription fully ends (if scheduled)
  cancel_at_period_end BOOLEAN         NOT NULL DEFAULT FALSE,
  canceled_at         TIMESTAMPTZ,                     -- when (if ever) the subscription was canceled
  created_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- For quick lookups of subscriptions that are expiring soon:
CREATE INDEX idx_subscription_current_period_end
  ON subscription(current_period_end);

-- And if you want to find all active subscriptions by store_id:
CREATE INDEX idx_subscription_store_status
  ON subscription(store_id, status);

-- 7) WEBHOOK_AUDIT table (recording webhooks)
CREATE TABLE webhook_audit (
  id             SERIAL         PRIMARY KEY,
  event_type     VARCHAR(100)   NOT NULL,       -- e.g. "APPLICATION_SUBSCRIPTION_START", "BUSINESS_USER_CREATED", etc.
  payload        JSONB          NOT NULL,       -- full JSON body that Poynt POSTed
  headers        JSONB          NOT NULL,       -- store any relevant headers (signature, timestamp, etc.)
  received_at    TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
  processed      BOOLEAN        NOT NULL DEFAULT FALSE,  -- flag for whether your app has ingested this yet
  error_message  TEXT                            -- if processing failed, capture the error
);

-- Optional indexes if you want to search by event_type or timestamp:
CREATE INDEX idx_webhook_audit_event_type
  ON webhook_audit(event_type);

CREATE INDEX idx_webhook_audit_received_at
  ON webhook_audit(received_at);



INSERT INTO webhook_audit (event_type, payload, headers, processed, error_message) VALUES (?, ?, ?, ?, ?)

select * from webhook_audit;