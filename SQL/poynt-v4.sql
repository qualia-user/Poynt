-- POYNT v4 SCHEMA (tenant-first clean DDL)
-- This version represents the minimal shared schema needed to bootstrap the
-- application. All business data now lives in per-tenant tables rendered from
-- `SQL/poynt-tenant-templates.sql` (and related template files) by the PHP
-- provisioner. No stored procedures are defined here; provisioning logic
-- executes in PHP.

-- GLOBAL REGISTRY
CREATE TABLE IF NOT EXISTS business (
    business_id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    active BOOLEAN NOT NULL DEFAULT FALSE
);

-- TENANT SCHEMA TRACKING
CREATE TABLE IF NOT EXISTS tenant_schema_version (
    tenant_id VARCHAR(255) PRIMARY KEY,
    version INTEGER NOT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS tenant_table_registry (
    business_id VARCHAR(255) NOT NULL,
    table_name VARCHAR(255) NOT NULL,
    template_version INTEGER NOT NULL,
    registered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (business_id, table_name)
);
CREATE INDEX IF NOT EXISTS idx_tenant_table_registry_business ON tenant_table_registry (business_id, template_version DESC);
