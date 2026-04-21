# 45 Configuration and Operations

Last updated: 2026-04-20 (social auth)

## Application URLs

- Root / Lobby: https://wkapp.com/45/lobby.html
- Game board: https://wkapp.com/45/game.html?game_id={id}
- Player profile: https://wkapp.com/45/player.html?user_id={id}
- Admin page: https://wkapp.com/45/admin.html
- Health: https://wkapp.com/45/health
- DB Health: https://wkapp.com/45/api/system/db-health
- Stats Summary (owner/admin): https://wkapp.com/45/api/stats/summary
- Admin users list: https://wkapp.com/45/api/admin/users
- Admin games list: https://wkapp.com/45/api/admin/games

Auth endpoints:
- `POST /45/api/auth/register`
- `POST /45/api/auth/login`
- `POST /45/api/auth/logout`
- `GET  /45/api/auth/me`
- `GET  /45/api/auth/csrf`
- `GET  /45/api/auth/providers` — returns enabled social providers and public client IDs
- `POST /45/api/auth/google/login` — body `{id_token}`
- `POST /45/api/auth/facebook/login` — body `{access_token, user_id, first_name?, last_name?}`
- `POST /45/api/auth/apple/login` — body `{id_token, first_name?, last_name?}`

Player profile endpoints:
- `GET /45/api/player/stats?user_id={id}` — returns user info (id, username, nickname, display_name, avatar_code, gravatar_url, role, member_since) and stats (games_played, wins, win_pct, partners, opponents, monthly). `user_id` defaults to the authenticated user.
- `POST /45/api/player/update_nickname` — CSRF required; sets `nickname` (max 60 chars, empty clears it)
- `POST /45/api/player/update_avatar` — CSRF required; sets `avatar_code` (max 20 chars, non-empty)
- `POST /45/api/player/update_password` — CSRF required; body: `current_password`, `new_password` (min 8 chars)

## Deployment

### Deploy command (from project root on dev machine)

```bash
./scripts/deploy.sh
```

This uploads all files via SFTP to `/home/chartb/public_html/45` on `wkapp.com:2222`, excluding `server/config.php`, `vendor/`, and `.git/`.

The `~/45/` directory on the server is symlinked to `public_html/45`.

### SSH access

```bash
ssh -p 2222 chartb@wkapp.com
```

### Pre-deploy checklist

1. Run tests: `php vendor/bin/phpunit` — all tests must pass
2. If schema changed: run the relevant migration on the server **before** deploying code (see below)

### Post-deploy checks

1. `GET /45/` redirects to `lobby.html` and returns 200
2. `GET /45/api/auth/me` returns clean JSON with `csrf_token`
3. Session login on lobby hides sign-in form and shows current user info
4. `GET /45/api/game/get_state?game_id=...` returns 200 for a seated authenticated user
5. Board loads without `refresh_failed: 500` errors

## Server Paths

- App root (server): `/home/chartb/45` (symlinked to `public_html/45`)
- Runtime config: `/home/chartb/45/server/config.php`
- Schema file: `/home/chartb/45/sql/schema.sql`

## Database Configuration

- Host: 127.0.0.1
- Port: 3306
- Database: `chartb_45s_gamestate`
- Username: `chartb_45s_gamesvc`
- Password: Q4mN8tR2pL6vK9sD5xJ3wH7c
- DSN: `mysql:host=127.0.0.1;port=3306;dbname=chartb_45s_gamestate;charset=utf8mb4`

cPanel prefixes all DB objects with the account prefix (`chartb_`).

## Current Schema Tables

All tables are active:
- `users`
- `games`
- `game_players`
- `game_events`
- `hands`
- `hand_cards`
- `tricks`
- `scores`

Foreign keys reference `games(id)` and `hands(id)`.

Games are never hard-deleted. `games.archived_at` is set to a timestamp when a game is archived; all list queries filter `WHERE archived_at IS NULL`. To apply the `archived_at` column to an existing database run:

```sql
ALTER TABLE games ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_games_archived_at ON games(archived_at);
```

The migration file is `sql/migrate_add_archived_at.sql`.

The `users` table has two optional profile columns added after initial launch. To apply them to an existing database run:

```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_code VARCHAR(20) NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS nickname VARCHAR(60) NULL DEFAULT NULL;
```

The migration file is `sql/migrate_add_avatar.sql`.

- `avatar_code` — one of 20 card-suit/colour codes (e.g. `s-teal`, `h-rose`). When null, the frontend falls back to the user's Gravatar (MD5 of email, `d=identicon`).
- `nickname` — optional free-text display name (max 60 chars, not unique). Shown everywhere instead of username. `COALESCE(nickname, username)` is the canonical display-name expression used in all queries.

## Social Auth Configuration

Social sign-in providers are configured in `server/config.php` under the `auth` key. Each provider is disabled when its value is an empty string. The `GET /api/auth/providers` endpoint reflects the current config; the frontend loads each SDK and shows the button only for configured providers.

```php
'auth' => [
    'google_client_id'  => '',   // Google OAuth 2.0 Web Client ID
    'facebook_app_id'   => '',   // Meta for Developers App ID
    'apple_service_id'  => '',   // Apple Services ID (com.example.app)
],
```

### Google Sign-In (live ✓)

**GCP project**: `fourty-five` (note spelling)
**Client ID**: stored in `server/config.php` on the production server — see memory for the value. Not committed to git (client IDs are public, but keeping it server-side avoids config drift).

Setup steps (already done):
1. Google Cloud Console → APIs & Services → OAuth consent screen → External → Publish
2. Credentials → Create OAuth 2.0 Client ID → Web application
3. Authorized JS origins: `https://wkapp.com`
4. No redirect URIs needed (popup flow)

How it works:
- Frontend loads Google Identity Services (`accounts.google.com/gsi/client`)
- Google renders a sign-in button; on click issues an `id_token` (JWT)
- Backend verifies `id_token` via `https://oauth2.googleapis.com/tokeninfo`, checks `aud` matches client ID
- First sign-in: auto-creates a user; Google display name stored as `nickname`
- Subsequent sign-ins: finds existing user by `auth_provider='google'` + `external_sub`

### Facebook Login (infrastructure ready, not activated)

1. Meta for Developers → create Web app → note App ID
2. App Domains: `wkapp.com`; Site URL: `https://wkapp.com/45/`
3. Add App ID to `server/config.php` → `auth.facebook_app_id`

Verification: backend calls `https://graph.facebook.com/me?fields=id,name,...` with the access token and confirms the returned user ID matches the client's claim.

### Apple Sign In (infrastructure ready, not activated)

Requires Apple Developer Program ($99/yr). Steps:
1. Apple Developer → Identifiers → Services IDs → create new (type: Services ID)
2. Enable "Sign In with Apple"; configure domain `wkapp.com`; return URL `https://wkapp.com/45/lobby.html`
3. Apple will ask you to verify domain ownership via a file at `/.well-known/apple-developer-domain-association.txt`
4. Add the Services ID to `server/config.php` → `auth.apple_service_id`

Verification: backend fetches Apple's public JWKS (`https://appleid.apple.com/auth/keys`), reconstructs the RSA public key, and verifies the RS256 signature on the `id_token`. No private key required for verification.

**Important**: Apple only sends the user's name on the *first* authorization. The backend stores it as `nickname` at account creation. On all subsequent sign-ins, only the `sub` is available.

## User Role and Identity Model

Roles: `owner`, `admin`, `player`

Owner/Admin permissions:
- create users, update user roles
- list all users, list all games
- read full stats summary
- archive any game (`POST /api/admin/delete_game` or `POST /api/lobby/archive_game`)

Player permissions:
- start and join games
- archive games they created (`POST /api/lobby/archive_game`)
- read game state only for games they are seated in

Identity paths:
- local auth: username + password_hash
- SSO: auth_provider in {google, sso} + external_sub

## Seed Test Users

```bash
mysql -u chartb_45s_gamesvc -p chartb_45s_gamestate < scripts/seed_test_users.sql
```

Seeded users:
- `test_north`, `test_east`, `test_south`, `test_west`

Seeded admin:
- username: `chartb`
- role: `admin`
- auth_provider: local
- password: `16_Bubles`

Recommended role assignment for testing:
- test_north: owner
- test_east: admin
- test_south: player
- test_west: player

## Running Tests

```bash
php vendor/bin/phpunit
```

All 130 tests should pass. Test suites:
- `tests/Application/GameRuntimeServiceTest.php` — phase lifecycle, bidding order, dealer steal, bid validation, kitty flow
- `tests/Domain/Rules/` — CardRanker, LegalMoveValidator, TrickResolver
- `tests/Infrastructure/AI/AlgorithmicMoveProviderTest.php` — all four AI action types; dealer steal bidding
- `tests/Infrastructure/Auth/SessionAuthTest.php`
- `tests/Infrastructure/Http/RoutesIntegrationTest.php` — CSRF guards, rate limiting, archive routes, player profile endpoints (stats, nickname, avatar, password)
- `tests/Infrastructure/Persistence/GameRepositoryArchiveTest.php` — archiveGame, archiveGameOwnedBy, list filters
- `tests/Infrastructure/Persistence/GameRepositoryBiddingTest.php` — bidSummary: empty state, highest bidder, dealer steal (last equal bid wins), cross-hand isolation, all-pass
- `tests/Infrastructure/Persistence/GameRepositoryPlayerTest.php` — findUserById, updateAvatar, updateNickname, updatePassword, statsForUser (zero-game baseline, win counting, partner/opponent tables, monthly history, archived-game exclusion)
- `tests/Infrastructure/Security/RateLimiterTest.php`

## Game Creation Notes

The lobby `ai_seats` field should list seats 1–3 (seat 0 is always the creator/human). Default is `1,2,3`.

In non-open invite mode, any seat with no user and no invite is automatically marked AI — this prevents ghost slots that would stall the game waiting for a player who will never join.

## AI Behaviour

AI turns are triggered synchronously on every `GET /api/game/get_state` call:
- `runAiTurns` checks the current phase and current turn seat
- If it's an AI seat's turn, the `AlgorithmicMoveProvider` chooses an action
- During `trick_play`, only 1 AI card is played per `get_state` call (paced to match the 2-second browser poll interval)
- During bidding, trump declaration, and discard, all pending AI actions run in a single call

## Bidding Rules

Bidding order starts left of the dealer and proceeds clockwise, ending with the dealer.

- Legal bid values: **15, 20, 25, 30, 60** (60 = "30-for-60" — bid team scores 60 on success).
- Non-dealer players must bid **strictly higher** than the current highest bid, or pass.
- The **dealer may match** the current highest bid to steal it ("dealer rob") — they do not need to exceed it.
- If all players (including the dealer) pass, the dealer is **forced to bid 15**.
- The backend enforces these rules; bids that violate them return `rejected: bid_too_low`.

The AI also respects the dealer steal: when the AI is the dealer and its computed bid equals the current high, it will steal rather than pass.

## Kitty Flow

1. Hand dealt → 3 kitty cards stored in `hands.kitty_json`
2. `hand_dealt` event emitted → frontend shows 3 face-down cards in center
3. Bidding completes → `bidding_closed` event; kitty stays face-down in center
4. Bid winner declares trump → `trump_declared` event; then `kitty_picked_up` event emitted; kitty merged into bid winner's `hand_cards`
5. Frontend removes kitty from center after `trump_declared`; kitty cards appear face-up in bid winner's hand only (other players never see them)

## Operational Notes

- DB-backed endpoints require `server/config.php` with `db.username` and `db.password` keys.
- All state-changing routes require CSRF token in `X-CSRF-Token` header; obtain from `GET /api/auth/me`.
- Admin and stats APIs require authenticated session with owner/admin role.
- Root browser traffic redirects to `/45/lobby.html`.
- Static frontend HTML lives exclusively in `public/` (`game.html`, `lobby.html`, `admin.html`, `player.html`). The root `.htaccess` rewrites bare URLs like `/45/game.html` → `public/game.html` transparently.
- Board polls `get_state` every 2 seconds.

## CSRF-Protected Routes

All state-changing POST routes require `X-CSRF-Token`:
- `POST /api/auth/logout`
- `POST /api/admin/users/create`
- `POST /api/admin/users/role`
- `POST /api/admin/delete_game`
- `POST /api/lobby/archive_game`
- `POST /api/lobby/create_game`
- `POST /api/lobby/join_game`
- `POST /api/game/submit_bid`
- `POST /api/game/declare_trump`
- `POST /api/game/discard_cards`
- `POST /api/game/play_card`
- `POST /api/player/update_nickname`
- `POST /api/player/update_avatar`
- `POST /api/player/update_password`

## Rate Limit Policy

Auth endpoints enforce request limits and return HTTP 429 on exhaustion. Response includes `retry_after` (seconds).

- register: 10 attempts / 15 minutes / IP
- login: 8 attempts / 15 minutes / username+IP
- google login: 15 attempts / 15 minutes / IP

## Known Active Gaps

- Some older game rows may contain event payloads that do not fully match newer board expectations.
- `get_state` is hardened against malformed event payloads, but old historical data may still limit board inference.
- Session continuity depends on the browser carrying the PHP session cookie across lobby and game page requests.
