# 45s Configuration and Operations

Last updated: 2026-04-13

## Application URLs
- Root: https://wkapp.com/45s/
- Lobby page: https://wkapp.com/45s/lobby.html
- Game board page: https://wkapp.com/45s/game.html?game_id={id}
- Health: https://wkapp.com/45s/health
- DB Health: https://wkapp.com/45s/api/system/db-health
- Admin page: https://wkapp.com/45s/admin.html
- Stats Summary (owner/admin): https://wkapp.com/45s/api/stats/summary
- Admin users list: https://wkapp.com/45s/api/admin/users
- Admin games list: https://wkapp.com/45s/api/admin/games
- Lobby my games: https://wkapp.com/45s/api/lobby/my_games?user_id=1
- Lobby joinable games: https://wkapp.com/45s/api/lobby/list_joinable?user_id=1

Auth endpoints:
- POST /45s/api/auth/register
- POST /45s/api/auth/login
- POST /45s/api/auth/google/login
- POST /45s/api/auth/logout
- GET /45s/api/auth/me
- GET /45s/api/auth/csrf

## Server Paths
- App root: /home/chartb/public_html/45s
- Runtime config: /home/chartb/public_html/45s/server/config.php
- Schema file: /home/chartb/public_html/45s/sql/schema.sql

## Database Configuration
- Host: 127.0.0.1
- Port: 3306
- Database: chartb_45s_gamestate
- Username: chartb_45s_gamesvc
- Password: Q4mN8tR2pL6vK9sD5xJ3wH7c
- DSN: mysql:host=127.0.0.1;port=3306;dbname=chartb_45s_gamestate;charset=utf8mb4

## cPanel/MySQL Naming Notes
- cPanel prefixes DB objects with account prefix.
- Use descriptive names for maintainability:
  - DB: chartb_45s_gamestate
  - User: chartb_45s_gamesvc

## Current Schema Tables
- users
- games
- game_players
- game_events

## User Role and Identity Model
- Roles: owner, admin, player
- Owner/Admin permissions (current):
  - create users
  - update user roles
  - list all users
  - list all games
  - read full stats summary
- Player permissions (current):
  - can start and join games
  - can only read game state for games they participate in when viewer_user_id is provided
- Identity paths:
  - local auth: username + password_hash
  - sso auth: auth_provider in {google,sso} + external_sub

## Seed Test Users
The following users are seeded for local testing of full-game flows:
- test_north
- test_east
- test_south
- test_west

Additional seeded admin user:
- username: chartb
- role: admin
- auth_provider: local
- password: 16_Bubles

Recommended role assignment for testing:
- test_north: owner
- test_east: admin
- test_south: player
- test_west: player

## Operational Notes
- DB-backed endpoints require server/config.php with db.username and db.password keys.
- API validation now rejects unknown user_id values in create_game/join_game with HTTP 400.
- Stats endpoint aggregates data from users, games, game_players, and game_events.
- Admin and stats APIs now require authenticated session user with owner/admin role (acting_user_id removed).
- Root browser traffic redirects to /45s/lobby.html.
- Static frontend is currently served from root-level HTML files mirrored into public/ for deployment.
- Main frontend entry points are currently lobby.html, game.html, and admin.html.

## Current Frontend Session Behavior
- Auth is session-cookie based.
- Lobby page calls GET /45s/api/auth/me on load to determine current session state.
- When authenticated:
  - the sign-in/register form is hidden
  - current user information is shown
  - lobby lists are loaded for the signed-in user
- When unauthenticated:
  - sign-in/register form is shown
  - game page displays a sign-in link back to lobby with a safe next= return path
- Logout requires CSRF and clears the session cookie-backed auth state.

## Current Game Board Behavior
- Board URL is /45s/game.html?game_id={id}.
- Viewer perspective is fixed:
  - south = viewer seat
  - west = next clockwise seat from viewer
  - north = partner across from viewer
  - east = remaining opponent
- Dealer seat is visually marked.
- Current turn seat is visually marked.
- Cards played in the current trick render in the center of the table.
- Won tricks are shown as rotated side stacks using explicit trick_won events when available.
- Board includes a Hand Flow tracker with these phases:
  - deal
  - bid phase
  - bid winner + kitty
  - discard
  - restock to 5
  - trick rounds 1..5

## Current Game State API Shape
- GET /45s/api/game/get_state returns:
  - game
  - players
  - events
  - viewer_seat
  - viewer_hand
- get_state requires authenticated session user.
- Non-admin/non-owner users may only read games they are seated in.
- viewer_hand is currently derived deterministically from game_id plus card_played history.

## Current Event Types Used By Board
- game_created
- bid_action
- forced_dealer_bid
- bidding_closed
- kitty_picked_up
- discard_completed
- restock_completed
- card_played
- trick_won
- player_joined

## Current Runtime Constraints
- The current runtime supports:
  - lobby creation/joining
  - bidding turn progression
  - card play turn progression
  - trick winner event emission
  - board-level hand and trick visualization
- The current runtime does not yet implement a full authoritative 45s hand engine:
  - no persisted hands/deck/kitty tables yet
  - no server-side discard and draw mechanics yet
  - no full legal-move enforcement from true hand state yet
  - no final hand scoring/game-over pipeline yet

## Lobby Invite Modes
- create_game supports two invite styles:
  - open invite: players can claim any open human seat.
  - specific invite: selected users are pre-assigned to selected seats.
- Request fields for POST /45s/api/lobby/create_game:
  - target_score: 45 or 120
  - ruleset: string (chartrand default)
  - user_id: creator user id (seat 0)
  - ai_seats: array of seat numbers to reserve as AI
  - invite_mode: open or specific
  - invites: array of {seat, user_id} where seat must be 1..3
- Example specific invite payload:
  - {"target_score":120,"ruleset":"chartrand","user_id":1,"invite_mode":"specific","invites":[{"seat":1,"user_id":2},{"seat":3,"user_id":4}],"ai_seats":[2]}

## Multi-game Support
- A single player can participate in multiple games at the same time.
- New lobby discovery endpoints:
  - GET /45s/api/lobby/my_games?user_id={id}
    - returns games where the user already has a seat
  - GET /45s/api/lobby/list_joinable?user_id={id}
    - returns games where the user is specifically invited or where open human seats exist

## Security Hardening
- CSRF protection is enabled on state-changing routes.
- Include header X-CSRF-Token for protected POST requests.
- Obtain the token from GET /45s/api/auth/me (csrf_token) or GET /45s/api/auth/csrf.
- Current CSRF-protected routes:
  - POST /45s/api/auth/logout
  - POST /45s/api/admin/users/create
  - POST /45s/api/admin/users/role
  - POST /45s/api/lobby/create_game
  - POST /45s/api/lobby/join_game
  - POST /45s/api/game/submit_bid
  - POST /45s/api/game/play_card

## Known Active Gaps
- Some older game rows may contain event payloads that do not fully match newer board expectations.
- get_state has been hardened against malformed event payloads, but old historical data may still limit what the board can infer.
- Session continuity depends on the browser carrying the PHP session cookie across lobby and game page requests.

## Next Steps
- Build a true authoritative hand/deck/kitty model in persistence instead of deriving viewer_hand from game_id.
- Implement discard and replacement draw as first-class actions and events.
- Add explicit trump declaration and true 45s trump/lead legality rules.
- Replace manual card-code entry with clickable playable cards from the rendered hand.
- Add score-hand, set tracking, bid-out, and game-over behavior.
- Add board-level polling or incremental event refresh instead of full-state refresh every cycle.

## Rate Limit Policy
- Auth endpoints enforce request limits and return HTTP 429 on exhaustion.
- Response body includes retry_after (seconds).
- Current limits:
  - register: 10 attempts per 15 minutes per IP
  - login: 8 attempts per 15 minutes per username+IP key
  - google login: 15 attempts per 15 minutes per IP
