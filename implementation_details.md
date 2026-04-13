# Forty-Fives (45s) Implementation Details

Last updated: 2026-04-13

## 1. Goals and Constraints

- Build a production-ready PWA for the Chartrand/Newfoundland 45s ruleset.
- Support multiplayer, AI seats, authentication, and debug mode.
- Deploy on existing shared hosting (same style as approver_bird):
  - SSH deploy via `scp` to `wkapp.com` on port `2222`
  - web root under `/home/chartb/public_html/...`
  - PHP available
  - MySQL available
- Keep architecture simple enough for shared hosting limits.
- Architectural guardrails are formalized in `docs/adr/ADR-0001-modular-boundaries-and-dependencies.md`.

## 2. High-Level Architecture

- Current client: static HTML/CSS/JavaScript pages (`lobby.html`, `game.html`, `admin.html`).
- Current server: Slim 4 on PHP with JSON API endpoints.
- Current database: MySQL (InnoDB).
- Current realtime strategy:
  - full state refresh from browser pages
  - event-log-driven board rendering
- Current session/auth strategy:
  - PHP sessions + secure cookies for local auth
  - CSRF tokens fetched from `auth/me` and sent on protected POST requests
  - Google login endpoint exists, but local auth is the main verified path

## 2.0 Current Implementation Status

What is live now:
- local register/login/logout and session-backed auth state
- owner/admin-protected stats and admin APIs
- lobby UI with session-aware signed-in/signed-out states
- create game with AI seats
- open-invite and seat-specific invite modes
- multi-game support via `my_games` and `list_joinable`
- game board with fixed viewer perspective (south = current user)
- dealer marker, turn marker, center trick area, and won-trick side stacks
- explicit lifecycle events for:
  - `bidding_closed`
  - `kitty_picked_up`
  - `discard_completed`
  - `restock_completed`
  - `trick_won`
- `get_state` currently returns `viewer_seat` and `viewer_hand`

What is still partial or inferred:
- `viewer_hand` is currently reconstructed deterministically from `game_id` plus `card_played` history rather than from persisted hand records
- the board still uses manual card-code entry for play actions
- the hand flow tracker is partially event-driven and partially phase-derived

What is not fully authoritative yet:
- trump declaration
- true discard/draw actions backed by persisted card state
- full 45s move legality based on authoritative hand contents
- hand scoring, sets, bid-out, and game-over resolution

## 2.1 Module Boundaries (Refined)

Your four-module model is correct and should be the core architecture. The improvement is to define strict responsibilities and one-way dependencies.

### Module A: Platform and Ecosystem Operations

Purpose:
- Keep the whole product healthy over time.

Owns:
- user accounts and auth integration
- lobby lifecycle and game discovery
- configuration, feature flags, deployment, observability
- admin tools, moderation, versioning, analytics

Does not own:
- per-hand game rule decisions
- card-level legality decisions

### Module B: Match Runtime (Single Game Backend)

Purpose:
- Run one game instance safely and deterministically.

Owns:
- game state machine and phase transitions
- turn order, legal action validation, trick/hand progression
- event log and persistence of authoritative state

Does not own:
- AI strategy internals
- UI concerns

### Module C: Decision Engine (AI and Algorithms)

Purpose:
- Choose bids, trump, discards, and card plays for AI seats.

Owns:
- algorithmic heuristics (V1)
- optional LLM-backed policy providers (future)
- explanation output for debug mode

Does not own:
- final legality checks (runtime must re-validate)
- direct database writes to game state

### Module D: Client Experience (Frontend PWA)

Purpose:
- Render state, collect user intent, and provide responsive UX.

Owns:
- view models, interaction flows, offline shell behavior
- reconnect UX and optimistic UI only where safe
- accessibility and installability

Does not own:
- authoritative rules
- trust decisions for legal moves

### Dependency Direction (Must Stay One-Way)

- Module A can orchestrate B and C and expose contracts to D.
- Module B can call C through interfaces.
- Module C knows nothing about A or D internals.
- Module D depends only on public API contracts from A/B.

Recommended dependency graph:
- A -> B
- A -> C
- A -> D
- B -> C (interface only)
- D -> A/B API contracts

Forbidden dependencies:
- C -> B direct state mutation
- D -> C direct calls
- B -> D

### API Contracts Between Modules

Minimum stable contracts:
- ActionCommand: what a player/AI attempts to do
- ActionResult: accepted/rejected plus reason codes
- GameStateView: sanitized state payload for clients
- AIRequest and AIResponse: strategy input/output contract

All inter-module communication should use explicit DTOs so refactors do not leak internals.

### Why This Improves Maintainability

- You can replace AI providers without touching game runtime logic.
- You can change frontend framework details without changing rules code.
- You can evolve auth/deployment/admin independently of gameplay behavior.
- Tests can be targeted by module boundary (unit tests in C, simulation tests in B, contract tests between B and D).

## 3. Tech Stack (Concrete)

### Frontend (Current)

- Static HTML + CSS + vanilla JavaScript.
- Fetch API for JSON calls to PHP backend.
- Root and `public/` copies of the main pages are mirrored for deployment compatibility.

### Frontend (Planned / Deferred)

- Vue 3 + Pinia + Vite remain a possible later migration path.
- Service worker, installability, and richer offline behavior remain planned rather than current production behavior.

### Backend

- Slim 4 for routing and middleware (lightweight, shared-host friendly).
- Composer + PSR-4 autoloading for maintainability.
- Clear layered structure: Domain, Application, Infrastructure.
- PDO for MySQL access.
- Password hashing: `password_hash` / `password_verify`.
- CSRF protection for state-changing requests.
- Rate limiting table for login and account endpoints.

### Database

- MySQL 8.x preferred (works with MySQL 5.7-compatible SQL if needed).
- InnoDB tables, foreign keys, indexed turn/game lookups.

## 4. Proposed Folder Layout

```text
45s/
  app/
    Domain/
      Game/
      Rules/
      AI/
    Application/
      Commands/
      Services/
    Infrastructure/
      Persistence/
      AI/
      Auth/
  public/
    index.php
    app/
      index.html
      manifest.json
      sw.js
      assets/
  api/
    bootstrap.php
    auth/
      register.php
      login.php
      logout.php
      google_callback.php
      me.php
    lobby/
      create_game.php
      join_game.php
      list_open_games.php
    game/
      get_state.php
      submit_bid.php
      declare_trump.php
      discard_draw.php
      play_card.php
      poll_events.php
    debug/
      reveal_hands.php
      ai_reasoning.php
  server/
    config.example.php
    config.php   (not committed)
    db.php
    rules_engine.php
    ai_engine.php
  frontend/
    src/
    index.html
    package.json
    vite.config.js
  scripts/
    deploy.ps1
  sql/
    schema.sql
    seed_dev.sql
  docs/
    implementation_details.md
```

## 5. Core Game Model

- Authoritative state lives server-side only.
- Every action is validated against current phase and seat turn.
- Event-sourced game log records each action in order.

Current practical note:
- The event log is the main source of truth for current board rendering.
- Some gameplay state is still inferred from events rather than stored explicitly.

Game phases:
1. `lobby`
2. `deal`
3. `bidding`
4. `declare_trump`
5. `discard_draw`
6. `trick_play` (5 tricks)
7. `score_hand`
8. `game_over`

Current live phase usage is narrower than the target model. The phases actively exercised today are primarily:
- `bidding`
- `trick_play`

## 6. Database Schema (Initial)

Current live schema is simpler than the original target model.

Tables currently in active production use:
- `users`
- `games`
- `game_players`
- `game_events`

Target tables such as `hands`, `tricks`, and `scores` are still design targets and not the current live implementation.

### users
- `id` PK
- `username` unique
- `email` unique nullable
- `password_hash` nullable (for OAuth-only accounts)
- `google_sub` unique nullable
- `created_at`, `updated_at`

### games
- `id` PK
- `status` (`lobby`, `active`, `finished`, `abandoned`)
- `target_score` (45 or 120)
- `ruleset` (`chartrand` default)
- `dealer_seat` (0-3)
- `current_phase`
- `current_turn_seat`
- `hand_number`
- `created_by_user_id`
- `created_at`, `updated_at`

### game_players
- `id` PK
- `game_id` FK
- `seat` (0-3)
- `user_id` nullable (AI if null and `is_ai=1`)
- `is_ai` bool
- `team` (0/1)
- `connected` bool

### hands
- `id` PK
- `game_id` FK
- `hand_number`
- `dealer_seat`
- `bid_winner_seat` nullable
- `bid_value` nullable
- `is_30_for_60` bool
- `trump_suit` nullable
- `state_json` (deck order seed, discard/draw metadata)

### tricks
- `id` PK
- `hand_id` FK
- `trick_number` (1-5)
- `lead_seat`
- `winner_seat` nullable
- `cards_json` (ordered plays)

### scores
- `id` PK
- `game_id` FK
- `hand_id` FK
- `team0_delta`
- `team1_delta`
- `team0_total`
- `team1_total`
- `team0_sets`
- `team1_sets`

### game_events
- `id` PK
- `game_id` FK
- `seq_no` monotonic
- `event_type`
- `actor_seat` nullable
- `payload_json`
- `created_at`

## 7. API Surface (V1)

Current production implementation uses Slim routes under `/45s/api/...` rather than separate endpoint files.

Current live auth routes:
- `POST /45s/api/auth/register`
- `POST /45s/api/auth/login`
- `POST /45s/api/auth/google/login`
- `POST /45s/api/auth/logout`
- `GET /45s/api/auth/me`
- `GET /45s/api/auth/csrf`

Current live lobby routes:
- `POST /45s/api/lobby/create_game`
- `POST /45s/api/lobby/join_game`
- `GET /45s/api/lobby/my_games?user_id=...`
- `GET /45s/api/lobby/list_joinable?user_id=...`

Current live gameplay routes:
- `GET /45s/api/game/get_state?game_id=...`
- `GET /45s/api/game/poll_events?game_id=...&after_seq=...`
- `POST /45s/api/game/submit_bid`
- `POST /45s/api/game/play_card`

Current live admin/system routes:
- `GET /45s/health`
- `GET /45s/api/system/db-health`
- `GET /45s/api/stats/summary`
- `GET /45s/api/admin/users`
- `GET /45s/api/admin/games`
- `POST /45s/api/admin/users/create`
- `POST /45s/api/admin/users/role`

Auth:
- `POST /api/auth/register.php`
- `POST /api/auth/login.php`
- `POST /api/auth/logout.php`
- `GET /api/auth/me.php`
- `GET /api/auth/google_callback.php`

Lobby:
- `POST /api/lobby/create_game.php`
- `POST /api/lobby/join_game.php`
- `GET /api/lobby/list_open_games.php`

Gameplay:
- `GET /api/game/get_state.php?game_id=...`
- `POST /api/game/submit_bid.php`
- `POST /api/game/declare_trump.php`
- `POST /api/game/discard_draw.php`
- `POST /api/game/play_card.php`
- `GET /api/game/poll_events.php?game_id=...&after_seq=...`

Debug:
- `GET /api/debug/reveal_hands.php?game_id=...` (restricted)
- `GET /api/debug/ai_reasoning.php?game_id=...&seat=...` (restricted)

## 8. Rules Engine Notes

- Implement pure functions for:
  - card ranking by suit/trump context
  - legal move validation
  - trick winner resolution
  - bid/set/score updates
- Keep engine deterministic for replay/testing.
- Store random seed per hand for reproducible simulation and debugging.

## 8.1 AI Architecture (Extensible)

- Use a provider interface so gameplay code is independent of AI implementation.
- Recommended interfaces:
  - `MoveProviderInterface`: choose bid, trump, discard, and card play.
  - `ExplainProviderInterface`: optional reasoning text for debug mode.
- Initial provider:
  - `AlgorithmicMoveProvider` (rules + heuristics only, no external calls).
- Future providers:
  - `OpenAIMoveProvider` (or other LLM provider) behind same interface.
  - `HybridMoveProvider` (heuristics first, LLM fallback for edge cases).
- Provider selection per seat should be config-driven in DB or config file.
- Add strict safeguards for LLM mode:
  - server-side legal move validator remains authoritative
  - timeout and fallback to algorithmic provider
  - prompt/response logging with redaction

## 8.2 Maintainability Guidelines

- Keep rule logic in pure, unit-testable classes under Domain.
- Keep HTTP, database, and external API logic in Infrastructure only.
- Keep use-case orchestration in Application services.
- Avoid embedding game rules directly in controller/route handlers.
- Version API payloads for multiplayer compatibility.

## 9. PWA Requirements

Manifest:
- `name`, `short_name`, `start_url`, `display: standalone`, icons, theme colors.

Service worker caches:
- App shell: `index.html`, CSS/JS, icons.
- Runtime cache for read-only API GETs with short TTL.
- Never cache auth/session-changing POST responses.

Offline behavior:
- Show offline banner.
- Allow viewing last known game state only.
- Queue is optional in V1; do not allow card plays while disconnected.

## 10. Security and Ops

- Store secrets in `server/config.php` only (not in git).
- Use environment-specific DB credentials.
- Validate all input server-side.
- Enforce seat ownership and turn checks for gameplay endpoints.
- Log auth and gameplay errors to server logs without exposing internals to clients.

## 11. Deployment Plan (Shared Host)

Based on current working pattern in approver_bird:

- Use `scripts/deploy.ps1` to upload files with `scp`.
- Remote target pattern:
  - host: `wkapp.com`
  - user: `chartb`
  - port: `2222`
  - destination: `/home/chartb/public_html/45s`
- Create remote directories before upload (`mkdir -p`).
- Exclude local-only files (`config.php`, `.env`, docs drafts, test assets).

Suggested post-deploy checks:
1. `GET /45s/` loads and registers service worker.
2. `GET /45s/api/auth/me.php` returns unauthenticated JSON cleanly.
3. Create game, join second seat, execute one full hand.
4. Verify database writes in `games`, `hands`, `game_events`, `scores`.

Current practical post-deploy checks:
1. `GET /45s/` redirects to `lobby.html` and returns 200 in browser flow.
2. `GET /45s/api/auth/me` returns clean JSON with `csrf_token`.
3. Session login on lobby hides sign-in/register and shows current user info.
4. `GET /45s/api/game/get_state?game_id=...` returns 200 for a seated authenticated user.
5. `viewer_hand` is non-empty for a newly created active game.
6. Board loads without `refresh_failed: 500` and gameplay POST routes return business errors instead of transport errors.

## 12. Milestone Plan

1. Foundation
- Project structure, DB schema, config loading, auth skeleton.

2. Core Rules and Single-Hand Engine
- Server-side validation for bidding, trump, discard/draw, trick resolution.

3. Multiplayer Loop
- Long-poll event feed, reconnect handling, lobby and seat management.

4. PWA and Installability
- Manifest, service worker, offline shell, mobile polish.

5. AI Seats and Debug Tools
- Rule-valid AI decisions, debug hand reveal, reasoning inspector.

6. Hardening
- Security review, error handling, deployment automation, smoke tests.

## 13. Open Decisions

- Whether to implement Google OAuth in V1 or V2.
- Whether to support WebSockets later if hosting changes.
- Whether to allow spectators in live games.
- Exact penalty behavior for revoke/misplay in production ruleset.

## 14. Next Steps

Highest priority backend work:
- replace inferred `viewer_hand` with authoritative persisted hand/deck/kitty state
- implement explicit trump declaration and storage
- implement discard and replacement draw as true actions with authoritative card state updates
- add hand scoring, set tracking, bid-out, and game-over processing
- add stronger `get_state` and event-payload regression tests

Highest priority frontend work:
- replace manual card-code entry with clickable cards from the rendered hand
- show current contract, trump suit, trick number, and team trick totals directly on the board
- replace generic player id labels with usernames when available
- improve polling to use incremental event refresh where possible

Operational next steps:
- normalize or clean malformed legacy event rows if any remain in production
- document the current deployment flow and session behavior in one short operator runbook
- add a narrow smoke script for `login -> open board -> get_state -> submit action`
