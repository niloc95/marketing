---
name: deploy-production
description: Release the WebScheduler marketing site and/or the directory app to production — the manual release procedure (git pull and build on the server), how to verify a release actually landed, and how to roll back. Use when deploying, cutting a release, shipping a migration, or investigating why a change is not visible on the live site.
---

# Releasing to production

| App | Live at | Docroot |
|---|---|---|
| Marketing site | `https://webscheduler.co.za` | `/var/www/marketing` |
| Directory app | `https://listing.webscheduler.co.za` | `/var/www/listing/public_html` |

Both run on **one AWS Lightsail instance** (Ubuntu 24.04, Apache 2.4 + PHP 8.3-FPM +
MySQL 8.0), behind Cloudflare in Full (strict) mode.

**The directory hostname is `listing.`, not `directory.`** A
`directory.webscheduler.co.za` DNS record exists in Cloudflare with nothing behind it
and answers `526` to everything. It is a leftover. Probing it and concluding the app is
down is a mistake that has already been made once — check `listing.` first, and treat
`/health` as the source of truth.

**Nothing deploys automatically. There is no CI deploy.** Pushing to `main` ships
nothing; `.github/workflows/deploy.yml` is dormant (manual dispatch only, and the
`SSH_*` secrets it needs do not exist). Releases are typed by hand in the **Lightsail
browser terminal**: `git pull` in the server's own clone, build there, `cp -a` into
`/var/www`. If you find instructions describing an rsync pipeline or an auto-deploy on
push, they are stale.

The instance was provisioned by hand too — no `provision.sh`. `deploy/LAMP-SETUP.md` is
the full bare-Ubuntu-to-live runbook, `deploy/README.md` is the reference for what the
stack is and why, and `DEPLOY.md` is the historical Hostinger record. This skill is the
recurring release procedure.

## Before you release

1. Push to `main` first — the server releases from its own clone, so an unpushed commit
   cannot ship.
2. `npm run site:build` and `npm run list:build` locally — both must exit 0. Each has
   guards (banned phrases, `mailto:` form actions, missing PHPMailer, CSRF filter
   disabled, `http://` downgrade in `.htaccess`, missing seeder) that fail loudly. They
   run again on the server, but finding a guard failure there means a half-finished
   release on a live box.
3. If the change touches `app/Database/Migrations/`, read "Migrations" below.
4. If the change touches the contact form or the privacy policy, they must ship
   together. See "The contact/privacy coupling".

## Release

Lightsail console → the instance → **Connect using SSH**. Work in `tmux` — the browser
session drops when idle and takes the build with it.

```bash
tmux new -s release        # or: tmux attach -t release

sudo -u deploy -H bash -lc 'cd ~/src && git pull \
  && composer install --no-dev --optimize-autoloader \
  && npm ci --ignore-scripts && npm run list:build && npm run site:build'

sudo cp -a /home/deploy/src/dist/site/.                       /var/www/marketing/
sudo cp -a /home/deploy/src/dist/listing/directory-app/.      /var/www/listing/directory-app/
sudo cp -a /home/deploy/src/dist/listing/docroot/.            /var/www/listing/public_html/

# ALWAYS — cp -a under sudo leaves everything root-owned
sudo chown -R deploy:www-data /var/www/listing /var/www/marketing
sudo chmod -R 2775 /var/www/listing/directory-app/writable
sudo chmod 2770 /var/www/listing/directory-app/writable/verification
sudo find /var/www/listing/public_html/assets/listings -type d -exec chmod 2775 {} +
sudo chmod 640 /var/www/listing/directory-app/.env
sudo chmod 755 /var/www/listing/directory-app/spark

cd /var/www/listing/directory-app && sudo -u deploy php spark migrate --all
sudo systemctl reload php8.3-fpm
```

Then purge the Cloudflare cache (*Caching → Configuration → Purge Everything*) and run
the verification below. Marketing-only change? The listing `cp -a` lines, the migration
and the FPM reload are all skippable — but re-running them is harmless.

## `cp -a` copies, it does not mirror

This is the property that makes a hand release safe, and the one gap it leaves.

**Safe:** anything on the server that the bundle does not contain simply stays. That is
how these survive a release, all of which exist in exactly one place:

| Path | What it is |
|---|---|
| `/var/www/marketing/developer/` | built from **another repo** (`niloc95/xscheduler_ci4`); nothing in `dist/site/` reproduces it |
| `directory-app/.env` | the server's secrets; the bundle never carries one |
| `writable/verification/` | uploaded ID and registration documents |
| `writable/{cache,logs,session}/` | runtime state — losing `cache` takes every rate limit with it |
| `public_html/assets/listings/` | owner-uploaded logos and photos |

Under the old rsync pipeline every one of those needed an explicit `--exclude`, because
`rsync --delete` mirrors and has no memory of what it placed. It does not any more.

**The gap:** a file deleted from the repo is *not* deleted from the server. A renamed
template or a removed asset lingers and keeps being served. Remove those by hand, and
check for them when a release renames anything in `public/` or `marketing-site/`.

Never point a vhost at `dist/` to skip the copy: `npm run list:build` begins by deleting
its whole output directory, so the next release would take every uploaded logo and
verification document with it.

## Verifying a release actually landed

Nothing checks this for you. A build that exited 0 is necessary, not sufficient:

```bash
# 1. Is Cloudflare still serving the old copy?
#    cf-cache-status: HIT on a page you just changed = purge did not happen
curl -sI https://webscheduler.co.za | grep -i cf-cache-status

# 2. Is the app healthy, not merely responding?
curl -s https://listing.webscheduler.co.za/health            # {"status":"ok"}

# 3. Did the developer portal survive the marketing deploy?
curl -sI https://webscheduler.co.za/developer/               # 200, not 404

# 4. Which CSP mode is live? Tells you whether the app is running current code.
curl -sD- -o /dev/null https://listing.webscheduler.co.za/ \
  | grep -oiE '^content-security-policy(-report-only)?:'
```

**Use GET, not HEAD, when probing app routes.** CI4 routes registered with
`$routes->get(...)` do not match `HEAD`, so `curl -I` on `/` returns a `404` JSON body
from an app that is working perfectly. `curl -sI` is safe against the static marketing
site and misleading against the directory app — use `curl -s -o /dev/null -w '%{http_code}'`
there instead.

The strongest check is a content diff against what you just built, run on the server
where both halves are present:

```bash
curl -s https://webscheduler.co.za/ -o /tmp/live.html
diff /tmp/live.html /home/deploy/src/dist/site/index.html && echo "live matches build"
```

And confirm the server is actually on the commit you think it is:

```bash
sudo -u deploy git -C /home/deploy/src log --oneline -1
```

`/health` returns 200 `{"status":"ok"}` only when database, cache, disk **and** mailer
all answer; 503 `{"status":"degraded"}` otherwise. Add `?token=<directory.healthToken>`
for a per-check breakdown. This matters because almost every way this app breaks still
returns HTTP 200 from `/` — mail can stop entirely and the homepage renders fine, and a
broken `writable/cache` disables every rate limit while the site looks perfect.

## Migrations

Manual, and easy to forget — which is the whole risk. Run them **immediately** after the
`cp -a`, before the FPM reload:

```bash
cd /var/www/listing/directory-app && sudo -u deploy php spark migrate --all
```

Between the copy and the migration, new code is live against the old schema. That
surfaces as a 500 on whichever page touches the new column — not as an outage anyone
notices. Keep the window to seconds.

**As `deploy`, never as root or `ubuntu`.** Spark writes to `writable/logs/`, and a
root-owned log file shows up much later as a log that silently stopped updating.

**Do not run `migrate:rollback` against production.** Migrations here are forward-only
in practice; several drop or rewrite columns, and a rollback against live listing data
loses it. To undo a bad migration, write a new one that reverses it.

## Symptom guide

| Symptom | Cause |
|---|---|
| `/` loads, every other URL 404s, `/index.php/directory` works | `AllowOverride All` missing — `.htaccess` is being read and ignored |
| Deploy green, site unchanged | Cloudflare cache not purged (filenames are not content-hashed) |
| `/health` 503 but `/` fine | a `writable/` path is not writable by `www-data`, or the DB/mailer is down |
| Logo uploads silently produce nothing | `ext-gd` without WebP — `imagewebp()` is `function_exists`-guarded |
| 500 on one page after a release | migration did not run, or ran against the wrong DB |
| `/developer/` 404 | someone deleted it by hand, or used `rsync --delete` instead of `cp -a` |
| Every page 500s, static files fine | `.env` not readable by `www-data` — must be `640 deploy:www-data`, not `600` |
| Contact form submits, nothing arrives | `.ws-contact.env` missing from `/var/www/`, or not readable by `www-data` |
| Apache will not start after a config edit | `php_admin_flag` used somewhere — mod_php only, invalid under FPM |

## The release is only as reliable as the person typing it

There is no run summary to read and no job to go red. The two steps most often skipped,
both of which fail silently:

- **The ownership block.** `cp -a` under `sudo` leaves `/var/www` owned by root. The
  site keeps serving; PHP stops being able to write. `/health` is what catches it.
- **The Cloudflare purge.** Asset filenames are not content-hashed, so a complete,
  correct release with an unpurged cache looks exactly like one that did nothing.

Both are in the Release block above. Run the whole block, not the parts that seem
relevant.

## Cloudflare

Both hostnames are proxied (orange cloud); DNS is on Cloudflare nameservers. TLS to the
origin uses a **Cloudflare Origin Certificate** at
`/etc/ssl/cloudflare/webscheduler.co.za.{pem,key}` — 15-year, no renewal cron, which is
exactly why `deploy/cron/webscheduler` carries a monthly expiry warning.

- **Cache**: asset filenames are not content-hashed, so a correct release can still
  leave visitors on the old build. Nothing purges for you — do it by hand at *Caching →
  Configuration → Purge Everything*, every time.
- **HTTP 526** means Cloudflare reached the origin but could not validate its
  certificate — now almost always the origin cert being absent, unreadable by Apache, or
  not covering the hostname. Check `apache2ctl configtest` and the cert's SANs. Never
  set SSL/TLS mode to Flexible — that plus the app's HTTPS redirect is a redirect loop.
- `curl -I` against a proxied host reports the **edge**, not your origin (`cf-ray`
  confirms it). To test the origin from the box:
  ```bash
  curl -k -H 'Host: listing.webscheduler.co.za' https://127.0.0.1/health
  ```
  From your Mac, `curl -k --resolve listing.webscheduler.co.za:443:<static-ip> …` does
  the same thing. `-k` is required either way: the origin certificate is trusted by
  Cloudflare and nothing else.

## Server-side things a release never touches

| Path | What it is |
|---|---|
| `/var/www/listing/directory-app/.env` | app secrets; the server's copy is authoritative. **`640 deploy:www-data`** — at `600` the app 500s on every page while static files keep serving |
| `/var/www/.ws-contact.env` | marketing contact-form SMTP config. One level **above** `/var/www/marketing`, because `contact.php` resolves `__DIR__ . '/../.ws-contact.env'`. Also `640 deploy:www-data` |
| `/home/deploy/.my.cnf` | credentials for the nightly `mysqldump`, chmod 600 |
| `/etc/ssl/cloudflare/` | origin certificate |
| `/etc/cron.d/webscheduler` | verification sweep, nightly dump, cert warning |

Changing any of these is a separate hand edit on the box. `deploy/` carries the
templates; the two `.env` files exist only on the server.

## The contact/privacy coupling

`marketing-site/contact.php` is the only dynamic file on the marketing site, and
`privacy.html` describes what it does with personal information. The two versions
describe two different systems — the older policy says the form "does not send anything
to us… nothing is posted to a server", which is true of a `mailto:` form and false of
the SMTP endpoint.

A release ships both from the same build, so this is automatic. It only becomes a
problem if someone copies `contact.php` alone onto the server to "just fix the form" —
that publishes an inaccurate description of your own data processing. Copy the build,
not the file. With a shell on the box that shortcut is one `cp` away, so it is worth
saying out loud.

## Rolling back

- **Marketing site**: `git -C /home/deploy/src checkout <last-good-commit>`, rebuild,
  copy, purge Cloudflare — or you will not see the rollback either. Low risk, static
  files. Return the clone to `main` afterwards (`git checkout main`), or the next
  release's `git pull` will surprise you.
- **Directory app**: code rolls back the same way. **Schema does not.** If the bad
  release included a migration, roll the code back to a commit whose code still matches
  the migrated schema, and fix forward with a new migration.
- **Whole-instance**: restore the most recent Lightsail snapshot. Blunt — it reverts
  uploads and database together — but it is the only rollback that covers a bad
  migration plus data loss at once.
- If the app must go down, point the subdomain at a holding page in Cloudflare rather
  than deleting files — an empty docroot on a proxied host gives visitors a raw error.

## First-launch-only steps

Not part of a routine release. **`deploy/LAMP-SETUP.md` is the full runbook** — bare
Ubuntu to live, in the browser terminal. These are the app-level pieces of it, run as
`deploy` from `/var/www/listing/directory-app`:

```bash
php spark key:generate                        # encryption.key — NOT on a migrated app
php spark directory:adminhash                 # then set directory.adminPasswordHash
php spark migrate --all
php spark db:seed DirectoryCategoriesSeeder   # 147 categories; without it, none anywhere
php spark directory:geocode                   # slow by design; also proves outbound HTTPS
```

`key:generate` is genuinely first-launch-only: on an instance migrated from an existing
host, a new `encryption.key` invalidates every existing session and everything encrypted
at rest. Carry the old one over instead.

Plus: the Cloudflare origin certificate, `/var/www/.ws-contact.env` (`640
deploy:www-data`, above the docroot), the `/etc/cron.d/webscheduler` jobs, Lightsail
automatic snapshots, and an uptime monitor on `GET /health` — **GET, not HEAD**.
