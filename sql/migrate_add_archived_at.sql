-- Archive support: soft-delete games without destroying any data.
ALTER TABLE games ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_games_archived_at ON games(archived_at);
