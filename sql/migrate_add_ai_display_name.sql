-- Per-game display name for AI seats (and any future override for human seats).
-- For AI, this is assigned at game creation from a curated family-name pool so
-- every bot at the table has a distinct, recognizable name.
ALTER TABLE game_players ADD COLUMN IF NOT EXISTS display_name VARCHAR(60) NULL DEFAULT NULL;
