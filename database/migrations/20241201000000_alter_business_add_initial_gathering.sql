-- Track initial resource gathering status on business records
ALTER TABLE business
    ADD COLUMN IF NOT EXISTS initial_gathering BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE business
SET initial_gathering = COALESCE(initial_gathering, FALSE);
