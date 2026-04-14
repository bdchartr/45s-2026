# Deploy 45s

Deploy the Forty-Fives app to production at https://wkapp.com/45s/

## Prerequisites

- SSH key loaded: `ssh-add ~/.ssh/id_ed25519` (or `id_rsa`)
- Verify access: `ssh -p 2222 chartb@wkapp.com "echo ok"`

## Deploy

Run from the project root (`/Users/chartb/excl/45s`):

```bash
./scripts/deploy.sh
```

This uploads all files (except `server/config.php`, `vendor/`, `.git/`) via sftp to `/home/chartb/public_html/45s` on `wkapp.com:2222`.

## First-time or schema changes

If new database tables are needed, run the migration **before** deploying code:

```bash
ssh -p 2222 chartb@wkapp.com
cd /home/chartb/public_html/45s
php -r "
\$c=require 'server/config.php';
\$d=\$c['db'];
\$p=new PDO(\$d['dsn'],\$d['username'],\$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
\$sql=file_get_contents('sql/migrate_add_hand_tables.sql');
foreach(array_filter(array_map('trim',explode(';',\$sql)),fn(\$s)=>\$s!='') as \$s){
  \$p->exec(\$s);
  if(preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i',\$s,\$m))echo 'OK: '.\$m[1].PHP_EOL;
}
echo 'Migration complete.'.PHP_EOL;
"
```

Migration files live in `sql/`. All migrations use `CREATE TABLE IF NOT EXISTS` so they are safe to re-run.

## Post-deploy smoke check

1. Open https://wkapp.com/45s/ — should redirect to lobby
2. `GET /45s/api/auth/me` from browser — should return JSON with `csrf_token`
3. Log in, create a game, verify hand is dealt and bidding phase starts

## Server details

| Item | Value |
|------|-------|
| Host | wkapp.com |
| Port | 2222 |
| User | chartb |
| Remote path | /home/chartb/public_html/45s |
| URL | https://wkapp.com/45s/ |
| PHP config | server/config.php (not in git) |
