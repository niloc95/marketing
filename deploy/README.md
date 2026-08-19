# Lightsail deployment

Everything needed to stand up the AWS Lightsail instance that serves both
`webscheduler.co.za` (marketing) and `listing.webscheduler.co.za` (the CI4
directory app), and to cut over to it from Hostinger.

**[LAMP-SETUP.md](LAMP-SETUP.md) is the runbook** — bare Ubuntu to live, typed by
hand in the Lightsail browser terminal. This file is the reference for what the
stack *is* and why. For a routine release once the migration is done, see the
`deploy-production` skill.

```
deploy/
├── LAMP-SETUP.md                            the runbook — start here
├── apache/
│   ├── conf-available/webscheduler-logformat.conf
│   ├── webscheduler.co.za.conf              marketing vhost
│   └── listing.webscheduler.co.za.conf      listing vhost
├── php/99-webscheduler.ini                  both SAPIs
├── mysql/99-webscheduler.cnf
├── cron/webscheduler                        → /etc/cron.d/
└── env                                      GIT-IGNORED. Real credentials.
```

Everything except `env` is tracked, because the server installs these files out
of its own `git clone` — there is no upload step and no provisioning script.
`deploy/env` is a filled-in working copy (live DB, SMTP, PayFast and admin
credentials) and this repository is **public**: `.gitignore` excludes it by full
path, since the `.env*` glob does not match a bare `env`.

## The stack

| | |
|---|---|
| Instance | Ubuntu 24.04 LTS **OS Only**, 4 GB / 2 vCPU / 80 GB, `ap-south-1` (Mumbai) |
| Web | Apache 2.4 (`mpm_event`) + PHP 8.3-FPM over `mod_proxy_fcgi` |
| DB | MySQL 8.0, co-located, bound to `127.0.0.1` |
| TLS | Cloudflare Origin Certificate, 15 years, SSL/TLS mode **Full (strict)** |
| Install | `apt` packages, configured by hand |
| Release | `git pull` on the server → build there → `cp -a` into `/var/www` |

All of it comes from Ubuntu's own `main` repository — no `ondrej/php` PPA.

Not the Bitnami LAMP blueprint: it ships its own tree under `/opt/bitnami` with
non-standard paths and `AllowOverride None`, which fights the `.htaccess`-dependent
layout this app needs.

There is deliberately **no provisioning script and no CI deploy**. An earlier
attempt had both — a 260-line `provision.sh` and a GitHub Actions rsync pipeline —
and neither produced a working origin; the abstraction got in the way of
understanding the box. `.github/workflows/deploy.yml` is retained but fires only
on manual dispatch, and has no credentials to run with.

## Server layout

```
/var/www/
├── .ws-contact.env                ← chmod 640 deploy:www-data, SMTP creds for contact.php
├── marketing/                     ← webscheduler.co.za docroot (dist/site/)
│   └── developer/                 ← from ANOTHER repo; never in this deploy
└── listing/
    ├── directory-app/             ← ABOVE the web root: app/ vendor/ writable/ .env
    └── public_html/               ← listing.webscheduler.co.za docroot
/home/deploy/src/                  ← the git clone; where both apps are built
/home/deploy/.my.cnf               ← chmod 600, credentials for the nightly dump
/home/deploy/backups/              ← nightly mysqldump, 14-day retention
```

### Two file modes that are not arbitrary

Both of these fail in ways that look like application bugs, and both were wrong
in the previous version of this document:

**`/var/www/listing/directory-app/.env` — `640 deploy:www-data`, not `600`.**
CodeIgniter reads it at boot as the PHP-FPM user, `www-data`. At `600` owned by
`deploy` the app falls back to an empty database name and 500s on every page,
while static files keep serving perfectly.

**`.ws-contact.env` lives at `/var/www/.ws-contact.env`, not `/home/deploy/`.**
`contact.php` resolves it as `__DIR__ . '/../.ws-contact.env'` — one level above
its own docroot, which is `/var/www/marketing`. It must also be readable by
`www-data`. `/var/www` is not itself a DocumentRoot, so nothing serves it. Get
this wrong and the contact form accepts submissions and delivers nothing.

### Secrets to copy from the old host — do not regenerate

- `/var/www/listing/directory-app/.env` — change **only** `database.default.*`.
  `app.baseURL`, PayFast, SMTP, `directory.prefillSecret`, `adminPasswordHash`
  and `healthToken` all carry over unchanged, because the domain is not changing.
- `/var/www/.ws-contact.env` — SMTP credentials for the marketing contact form.

> **Keep the same `encryption.key`.** A new one invalidates every existing
> session and anything encrypted at rest.

`.env.production` in this repo is not a substitute for either: it is stale and
missing `encryption.key`, `directory.adminPasswordHash` and `app.indexPage`.

## Migrating the data

Three things exist in exactly one place and are in no git repo:

```bash
# On Hostinger (hPanel → Advanced → Terminal)
mysqldump --default-character-set=utf8mb4 --single-transaction \
  --routines --triggers <cpuser>_directory > directory.sql
tar czf uploads.tgz      public_html/assets/listings/
tar czf verification.tgz directory-app/writable/verification/
```

The new instance has outbound internet, so pull these with `lftp` directly rather
than routing them through your laptop — see LAMP-SETUP.md Part 9.

Load them, then **verify the spatial column survived** — a `POINT ... SRID 4326`
column is the piece most likely to come back subtly wrong:

```sql
SHOW CREATE TABLE xs_directory_listing_points\G   -- expect SRID 4326 + SPATIAL KEY
SELECT COUNT(*) FROM xs_directory_listing_points;
```

After untarring the uploads, re-apply ownership — `tar` does not preserve the
setgid bits, and extracting under `sudo` leaves everything root-owned:

```bash
sudo chown -R deploy:www-data /var/www/listing/public_html/assets/listings \
                              /var/www/listing/directory-app/writable
sudo find /var/www/listing/public_html/assets/listings -type d -exec chmod 2775 {} +
sudo chmod 2770 /var/www/listing/directory-app/writable/verification
```

## Rehearsal — before DNS moves

Point your own machine at the instance without touching public DNS. `--resolve`
avoids editing `/etc/hosts`; `-k` is needed because a Cloudflare origin
certificate is trusted by Cloudflare and nothing else:

```bash
curl -k --resolve listing.webscheduler.co.za:443:<static-ip> \
     -sI https://listing.webscheduler.co.za/directory
```

### Verification

**Stack, on the box:**
```bash
php -v                                          # 8.3.x
php -r 'var_dump(gd_info()["WebP Support"]);'   # must be true
mysql -e 'SELECT VERSION();'                    # 8.0.3+
apache2ctl -M | grep -E 'rewrite|headers|proxy_fcgi'
apache2ctl -S                                   # both vhosts, right docroots
```

**`.htaccess` is actually in force** — the check that catches `AllowOverride None`:
```bash
curl -sI https://listing.webscheduler.co.za/directory      # 200, NOT 404
curl -sI https://listing.webscheduler.co.za/about/         # 301, slash stripped
curl -sI https://www.webscheduler.co.za/                   # 301 → https:// (never http://)
```
If `/directory` 404s while `/index.php/directory` returns 200, rewriting is off
and nothing else is wrong.

**App behaviour:**
```bash
curl -sI https://listing.webscheduler.co.za/add-listing | grep -i set-cookie
    # Secure; HttpOnly; SameSite=Lax
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
     -d "display_name=x" https://listing.webscheduler.co.za/add-listing
    # 403 — CSRF blocking the write
curl -s "https://listing.webscheduler.co.za/health?token=$HEALTHTOKEN"
    # every check green
curl -s https://listing.webscheduler.co.za/sitemap.xml | head -5
    # real domain, not CHANGE-ME or localhost
```

`/health` is the one that matters most here. A `writable/` permissions mistake
does not break the homepage — it breaks the file cache, and **every rate limit
with it**, while the site keeps returning 200. `/health` is what makes that
visible.

**The marketing docroot still has `/developer/`:**
```bash
curl -sI https://webscheduler.co.za/developer/   # 200, not 404
```

**End to end, in a browser:** submit a listing → receive the verification email
(proves SMTP:465 egress) → confirm it publishes → upload a logo (proves GD/WebP
and upload permissions) → sign in at `/admin` → feature/unpublish. Then load the
marketing site and submit the contact form (proves `contact.php` found
`.ws-contact.env` above the docroot).

## Cutover

1. A day ahead, drop the Cloudflare TTL on both records to 2 minutes.
2. Run the rehearsal above. Fix everything there.
3. Take a **final** `mysqldump` and re-sync uploads — the rehearsal copy is stale.
4. Point both Cloudflare A records at the static IP. Stay orange-clouded, stay
   Full (strict).
5. Watch `/health` and `writable/logs/` for an hour. Submit a real listing.
6. Leave Hostinger running, untouched, for **at least a week**.
7. Then delete the retired instance **and release its static IP** — an unattached
   static IP is billed.

Leave `directory.webscheduler.co.za` alone — a leftover Cloudflare record with
nothing behind it that answers `526`. Don't probe it, don't "fix" it mid-cutover.

### Rollback

Repoint the two Cloudflare A records back at Hostinger. Minutes, no code change.

That holds only while Hostinger stays paid up and its database is not stale — any
listing created after cutover would need replaying by hand. **Migrations are
forward-only:** `migrate:rollback` against production stays banned. Code rolls
back by rebuilding an older commit; schema is fixed forward with a new migration.

## Things that will bite

**`AllowOverride All` is load-bearing.** Ubuntu ships `AllowOverride None` for
`/var/www/`. The listing vhost sets `All` for its own docroot, so this is
normally fine — but if it is ever removed, the homepage still loads and every
other URL 404s, which looks like an application bug and is not.

**The build deletes its own output directory.** `npm run list:build` starts with
`rm -rf dist/listing`. Never point a vhost at `dist/` — a rebuild would take every
uploaded logo and verification document with it. Build there, `cp -a` to
`/var/www`.

**`cp -a` copies, it does not mirror.** That is why
`/var/www/marketing/developer/` — deployed from `niloc95/xscheduler_ci4` into the
same docroot — survives a marketing release, and why uploads survive a listing
release. The cost is that files deleted from the repo linger on the server until
removed by hand.

**Asset filenames are not content-hashed**, so the Cloudflare purge is
load-bearing. A release with an unpurged cache looks exactly like a release that
did nothing.

**Don't add `mod_remoteip`.** It rewrites `REMOTE_ADDR`, which is precisely what
`App\Config\App::$proxyIPs` inspects before trusting `CF-Connecting-IP`. The
throttles keep working afterwards, so nothing tells you the trust path changed.
The `cloudflare` LogFormat already puts real client IPs in the access log.

**`php_admin_flag` does not exist under PHP-FPM.** It is a mod_php directive;
Apache refuses to start with "Invalid command". Disarm PHP in a directory with
`SetHandler None` instead.

**Outbound port 25 is blocked by AWS** on new accounts. Irrelevant here — mail
goes out over 465 — but it is the trap if anyone later reaches for `sendmail`.
