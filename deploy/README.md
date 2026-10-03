# Lightsail deployment

Everything needed to stand up the AWS Lightsail instance that serves both
`webscheduler.co.za` (marketing) and `local.webscheduler.co.za` (the CI4
directory app, WebScheduler Local), and to cut over to it from Hostinger.

The app's former hostname, `listing.webscheduler.co.za`, is kept permanently as
a redirect that still serves PayFast ITNs. Read [Hostname: local. and the legacy
listing. host](#hostname-local-and-the-legacy-listing-host) before touching it.

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
│   ├── local.webscheduler.co.za.conf        WebScheduler Local vhost
│   ├── listing.webscheduler.co.za.conf      legacy host: 301 → local., serves /payfast/notify
│   └── listing.webscheduler.co.za.conf.serve  rollback only: the pre-move listing vhost
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
    └── public_html/               ← local.webscheduler.co.za docroot (listing. 301s here)
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
curl -k --resolve local.webscheduler.co.za:443:<static-ip> \
     -sI https://local.webscheduler.co.za/directory
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
curl -sI https://local.webscheduler.co.za/directory        # 200, NOT 404
curl -sI https://local.webscheduler.co.za/about/           # 301, slash stripped
curl -sI https://www.webscheduler.co.za/                   # 301 → https:// (never http://)
```
If `/directory` 404s while `/index.php/directory` returns 200, rewriting is off
and nothing else is wrong.

**App behaviour:**
```bash
curl -sI https://local.webscheduler.co.za/add-listing | grep -i set-cookie
    # Secure; HttpOnly; SameSite=Lax
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
     -d "display_name=x" https://local.webscheduler.co.za/add-listing
    # 403 — CSRF blocking the write
curl -s "https://local.webscheduler.co.za/health?token=$HEALTHTOKEN"
    # every check green
curl -s https://local.webscheduler.co.za/sitemap.xml | head -5
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

## Admin behind Cloudflare Access

`/admin` is protected by one shared password (gap #1 in `SECURITY-POSTURE.md`).
Cloudflare Access adds a second factor in front of it: an emailed one-time PIN,
checked at the edge before the request reaches the server. This is dashboard
configuration only, so nothing in the repo applies it or can tell whether it is
live. Check it with the curls below.

**1. The Access application.** Zero Trust → *Access → Applications → Add →
Self-hosted*.
- Application domain: `local.webscheduler.co.za`, path `admin`. Add a second
  domain row for the same host with path `admin/*`, because the bare path does not
  cover sub-paths. Keep the two matching rows for `listing.webscheduler.co.za`
  as well. That host only redirects now, but the rows cost nothing and matter
  again if it is ever rolled back to serving the app.
- Session duration: 24 hours.
- Policy: *Allow*, with Include set to *Emails* `za_admin@webscheduler.co.za`.
  Add any other admin address individually. Do not use a domain rule.
- Login method: *One-time PIN*, which is on by default.

Only `/admin*` is gated. `/payfast/notify`, `/manage/*`, `/health` and the rest
must stay reachable without Access. PayFast's server cannot answer a PIN
challenge.

**2. Close the bypass.** Access only sees traffic that comes through Cloudflare.
Anyone who finds the Lightsail IP can connect to it directly and meet only the
password. In Lightsail → instance → *Networking*:
- Restrict **HTTP (80)** and **HTTPS (443)** (IPv4 and IPv6) to the Cloudflare
  ranges listed in `App\Config\App::$proxyIPs`. If they have changed, re-fetch
  them from https://www.cloudflare.com/ips/.
- Leave **SSH (22)** open to any address. The GitHub Actions deploy connects from
  runner IPs that cannot be listed in advance, and key-only authentication is the
  control on that port.

After this, the `--resolve <static-ip>` rehearsal curls above stop working. That
is expected.

**3. Verify.**
```bash
# Expect a 302 to <team>.cloudflareaccess.com, not the app's own login page
curl -sI https://local.webscheduler.co.za/admin/login | grep -i '^location'

# Expect 200: the public site is not gated
curl -sI https://local.webscheduler.co.za/directory | head -1

# Expect a timeout: the origin no longer answers when Cloudflare is skipped
curl -sk --max-time 10 --resolve local.webscheduler.co.za:443:<static-ip> \
     https://local.webscheduler.co.za/ -o /dev/null -w '%{http_code}\n'
```
Also sign in once in a private window. You should get the PIN email, then the
app's password prompt.

**Rollback:** delete the Access application, and set 80/443 back to *Any IPv4 /
Any IPv6*. Both take effect within a minute.

## Block scanner probes at Cloudflare

About a fifth of all requests are bots probing for files this stack does not have:
`.env`, `.git`, WordPress, phpMyAdmin, PHPUnit's `eval-stdin.php`, SQL dumps. They
all fail already, as 404s or 400s. Blocking them at the edge keeps them off the
server and out of the logs. Measured against 14 days of access logs up to
29 Sep 2026, this rule matched 12,712 of 62,277 requests and **no** real page. The
few 301s it matched were probes being redirected to HTTPS.

Like Access, this is dashboard configuration only. Nothing in the repo applies it.

**1. The rule.** Cloudflare → `webscheduler.co.za` zone → *Security → WAF →
Custom rules → Create rule*.
- Name: `Block scanner probes`
- *Edit expression*, and paste:

```
(http.request.uri.path contains "/.env")
or (http.request.uri.path contains "/.git/") or ends_with(http.request.uri.path, "/.git")
or (http.request.uri.path contains "/.aws") or (http.request.uri.path contains "/.ssh")
or (http.request.uri.path contains "/.svn") or (http.request.uri.path contains "/.htpasswd")
or (http.request.uri.path contains "/.DS_Store")
or starts_with(http.request.uri.path, "/vendor/")
or (http.request.uri.path contains "/wp-admin") or (http.request.uri.path contains "/wp-includes")
or (http.request.uri.path contains "/wp-content") or (http.request.uri.path contains "/wp-login")
or (http.request.uri.path contains "/wp-config") or (http.request.uri.path contains "/wp-json")
or (http.request.uri.path contains "xmlrpc.php") or (http.request.uri.path contains "wlwmanifest.xml")
or (http.request.uri.query contains "rest_route=")
or (http.request.uri.path contains "/cgi-bin") or (lower(http.request.uri.path) contains "phpmyadmin")
or (http.request.uri.path contains "eval-stdin.php")
or ends_with(http.request.uri.path, ".sql") or ends_with(http.request.uri.path, ".bak")
```
- Action: **Block**. Place it first in the list.

It covers every hostname in the zone, including `local.` and the legacy
`listing.`. Neither site is WordPress.

**What it must never match:** the listing app's own map and editor files live under
`/assets/vendor/`, which is why only a path *starting* with `/vendor/` is blocked.
The marketing site's `contact.php` is why `.php` as a whole is not blocked. If a
future asset lands at a path this rule names, it will 403 at the edge with nothing
in the origin logs. Check *Security → Events* first.

**2. Verify.**
```bash
# Expect 403 from Cloudflare for probes...
curl -s -o /dev/null -w '%{http_code}\n' https://local.webscheduler.co.za/.env
curl -s -o /dev/null -w '%{http_code}\n' https://webscheduler.co.za/wp-login.php

# ...and 200 for the files that sit near the rule's edges
curl -s -o /dev/null -w '%{http_code}\n' https://local.webscheduler.co.za/assets/vendor/leaflet/leaflet.js
curl -s -o /dev/null -w '%{http_code}\n' https://local.webscheduler.co.za/health
curl -s -o /dev/null -w '%{http_code}\n' https://webscheduler.co.za/
```
*Security → Events* then shows the blocks as they happen.

**Rollback:** toggle the rule off. It takes effect within seconds.

## Hostname: local. and the legacy listing. host

WebScheduler Local is served from `local.webscheduler.co.za`. Until the move it
was `listing.webscheduler.co.za`, which Google had indexed and which owners, PDFs
and WhatsApp posts still link to. Only the hostname changed. Paths, slugs and
content are identical, so `listing.…/directory/global-tutors` maps to
`local.…/directory/global-tutors`.

**The app has one source of truth for its host: `app.baseURL` in
`/var/www/listing/directory-app/.env`.** Canonicals, `og:url`, JSON-LD, the
sitemap, the `Sitemap:` line in robots.txt and every email link come from it
through `base_url()`. Leave `app.allowedHostnames` empty, so that every
generated URL stays on that one host.

**The legacy vhost** (`apache/listing.webscheduler.co.za.conf`) does two things
and must never be removed, nor its DNS record:
- Every request gets **one** 301 to the same path and query on `local.`, with a
  trailing slash stripped in the same hop.
- `POST /payfast/notify` is **served**, not redirected. PayFast stores
  `notify_url` per subscription, so every Verified subscription created before
  the move keeps sending its monthly ITN to the old host. A redirected
  server-to-server POST is lost.

The exception tests `THE_REQUEST`, not `REQUEST_URI`. `.htaccess` internally
rewrites the path to `/index.php/payfast/notify`, and a `REQUEST_URI` test then
stops matching, so the ITN gets a 301 after all. That bug was caught in a local
Apache test before release. If anyone edits the rule, re-run the check in step 5
below.

### Cutover (zero downtime)

Run in this order. Steps 1–3 change nothing visitors see.

1. **Back up.**
   ```bash
   sudo cp -a /var/www/listing/directory-app/.env \
              /var/www/listing/directory-app/.env.bak-host-$(date +%Y%m%d)
   sudo cp -a /etc/apache2/sites-available/listing.webscheduler.co.za.conf{,.bak}
   ```
2. **Install the new vhost** next to the old one, after `git pull` in
   `/home/deploy/src`:
   ```bash
   S=/home/deploy/src/deploy
   sudo install -m 644 "$S/apache/local.webscheduler.co.za.conf" /etc/apache2/sites-available/
   sudo a2ensite local.webscheduler.co.za
   sudo apachectl configtest && sudo systemctl reload apache2
   ```
3. **Cloudflare.**
   - Add a DNS record `local`, a copy of the `listing` record (same A/AAAA), **proxied**.
   - Add the `local.` rows to the Access application (see *Admin behind
     Cloudflare Access*) **before step 4**. Otherwise `local.…/admin` sits behind
     the password alone.
   - Check *Rules* for anything scoped to `listing.` (Page, Cache, Configuration,
     Transform or Redirect rules) and copy it.

   Then check:
   ```bash
   curl -s https://local.webscheduler.co.za/health                 # ok
   curl -sI https://local.webscheduler.co.za/admin/login | grep -i '^location'   # cloudflareaccess.com
   ```
   Canonicals still say `listing.` at this point. That is expected.
4. **Switch.** Run the two halves seconds apart, in this order, so that neither
   host ever redirects to the other.
   ```bash
   # a) the app now generates local. URLs everywhere
   sudo sed -i "s|^app.baseURL.*|app.baseURL = 'https://local.webscheduler.co.za/'|" \
        /var/www/listing/directory-app/.env
   sudo systemctl reload php8.3-fpm
   sudo rm -f /var/www/listing/directory-app/writable/cache/directory_sitemap_xml

   # b) the old host now redirects
   sudo install -m 644 "$S/apache/listing.webscheduler.co.za.conf" /etc/apache2/sites-available/
   sudo apachectl configtest && sudo systemctl reload apache2
   ```
   Delete only that one cache file. `spark cache:clear` would also wipe the rate
   limits and the geocoding cache.
5. **Verify.**
   ```bash
   # one hop, same path and query
   curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' 'https://listing.webscheduler.co.za/directory/?q=physio'
   #   301 https://local.webscheduler.co.za/directory?q=physio
   curl -sL -o /dev/null -w '%{num_redirects}\n' https://listing.webscheduler.co.za/faq/   # 1

   # PayFast still reaches the app on the old host. Expect the app's rejection
   # (4xx), never 301, plus a new row in xs_directory_verification_itn_rejections.
   curl -s -o /dev/null -w '%{http_code}\n' -X POST -d x=1 https://listing.webscheduler.co.za/payfast/notify

   curl -s https://local.webscheduler.co.za/robots.txt | grep Sitemap        # local.
   curl -s https://local.webscheduler.co.za/sitemap.xml | grep -c listing\.  # 0
   curl -s https://local.webscheduler.co.za/ | grep -E 'rel="canonical"|og:url'  # local.
   ```
   Then, in a browser: an owner manage link, a signup through to the
   verification email (links say `local.`), the Verified checkout through to
   PayFast, a job post, the contact form and the newsletter form.
6. **Everything that points at the old host.**
   - Release the marketing site (its links already say `local.`) and purge its
     HTML in Cloudflare.
   - Update the Mautic form 2 post-submit redirect.
   - Update `directory.handoffUrl` in the WebScheduler app.
   - Move the uptime monitor to `local.…/health`, and add a second check that
     `listing.…/` returns 301.
   - Update the social bios.
   - Search Console: add `local.` and submit its sitemap. In the **old** property,
     run *Settings → Change of address*. Never delete the old property.

Signed-in owners and admins are signed out once, because cookies are
host-only. Their light or dark theme choice also resets once.

### Rollback

No downtime, and the old host stays up throughout:
```bash
sudo install -m 644 "$S/apache/listing.webscheduler.co.za.conf.serve" \
     /etc/apache2/sites-available/listing.webscheduler.co.za.conf
sudo cp -a /var/www/listing/directory-app/.env.bak-host-<date> /var/www/listing/directory-app/.env
sudo systemctl reload php8.3-fpm
sudo rm -f /var/www/listing/directory-app/writable/cache/directory_sitemap_xml
sudo apachectl configtest && sudo systemctl reload apache2
```
If Google has already crawled `local.`, keep its DNS record and make its vhost
a 301 back to `listing.` (the mirror image of the legacy rule), so signals
consolidate again. Also cancel the Change of address in Search Console. Do
not flip back and forth more than once.
