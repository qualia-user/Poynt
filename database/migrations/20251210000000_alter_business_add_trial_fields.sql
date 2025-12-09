-- Track business-level trials and plan decisions
ALTER TABLE business
    ADD COLUMN IF NOT EXISTS trial_eligible BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS trial_expires_at TIMESTAMPTZ;

UPDATE business
SET trial_eligible = COALESCE(trial_eligible, FALSE);

CREATE TABLE IF NOT EXISTS subscription_plan_audit (
    id SERIAL PRIMARY KEY,
    business_id VARCHAR(255) NOT NULL,
    store_id VARCHAR(255),
    plan_id VARCHAR(255) NOT NULL,
    decided_by VARCHAR(255) NOT NULL,
    decision_reason TEXT NOT NULL,
    decided_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_subscription_plan_audit_business ON subscription_plan_audit (business_id);
CREATE INDEX IF NOT EXISTS idx_subscription_plan_audit_store ON subscription_plan_audit (store_id);
