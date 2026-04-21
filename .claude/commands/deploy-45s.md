# Deploy 45s

Deploy the 45 app to production at https://wkapp.com/45/

## Pre-deploy checklist

1. **Run tests** — all must pass before deploying:
   ```bash
   php vendor/bin/phpunit
   ```
2. **Schema changes?** — run the migration on the server *before* deploying code (see below).
3. **Editing HTML?** — all frontend files live exclusively in `public/` (`game.html`, `lobby.html`, `admin.html`, `player.html`, `player-modal.js`). Never edit root-level HTML duplicates — they don't exist any more.

## Deploy

Run from the project root (`/Users/chartb/excl/45s`):

```bash
./scripts/deploy.sh
```

This uploads all files (except `server/config.php`, `vendor/`, `.git/`) via SFTP to `/home/chartb/public_html/45` on `wkapp.com:2222`.

## Running a migration (schema changes)

SSH in, then run the migration file against the live DB. Replace `migrate_add_foo.sql` with the actual file:

```bash
ssh -p 2222 chartb@wkapp.com "cd /home/chartb/public_html/45 && php -r \"
\\\$c=require 'server/config.php';
\\\$d=\\\$c['db'];
\\\$p=new PDO(\\\$d['dsn'],\\\$d['username'],\\\$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
\\\$sql=file_get_contents('sql/migrate_add_foo.sql');
foreach(array_filter(array_map('trim',explode(';',\\\$sql)),fn(\\\$s)=>\\\$s!='') as \\\$s){
  \\\$p->exec(\\\$s);
  echo \\\$s.PHP_EOL;
}
echo 'Done.'.PHP_EOL;
\""
```

All migration files use `ALTER TABLE … ADD COLUMN IF NOT EXISTS` or `CREATE TABLE IF NOT EXISTS` so they are safe to re-run. Migration files live in `sql/`.

## Post-deploy smoke check

1. Open https://wkapp.com/45/ — should redirect to lobby (200)
2. `GET /45/api/auth/me` — should return JSON with `csrf_token`
3. `GET /45/health` — should return `{"status":"ok"}`
4. Log in, create a 4-player game (3 AI seats), verify bidding phase starts and AI takes its turns within ~4 seconds

## Development workflow notes

- **All HTML edits go in `public/`** — the root `.htaccess` rewrites `/45/game.html` → `public/game.html`
- **PHP autoloaded classes** live under `app/` (PSR-4); `public/index.php` bootstraps Slim
- **API routes** are all in `app/Infrastructure/Http/Routes.php`
- **Game logic** is in `app/Application/Services/GameRuntimeService.php` and `app/Domain/Rules/`
- **AI logic** is in `app/Infrastructure/AI/AlgorithmicMoveProvider.php`
- Board polls `get_state` every 2 s; lobby polls every 3 s
- CSRF token comes from `GET /api/auth/me`; send as `X-CSRF-Token` header on all state-changing POSTs

## Server details

| Item | Value |
|------|-------|
| Host | wkapp.com |
| Port | 2222 |
| User | chartb |
| Remote path | /home/chartb/public_html/45 |
| URL | https://wkapp.com/45/ |
| PHP config | server/config.php (not in git) |
| DB | chartb_45s_gamestate (MariaDB 10.6) |
