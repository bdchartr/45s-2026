# 45 Implementation Details

Last updated: 2026-04-20 (social auth update)

## 1. Goals and Constraints

- Build a production-ready PWA for the Chartrand/Newfoundland 45 ruleset.
- Support multiplayer, AI seats, authentication, and debug mode.
- Deploy on existing shared hosting (wkapp.com):
  - SSH deploy via SFTP to `wkapp.com` on port `2222` using `scripts/deploy.sh`
  - web root under `/home/chartb/public_html/45`
  - PHP and MySQL available
- Keep architecture simple enough for shared hosting limits.
- Architectural guardrails are formalized in `docs/adr/ADR-0001-modular-boundaries-and-dependencies.md`.

## 2. High-Level Architecture

- Current client: static HTML/CSS/JavaScript pages in `public/` (`lobby.html`, `game.html`, `admin.html`, `player.html`).
- Current server: Slim 4 on PHP with JSON API endpoints.
- Current database: MySQL/MariaDB (InnoDB).
- Current realtime strategy:
  - game board polls `get_state` every 2 seconds; lobby polls every 3 seconds
  - event-log-driven board rendering; friendly event log with raw JSON hover
- Current session/auth strategy:
  - PHP sessions + secure cookies for local auth
  - CSRF tokens fetched from `auth/me` and sent on protected POST requests

## 2.0 Current Implementation Status

### Fully live and working

**Auth & Accounts**
- Local register / login / logout with PHP session-backed auth
- **Google Sign-In** via Google Identity Services (GIS) — production live; stores user's Google display name as nickname on first sign-in; verifies `aud` against configured client ID
- Facebook and Apple social auth: backend endpoints fully implemented and deployed; buttons appear automatically once App ID / Service ID added to `server/config.php`; not yet activated (no credentials configured)
- Social auth provider config: `GET /api/auth/providers` returns which providers are enabled; frontend loads SDKs and shows buttons dynamically
- Nickname and avatar profile fields; player profile modal accessible from anywhere
- Owner / admin / player role system with protected routes
- Password change via `POST /api/player/update_password`

**Onboarding / Welcome**
- Unauthenticated visitors see a full-page welcome screen (dark felt green, Playfair Display title, game feature bullets)
- Sign In and New Player forms in a tabbed auth card; non-technical copy throughout ("Choose a username", "Join the Table")
- Social sign-in buttons (Google live; Facebook/Apple infrastructure ready) appear below the tabs when configured
- On sign-in, view switches to the lobby without page reload

**Lobby**
- Polls every 3 seconds (pauses when tab is hidden)
- Create game UI: visual seat picker with 4P/6P toggle; per-seat AI / Open / Invite options
- Invite picker: recently-played users shown first with star badge; full searchable list
- Game lists auto-categorise: open slot → "Open Tables"; all seats filled → "Active Games"
- "Waiting for players…" shown instead of Join button when already seated
- Joining a game navigates directly to the board
- Invited badge (purple) on games you've been specifically invited to
- Friendly status labels: "In Progress", "Waiting", "Finished"
- Admin panel with all-games table and Archive button

**Game Board**
- Heritage Pub Table aesthetic: dark walnut body, hunter green felt, parchment player zones
- Fixed viewer perspective: current user always at south
- Player zones: name, card backs, bid badges, dealer marker (✦ DEAL)
- Turn indicator pills hanging off the table-facing edge of each zone (animated gold pulse)
- Board action overlay (frosted-glass panel on felt) for bid / trump / discard actions:
  - Bidding: bid buttons with current high shown; dealer steal logic
  - Trump declaration: suit buttons
  - Discard: confirm button with live card-count hint
- Info bar: trump suit, contract (player name + bid value), team scores with player names, hand/trick progress
- Topbar chips: your name + seat, current phase (friendly)
- Status panel below board: current phase + whose turn in plain language
- Recent Events log: friendly sentences ("chartb bid 25") with raw JSON on hover
- Mini card chips (38×54 non-viewer, 46×66 viewer) with rank + suit symbol; playable cards lift on hover
- Card counts on non-viewer zones reflect actual cards remaining (derived from events)
- Fly animation when viewer plays a card (750ms arc from hand to center)
- Directional trick card animations (cards slide in from each player's direction)
- Trick collection animation (cards sweep to winner's stack)
- Kitty shown face-down during bidding; merged into bid winner's hand after trump declared
- Won-trick side stacks with counts
- Clickable player names open profile modal

**Game Engine**
- Full phase lifecycle: `bidding → declare_trump → discard_phase → trick_play → score_hand → game_over` (loops)
- Bidding order: left of dealer first, dealer last; dealer may match current high to steal
- All 45s card ranking, legal move validation, trick resolution (pure domain classes)
- Discard + replacement draw; bid winner gets kitty merged into hand
- Hand scoring: best-trump bonus (+5), set tracking, bid-out, three-sets loss, game-over
- Scores persisted per hand in `scores` table
- AI integration: `AlgorithmicMoveProvider` wired into `get_state` via `runAiTurns`; paced at 1 trick card per 2s poll

**6-Player Variant**
- Team layout: seats {0,2,4} vs {1,3,5}
- Per-player discard limit of 3
- After all 6 discard+draw, dealer gets remaining deck; must discard back to 5
- `player_count` in `games` distinguishes 4- vs 6-player; migration: `sql/migrate_add_6player.sql`

### Partial / not started
- Facebook and Apple social auth (backend + frontend done; needs credentials in `server/config.php`)
- PWA manifest / service worker / offline shell
- Incremental event polling (currently full-state refresh)
- Spectator mode

## 2.1 Module Boundaries

### Module A: Platform and Ecosystem Operations
- User accounts, auth, lobby, game discovery
- Admin tools, configuration, deployment, observability

### Module B: Match Runtime (Single Game Backend)
- Game state machine, phase transitions, turn order
- Legal action validation, trick/hand progression
- Event log and authoritative persistence
- AI turn orchestration (calls Module C through interface)

### Module C: Decision Engine
- `AlgorithmicMoveProvider`: bid by max-suit×5, trump by longest suit, discard weakest non-trump, play highest legal
- Future: `OpenAIMoveProvider`, `HybridMoveProvider` — same interface, no runtime changes

### Module D: Client Experience
- View models, interaction flows, board rendering from event log
- Does not own authoritative rules or trust decisions

**Dependency direction**: A→B, A→C, A→D · B→C (interface only) · D→A/B API contracts only

## 3. Tech Stack

### Frontend
- Static HTML + CSS + vanilla JavaScript (no build step)
- Fetch API for JSON calls to PHP backend
- Fonts: Playfair Display (headings), Lora (body), IBM Plex Mono (cards/data)
- All pages live exclusively in `public/`; root `.htaccess` rewrites `/45/game.html` → `public/game.html`

### Backend
- Slim 4 routing; Composer + PSR-4 autoloading
- Layered: Domain → Application → Infrastructure
- PDO for MySQL; `password_hash` / `password_verify` for local auth
- CSRF on all state-changing POST routes; rate limiting on auth endpoints

### Database
- MariaDB 10.6 (MySQL 8-compatible SQL), InnoDB, foreign keys

## 4. Current Folder Layout

```text
45s/
  app/
    Domain/
      Game/          — Card, Suit value objects
      Rules/         — CardRanker, LegalMoveValidator, TrickResolver
      AI/            — AIRequest, AIResponse, MoveProviderInterface
    Application/
      Commands/      — ActionCommand, ActionResult
      Services/      — GameRuntimeService (orchestrates phases + AI)
    Infrastructure/
      Persistence/   — GameRepository
      AI/            — AlgorithmicMoveProvider
      Auth/          — SessionAuth, RateLimiter
      Http/          — Routes.php (all Slim route handlers)
  public/
    index.php        — Slim bootstrap + all HTML pages
    lobby.html
    game.html
    admin.html
    player.html
    player-modal.js  — shared player profile modal
  server/
    config.php       — DB credentials (not committed)
    config.example.php
  sql/
    schema.sql
    migrate_add_hand_tables.sql
    migrate_add_6player.sql
    migrate_add_archived_at.sql
    migrate_add_avatar.sql
    run_migration.php
  scripts/
    deploy.sh        — SFTP deploy to wkapp.com:2222
    seed_test_users.sql
  tests/
    Application/     — GameRuntimeServiceTest
    Domain/Rules/    — CardRankerTest, LegalMoveValidatorTest, TrickResolverTest
    Infrastructure/  — AI/, Auth/, Http/, Persistence/ test suites
  docs/
    adr/
    configuration-and-operations.md
  .htaccess          — rewrites game.html/lobby.html/admin.html → public/
  game_rules.md
  implementation_details.md
```

## 5. Core Game Model

Game phases (loop per hand):
1. `bidding` — starts at dealer+1, dealer bids last; dealer may match to steal
2. `declare_trump` — bid winner only
3. `discard_phase` — all players simultaneously; bid winner receives kitty first
4. `trick_play` — 5 tricks
5. `score_hand` — automatic after trick 5; loops or ends game
6. `game_over` — when a team reaches target score

Kitty flow:
- 3 cards dealt into kitty at hand start; shown face-down in center during bidding/declare_trump
- After trump declared: `kitty_picked_up` emitted; kitty merged into bid winner's hand; only bid winner sees kitty cards

AI turn pacing:
- `runAiTurns` called on every `get_state` request
- Bidding / trump / discard: all pending AI actions run at once
- `trick_play`: 1 AI card per `get_state` call (~2s pace with polling interval)

## 6. Database Schema

### users
`id`, `username`, `email`, `password_hash`, `role` (owner/admin/player), `auth_provider`, `external_sub`, `google_sub`, `nickname`, `avatar_code`, `created_at`, `updated_at`

### games
`id`, `status` (lobby/active/finished/abandoned), `target_score`, `ruleset`, `player_count` (4 or 6), `dealer_seat`, `current_phase`, `current_turn_seat`, `hand_number`, `created_by_user_id`, `archived_at`, `created_at`, `updated_at`

### game_players
`id`, `game_id` FK, `seat`, `user_id` nullable, `is_ai` bool, `team` (0/1), `connected` bool, `created_at`

### hands
`id`, `game_id` FK, `hand_number`, `dealer_seat`, `bid_winner_seat`, `bid_value`, `is_30_for_60` bool, `trump_suit`, `deck_seed`, `kitty_json`, `deck_remaining_json`, `dealer_extra_draw_pending` bool

### hand_cards
`id`, `hand_id` FK, `seat`, `cards_json` (current cards; mutated by discard/draw/play)

### tricks
`id`, `hand_id` FK, `trick_number` (1–5), `lead_seat`, `winner_seat`, `cards_json`

### scores
`id`, `game_id` FK, `hand_id` FK, `team0_delta`, `team1_delta`, `team0_total`, `team1_total`, `team0_sets`, `team1_sets`

### game_events
`id`, `game_id` FK, `seq_no` (monotonic), `event_type`, `actor_seat`, `payload_json`, `created_at`

## 7. API Surface

### Auth
- `POST /45/api/auth/register`
- `POST /45/api/auth/login`
- `POST /45/api/auth/logout` *(CSRF)*
- `GET  /45/api/auth/me` — returns `user_id`, `username`, `display_name`, `nickname`, `avatar_code`, `role`, `csrf_token`
- `GET  /45/api/auth/csrf`
- `GET  /45/api/auth/providers` — returns enabled social providers and their public client IDs/app IDs
- `POST /45/api/auth/google/login` — body: `{id_token}`; verifies via Google tokeninfo, returns session
- `POST /45/api/auth/facebook/login` — body: `{access_token, user_id, first_name?, last_name?}`; verifies via Graph API
- `POST /45/api/auth/apple/login` — body: `{id_token, first_name?, last_name?}`; verifies JWT signature via Apple JWKS

### Lobby
- `POST /45/api/lobby/create_game` *(CSRF)* — `target_score`, `ruleset`, `player_count`, `user_id`, `invite_mode`, `ai_seats`, `invites[]`
- `POST /45/api/lobby/join_game` *(CSRF)*
- `POST /45/api/lobby/archive_game` *(CSRF)* — soft-delete (sets `archived_at`)
- `GET  /45/api/lobby/my_games?user_id=`
- `GET  /45/api/lobby/list_joinable?user_id=`
- `GET  /45/api/lobby/users` — all users except self, recently-played first (for invite picker)

### Gameplay
- `GET  /45/api/game/get_state?game_id=` — triggers `runAiTurns`; returns game, players, events, viewer_hand, hand, scores
- `POST /45/api/game/submit_bid` *(CSRF)*
- `POST /45/api/game/declare_trump` *(CSRF)*
- `POST /45/api/game/discard_cards` *(CSRF)*
- `POST /45/api/game/play_card` *(CSRF)*

### Player Profile
- `GET  /45/api/player/stats?user_id=` — info + stats (games, wins, partners, opponents, monthly)
- `POST /45/api/player/update_nickname` *(CSRF)*
- `POST /45/api/player/update_avatar` *(CSRF)*
- `POST /45/api/player/update_password` *(CSRF)*

### Admin / System *(owner or admin role)*
- `GET  /45/health`
- `GET  /45/api/system/db-health`
- `GET  /45/api/stats/summary`
- `GET  /45/api/admin/users`
- `GET  /45/api/admin/games`
- `POST /45/api/admin/users/create` *(CSRF)*
- `POST /45/api/admin/users/role` *(CSRF)*
- `POST /45/api/admin/delete_game` *(CSRF)* — hard-deletes game and all related rows

## 8. Rules Engine

Pure-function domain classes under `app/Domain/Rules/`:
- `CardRanker` — full 45s card strength by suit/trump context
- `LegalMoveValidator` — follow-suit, trump exemption, top-trump no-force-out rule
- `TrickResolver` — determines trick winner

All are deterministic and fully unit-tested. Random deck seed stored in `hands.deck_seed`.

## 8.1 AI Architecture

Interface: `MoveProviderInterface::choose(AIRequest): AIResponse`

`AlgorithmicMoveProvider`:
- **bidding**: `maxSuitCount × 5` if legal and > current high; else pass
- **declare_trump**: longest suit
- **discard**: score by strength; keep top 5 (preserve trump); discard rest
- **trick_play**: enumerate legal cards; play highest by `CardRanker::strength`; random tiebreak

## 9. Security

- Secrets in `server/config.php` only (not committed).
- All state-changing routes require `X-CSRF-Token` header.
- Seat ownership and turn checks enforced server-side for all gameplay endpoints.
- Non-admin users may only read state for games they are seated in.

## 10. Deployment

Use `./scripts/deploy.sh` from the project root. It uploads all files via SFTP to `wkapp.com:2222` (excludes `.git/`, `vendor/`, `config.php`, `*.ps1`).

See `docs/configuration-and-operations.md` for full deploy procedure, migration steps, and post-deploy smoke checks.

## 11. Rate Limit Policy

Auth endpoints return HTTP 429 with `retry_after` (seconds) on exhaustion:
- register: 10 attempts / 15 min / IP
- login: 8 attempts / 15 min / username+IP
- google login: 15 attempts / 15 min / IP

## 12. Open Decisions / Future Work

- Google OAuth (endpoint exists; not production-tested)
- PWA manifest, service worker, offline shell
- Incremental event polling (currently full-state refresh every 2s)
- Spectator mode (watch without a seat)
- LLM-backed AI provider behind existing `MoveProviderInterface`
- WebSockets if hosting ever changes
