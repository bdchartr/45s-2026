-- Player avatar selection (short code referencing a predefined icon set).
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_code VARCHAR(20) NULL DEFAULT NULL;
-- Display nickname (not unique; defaults to username; shown on boards and lobby).
ALTER TABLE users ADD COLUMN IF NOT EXISTS nickname VARCHAR(60) NULL DEFAULT NULL;
