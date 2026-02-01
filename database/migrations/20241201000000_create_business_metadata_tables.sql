-- Business metadata and schema versioning
-- business_meta stores a single lifecycle row per business, capturing provisioning windows and the latest status.
--   business_id: primary key matching the logical business identifier.
--   status: lifecycle state (e.g., provisioning, active, deprovisioned).
--   created_at: when the row was first registered.
--   provisioned_at: when the business was fully provisioned.
--   deprovisioned_at: when the business was retired or archived.
CREATE TABLE IF NOT EXISTS business_meta (
    business_id VARCHAR(255) PRIMARY KEY,
    status VARCHAR(50) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    provisioned_at TIMESTAMPTZ,
    deprovisioned_at TIMESTAMPTZ
);

-- business_schema_version tracks DDL/application version history for each business to coordinate per-business migrations.
--   business_id: scoped business identifier (references business_meta).
--   version: ordered DDL version applied to that business.
--   applied_at: timestamp for when the version was applied.
--   notes: optional operator or automation notes for the version.
CREATE TABLE IF NOT EXISTS business_schema_version (
    business_id VARCHAR(255) NOT NULL,
    version INTEGER NOT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    notes TEXT,
    PRIMARY KEY (business_id, version),
    CONSTRAINT fk_business_schema_version_business
        FOREIGN KEY (business_id) REFERENCES business_meta (business_id)
);

CREATE INDEX IF NOT EXISTS idx_business_schema_version_applied_at
    ON business_schema_version (applied_at);
