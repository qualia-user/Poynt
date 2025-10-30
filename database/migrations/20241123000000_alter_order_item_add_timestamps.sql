ALTER TABLE order_item
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

ALTER TABLE order_item
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

ALTER TABLE order_item
    ADD COLUMN IF NOT EXISTS created_at_ext TIMESTAMPTZ;

ALTER TABLE order_item
    ADD COLUMN IF NOT EXISTS updated_at_ext TIMESTAMPTZ;

UPDATE order_item
SET created_at = NOW()
WHERE created_at IS NULL;

UPDATE order_item
SET updated_at = NOW()
WHERE updated_at IS NULL;

ALTER TABLE order_item
    ALTER COLUMN created_at SET DEFAULT NOW(),
    ALTER COLUMN created_at SET NOT NULL,
    ALTER COLUMN updated_at SET DEFAULT NOW(),
    ALTER COLUMN updated_at SET NOT NULL;
