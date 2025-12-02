-- Business table template and naming conventions
-- Tables: <business_id>_<base_table>
-- Indexes: <business_id>_<base_table>_<col>_idx
-- Identity columns should start at 1 for each business-specific table.
-- Default business table options
--   Engine/access method: heap
--   Character set: UTF8
--   Collation: en_US.utf8

CREATE TABLE IF NOT EXISTS {{business_id}}_{{base_table}} (
    id BIGINT GENERATED ALWAYS AS IDENTITY (START WITH 1) PRIMARY KEY,
    example_col TEXT COLLATE "en_US.utf8" NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS {{business_id}}_{{base_table}}_example_col_idx
    ON {{business_id}}_{{base_table}} (example_col);
