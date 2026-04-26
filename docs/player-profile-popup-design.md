# Player Profile Popup — Design Spec

**Audience:** Implementation by Sonnet. Read this end-to-end before writing
code; everything Sonnet needs to ship is in here.

## 1. The actual bug (do this first)

The popup *infrastructure* is already built (`public/player-modal.js`,
~22 KB, complete with avatar grid, stats panel, account tab, monthly
sparkline, partners/opponents tables) and `openPlayerModal(userId)` is
already wired into both `lobby.html` and `game.html` from clickable
player names. **It just never loads.**

Both pages reference the script as:
```html
<script src="player-modal.js"></script>
```
Resolved from a rewritten `/45/lobby.html` page, the browser fetches
`/45/player-modal.js`. The `.htaccess` only rewrites the four named
HTML files (`game|lobby|admin|player.html`); JS siblings fall through
to `index.php`, which returns 0 bytes. The real file is at
`/45/public/player-modal.js`.

**Fix:** add a single rewrite line to `.htaccess`:

```apache
# Static assets in /public/ should be reachable from the rewritten URLs.
RewriteRule ^(player-modal\.js|.+\.css|.+\.png|.+\.svg|.+\.jpg)$ public/$1 [L]
```

If you don't want to be that broad, narrow to just `player-modal.js`:
```apache
RewriteRule ^player-modal\.js$ public/player-modal.js [L]
```

Either is fine. Verify with `curl -sI https://wkapp.com/45/player-modal.js`
that you get a 200 with a non-zero Content-Length.

After this fix, all the existing self-popup (with stats + account tab)
behaviour works. Smoke-test by clicking your username in the topbar and
confirming you see the modal with the Stats + Account tabs.

## 2. The three modes the popup must support

The popup is opened in three contexts; the contents differ.

| Mode    | Triggered when                                    | Tabs available |
|---------|---------------------------------------------------|----------------|
| **Self**     | viewer clicks their own name                  | Stats, Account |
| **Human**    | viewer clicks another human player's name     | Stats          |
| **AI**       | viewer clicks an AI seat's name (NEW)         | Bot            |

The existing modal already supports Self and Human (Account tab
auto-hidden when `isSelf === false`). The two gaps are:

1. AI seats are not currently clickable.
2. Self stats need to be richer than what other-human sees.

## 3. Where the popup opens from (trigger surface)

Already wired (verify after the .htaccess fix):

- `lobby.html`: topbar username, player names in active-games card,
  player names in open-tables card, player names in seat-picker chips.
- `game.html`: `playerNameEl(p)` in `renderPlayerZone`, and the new
  `nameChip` inside the `viewer-status` strip.

Needs new wiring:

- `game.html` — `playerNameEl(p)` currently short-circuits AI players
  to plain text:
  ```js
  if (!p || !p.user_id || Number(p.is_ai) === 1) {
    return document.createTextNode(name);
  }
  ```
  Change so AI players render as a clickable span that opens the
  AI-mode popup. Pseudocode:
  ```js
  if (!p) return document.createTextNode(name);
  if (Number(p.is_ai) === 1) {
    const span = clickableSpan(name);
    span.onclick = () => openAiInfo(p);  // p has display_name, seat
    return span;
  }
  // existing user case ...
  ```

- The popup-init module exposes `openPlayerModal(userId)`. Add a new
  sibling export `openAiInfo(playerRow)` so the call sites stay simple.
  Or: extend `openPlayerModal` to accept an object descriptor like
  `openPlayerModal({ kind: 'ai', display_name, seat })`. **Pick the
  object form** — it lets future modes (open seat? invited but not
  joined? bots with strategy) compose without proliferating function
  names. Keep `openPlayerModal(123)` working as a shorthand for
  `openPlayerModal({ kind: 'user', userId: 123 })`.

## 4. AI mode — the new content

The Bot tab (the only tab in AI mode) shows:

```
┌──────────────────────────────────────────┐
│  ◆     Cora                       ✕      │
│        Computer player · Seat 3          │
├──────────────────────────────────────────┤
│  [ Bot ]                                 │
├──────────────────────────────────────────┤
│  Strategy        Algorithmic             │
│  Difficulty      Medium                  │
│  About                                   │
│  This bot bids by counting trump cards   │
│  in hand, follows suit when required,    │
│  and reneges on top trumps when allowed. │
│  It plays the same way every game.       │
│                                          │
│  Personality     —                       │
│  Wins                  (placeholder)     │
└──────────────────────────────────────────┘
```

**Data source.** No DB read needed today; everything in this panel is
derived from in-memory state plus a constant lookup. Concretely:

| Field        | Source                                                          |
|--------------|-----------------------------------------------------------------|
| Avatar       | A coloured chip with the bot's first initial. No gravatar.      |
| Name         | `playerRow.display_name` (the family-pool name)                 |
| Seat         | 1-based: `playerRow.seat + 1`                                   |
| Strategy     | Hardcoded: `'Algorithmic'` — there is only one provider today.  |
| Difficulty   | Hardcoded: `'Medium'`. Leave a TODO for per-bot levels.         |
| About        | Static blurb, kept inside `player-modal.js`.                    |
| Personality  | Empty — placeholder for future flavour text per family name.    |
| Wins         | Reserve the slot; show `—` until we track per-bot win rates.    |

**Future hook (not for this round):** add a `difficulty` column on
`game_players` (or a `bot_strategy` row), let `create_game` pick a
level, and let the AI runner choose a strategy class accordingly.
For now: nothing in the DB, just a static panel.

## 5. Human mode — what the read-only view shows

Already mostly built (`renderStats(stats)`). The only polish needed:

- Show a "Common games with you" line at the top of the Stats panel
  before the partners/opponents tables. Compute from
  `partners.find(p => p.user_id === viewerId)` plus
  `opponents.find(p => p.user_id === viewerId)`. Display:
  ```
  You and Brent: 12 games · 7–5 (58%)
  ```
- Hide the monthly sparkline in human mode if it's empty (today it
  shows "No game history yet."); fine to keep as-is.
- Keep the Account tab hidden for non-self (already enforced).

## 6. Self mode — make the stats richer

The existing self view shows: KPIs (Played / Wins / Win Rate), monthly
win % sparkline, common partners, common opponents. The user asked for
**more detail when looking at themselves**. Add:

### a. Toggle: with-AI vs all-humans

`/api/player/stats` today filters `gp_co.is_ai = 0` for the
partners/opponents query, so AI co-players never appear. This is fine
for "real" stats but the user occasionally wants to see how often they
play with which AI bot. Add a `?include_ai=1` query parameter; when
set, the partners/opponents lists also include AI seats keyed by
`display_name` (no `user_id`).

In the self view, render two tabs above the partners table or a small
toggle: **All players** (default) / **Humans only** / **Bots only**.
Three counts so the user can compare easily.

### b. Per-partner outcome rollup

Today the partners table is `Player | Games | Win % | bar`. Expand the
hovered/expanded row to show:

```
Brent Chartrand   12 games   58%   ████░
  └─ 7 wins · 5 losses · last played 2 weeks ago
```

`statsForUser` already groups by user_id. Add `last_played` (max
`g.updated_at`) to the partner/opponent rows.

### c. Win-rate trend by year

The monthly sparkline goes back 18 months. For users who play across
years, also expose a "By year" view: bars for each year showing
games-played vs wins. Computed from the same `finishedGames` list
already pulled — just group by `SUBSTR(updated_at, 1, 4)`.

UI: a small toggle above the sparkline, **Months / Years**, swapping
the chart. The same `pm-chart` div renders one or the other.

### d. Set-out / bid-out counters

These are 45s-specific. Add three small KPIs alongside Played / Wins /
Win Rate:

| KPI            | What it counts                                              |
|----------------|-------------------------------------------------------------|
| Hands bid      | Hands where the user (or their team) won the bid.           |
| Bids made      | Bid hands the team made.                                    |
| Sets taken     | Bid hands the team got set on.                              |

Pull from a new SQL pass over `hands` joined to `game_players`. This
is a meaningful stat for a 45s player ("am I a reckless bidder?"); it
should not appear in the human-mode view (private to self).

## 7. Account tab additions

Already built: Display Name, Avatar grid, Change Password.

User requested: "eventually upload a picture or pick an avatar, etc.
For now maybe use gravatar?"

Gravatar is **already wired** — `/api/player/stats` returns
`user.gravatar_url` and the modal header falls back to it when
`avatar_code` is null. So the gravatar story is done; nothing new.

Add to the Account tab a small `Email` field next to the gravatar
preview, with a **Save email** button. Updating email re-derives the
gravatar URL. (Pre-existing endpoint? If not, add
`POST /api/player/update_email`.) This is what the user actually wants
when they say "upload a picture for now" — they want to be able to
switch their gravatar by setting the email.

## 8. Avatar slot

The header already has a 52×52 round avatar slot
(`#pm-header .pm-avatar`). It accepts:

1. `avatar_code` → render the suit/colour chip inline (no network).
2. else `gravatar_url` → `<img>` with object-fit: cover.
3. else neutral `?` placeholder.

The user wrote "leave a place for a picture which can be added later"
— this is the slot. Document this in `implementation_details.md` once
the broader work lands so it isn't reinvented.

## 9. Behaviour & accessibility details

- **Open** — clicking a clickable name calls `openPlayerModal(...)`.
  Cursor is `pointer`, name is underlined (already true for users; add
  for AI).
- **Close** — clicking the backdrop, the `✕ Close` button, or pressing
  Escape. Backdrop click already implemented; Escape needs a
  `keydown` listener on `dialog` while open. Add it.
- **Loading** — show the existing `pm-spinner` while the API call is
  in flight. AI mode skips the API entirely so it should never spin.
- **Errors** — if `/api/player/stats` fails (auth lost, 404), the
  existing "Could not load profile" error is fine; verify it actually
  appears. AI mode has no failure path.
- **Tab order** — the modal must trap focus within itself while open.
  The existing implementation does not do this. Add: on open, focus the
  Close button; trap Tab/Shift-Tab between focusable elements inside
  `#pm-box`.
- **Mobile** — the modal is `max-width: 560px; max-height: 88vh` which
  works on phones. Verify the new AI panel doesn't overflow.

## 10. File touch list

When implementing, expect to edit:

| File                                                       | Change                                                              |
|------------------------------------------------------------|---------------------------------------------------------------------|
| `.htaccess`                                                | Add static-asset rewrite for `player-modal.js`.                     |
| `public/player-modal.js`                                   | Object-form `openPlayerModal({...})`; add AI panel; richer self stats; trend toggle; Escape/focus-trap. |
| `public/game.html`                                         | Make AI player names clickable; route to `openPlayerModal({kind:'ai', ...})`. |
| `public/lobby.html`                                        | If lobby surfaces AI rows anywhere, make those clickable too (currently only humans show in seat-picker invite mode). |
| `app/Infrastructure/Persistence/GameRepository.php`        | `statsForUser` returns `last_played` per partner/opponent; add bid/set/sets-taken counters; optional `include_ai` flag. |
| `app/Infrastructure/Http/Routes.php`                       | Add `?include_ai=1` query parameter pass-through; optional `POST /api/player/update_email`.                              |
| `tests/Infrastructure/Persistence/GameRepository*Test.php` | Cover the new stats fields. SQLite fixture already exists.          |
| `implementation_details.md`                                | Document the modal's three modes and the avatar fallback chain.     |

## 11. Out of scope (defer to later passes)

- Multiple AI difficulty levels (would need a new strategy class hierarchy).
- Real avatar uploads (S3 or local disk). Gravatar is the current story.
- Per-bot win-rate stats (we don't track which bot played which game per-row; would need a fingerprint or `bot_strategy` column).
- "Friend list" / "block player" — not requested.
- Chat / messaging through the popup — not requested.

## 12. Acceptance checklist

- [ ] Click your own name in lobby topbar → modal opens with Stats + Account tabs; Stats shows your KPIs; Account lets you change nickname/avatar/password.
- [ ] Click another human's name in an active-games card → modal opens with Stats only; Account tab is absent.
- [ ] Click an AI player's name in any seat zone on the game board → modal opens with the Bot panel; no Stats or Account tab; the displayed name and seat match the family-pool name shown on the table.
- [ ] Press Escape → modal closes regardless of mode.
- [ ] Self Stats: trend toggle switches the chart between Months and Years; partners table shows `last_played`; new bid/set KPIs are visible.
- [ ] Email field on the Account tab updates the gravatar shown in the header after Save.
- [ ] All 167 existing tests still pass; new GameRepository tests for `last_played` and bid/set counters added.
