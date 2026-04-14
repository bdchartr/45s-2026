-- 45s schema
-- Append-only: new tables and ALTER statements added below existing ones.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(255) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  google_sub VARCHAR(255) NULL UNIQUE,
  role ENUM('owner','admin','player') NOT NULL DEFAULT 'player',
  auth_provider VARCHAR(40) NOT NULL DEFAULT 'local',
  external_sub VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('owner','admin','player') NOT NULL DEFAULT 'player';
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_provider VARCHAR(40) NOT NULL DEFAULT 'local';
ALTER TABLE users ADD COLUMN IF NOT EXISTS external_sub VARCHAR(255) NULL;
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_auth_provider ON users(auth_provider);
CREATE INDEX IF NOT EXISTS idx_users_external_sub ON users(external_sub);

CREATE TABLE IF NOT EXISTS games (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status ENUM('lobby','active','finished','abandoned') NOT NULL DEFAULT 'lobby',
  target_score SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  ruleset VARCHAR(40) NOT NULL DEFAULT 'chartrand',
  dealer_seat TINYINT UNSIGNED NOT NULL DEFAULT 0,
  current_phase VARCHAR(40) NOT NULL DEFAULT 'lobby',
  current_turn_seat TINYINT UNSIGNED NOT NULL DEFAULT 0,
  hand_number INT UNSIGNED NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_games_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_players (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  is_ai TINYINT(1) NOT NULL DEFAULT 0,
  team TINYINT UNSIGNED NOT NULL,
  connected TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_game_seat UNIQUE (game_id, seat),
  CONSTRAINT fk_game_players_game FOREIGN KEY (game_id) REFERENCES games(id),
  CONSTRAINT fk_game_players_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  seq_no BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  actor_seat TINYINT UNSIGNED NULL,
  payload_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_game_seq UNIQUE (game_id, seq_no),
  CONSTRAINT fk_game_events_game FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Authoritative hand state (replaces deterministic reconstruction)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  hand_number INT UNSIGNED NOT NULL,
  dealer_seat TINYINT UNSIGNED NOT NULL,
  deck_seed BIGINT UNSIGNED NOT NULL,       -- random seed for this hand's shuffle
  kitty_json JSON NOT NULL,                  -- array of 3 card codes face-down in center
  bid_winner_seat TINYINT UNSIGNED NULL,
  bid_value SMALLINT UNSIGNED NULL,
  is_30_for_60 TINYINT(1) NOT NULL DEFAULT 0,
  trump_suit CHAR(1) NULL,                   -- C D H S, set after declare_trump
  phase VARCHAR(40) NOT NULL DEFAULT 'bidding',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_game_hand UNIQUE (game_id, hand_number),
  CONSTRAINT fk_hands_game FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-seat card assignments for a hand.
-- cards_json is the current live hand (mutated by discard/draw/play actions).
CREATE TABLE IF NOT EXISTS hand_cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hand_id BIGINT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  cards_json JSON NOT NULL,                  -- current cards in this seat's hand
  CONSTRAINT uq_hand_seat UNIQUE (hand_id, seat),
  CONSTRAINT fk_hand_cards_hand FOREIGN KEY (hand_id) REFERENCES hands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tricks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hand_id BIGINT UNSIGNED NOT NULL,
  trick_number TINYINT UNSIGNED NOT NULL,    -- 1-5
  lead_seat TINYINT UNSIGNED NOT NULL,
  winner_seat TINYINT UNSIGNED NULL,
  cards_json JSON NOT NULL,                  -- ordered plays: [{seat, card}, ...]
  best_trump_played CHAR(3) NULL,            -- highest trump code played, for bonus point
  CONSTRAINT uq_hand_trick UNIQUE (hand_id, trick_number),
  CONSTRAINT fk_tricks_hand FOREIGN KEY (hand_id) REFERENCES hands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  hand_id BIGINT UNSIGNED NOT NULL,
  team0_delta SMALLINT NOT NULL DEFAULT 0,
  team1_delta SMALLINT NOT NULL DEFAULT 0,
  team0_total SMALLINT NOT NULL DEFAULT 0,
  team1_total SMALLINT NOT NULL DEFAULT 0,
  team0_sets TINYINT UNSIGNED NOT NULL DEFAULT 0,
  team1_sets TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT uq_score_hand UNIQUE (hand_id),
  CONSTRAINT fk_scores_game FOREIGN KEY (game_id) REFERENCES games(id),
  CONSTRAINT fk_scores_hand FOREIGN KEY (hand_id) REFERENCES hands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
