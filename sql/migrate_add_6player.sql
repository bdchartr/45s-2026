-- Migration: add 6-player support columns
-- Safe to re-run (uses ADD COLUMN IF NOT EXISTS).

-- Track how many seats are in the game (4 or 6).
ALTER TABLE games
    ADD COLUMN IF NOT EXISTS player_count TINYINT UNSIGNED NOT NULL DEFAULT 4;

-- Remaining deck cards after the initial deal.
-- Used to draw replacement cards during discard phase and to supply the dealer
-- with leftover cards in the 6-player extra-draw step.
ALTER TABLE hands
    ADD COLUMN IF NOT EXISTS deck_remaining_json JSON NULL;

-- Flag set after all players have completed their initial discards in a
-- 6-player game, indicating the dealer must now take the remaining deck cards
-- and discard back to 5 before trick play begins.
ALTER TABLE hands
    ADD COLUMN IF NOT EXISTS dealer_extra_draw_pending TINYINT(1) NOT NULL DEFAULT 0;
