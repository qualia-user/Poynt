BEGIN;

ALTER TABLE transaction
    ADD COLUMN IF NOT EXISTS store_device_id VARCHAR(255),
    ADD COLUMN IF NOT EXISTS employee_user_id BIGINT,
    ADD COLUMN IF NOT EXISTS signature_required BOOLEAN,
    ADD COLUMN IF NOT EXISTS signature_captured BOOLEAN,
    ADD COLUMN IF NOT EXISTS pin_captured BOOLEAN,
    ADD COLUMN IF NOT EXISTS adjusted BOOLEAN,
    ADD COLUMN IF NOT EXISTS amounts_adjusted BOOLEAN,
    ADD COLUMN IF NOT EXISTS auth_only BOOLEAN,
    ADD COLUMN IF NOT EXISTS action_void BOOLEAN,
    ADD COLUMN IF NOT EXISTS voided BOOLEAN,
    ADD COLUMN IF NOT EXISTS reversal_void BOOLEAN,
    ADD COLUMN IF NOT EXISTS transaction_instruction VARCHAR(64),
    ADD COLUMN IF NOT EXISTS source VARCHAR(32),
    ADD COLUMN IF NOT EXISTS source_app VARCHAR(255),
    ADD COLUMN IF NOT EXISTS mcc VARCHAR(16),
    ADD COLUMN IF NOT EXISTS customer_language VARCHAR(16),
    ADD COLUMN IF NOT EXISTS customer_opted_no_tip BOOLEAN,
    ADD COLUMN IF NOT EXISTS approved_amount_minor BIGINT,
    ADD COLUMN IF NOT EXISTS acquirer VARCHAR(64),
    ADD COLUMN IF NOT EXISTS processor_transaction_id VARCHAR(255),
    ADD COLUMN IF NOT EXISTS links_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS processor_options JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS processor_response JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS amounts_json JSONB NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE transaction
    DROP COLUMN IF EXISTS order_id,
    DROP COLUMN IF EXISTS card_brand,
    DROP COLUMN IF EXISTS last4,
    DROP COLUMN IF EXISTS entry_mode;

DROP INDEX IF EXISTS idx_tx_order;
DROP INDEX IF EXISTS idx_tx_action_status;
CREATE INDEX IF NOT EXISTS idx_tx_business_updated ON transaction (business_id, updated_at_ext);
CREATE INDEX IF NOT EXISTS idx_tx_status ON transaction (status);
CREATE INDEX IF NOT EXISTS idx_tx_action ON transaction (action);

COMMIT;
