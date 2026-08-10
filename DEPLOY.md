# Deploying to production (cPanel)

This repo ships **two deployables**:

| App | What | Production URL | Docroot |
|---|---|---|---|
| **Marketing site** | Static HTML built from `marketing-site/` → `dist/site/` | `https://webscheduler.co.za` | your main `public_html/` |
| **Directory app** | CI4 + MySQL app (repo root: `app/`, `public/`, …) | `https://directory.webscheduler.co.za` | a subdomain docroot → the app's `public/` |

The **developer portal** (`/developer`) is built and deployed from the *other* repo
(`niloc95/xscheduler_ci4`, `npm run docs:build`). The marketing pages link to it at
`/developer/`.

Auto-deploy is handled by **`.github/workflows/deploy.yml`** (GitHub Actions → FTPS).
Everything below is the one-time setup.

---

## 1. cPanel: subdomain + database

1. **Subdomain** — cPanel → *Domains* → create `directory.webscheduler.co.za`. Set its
   **Document Root** to the folder that will hold the app's `public/`, e.g.
   `/home/<cpuser>/directory_app/public`.
2. **Database** — cPanel → *MySQL Databases*:
   - Create DB (e.g. `<cpuser>_directory`)
   - Create a user + strong password; add the user to the DB with **All Privileges**.
   - Note the final names (cPanel prefixes them with `<cpuser>_`).

## 2. Generate the shared handoff secret (the cross-system "connection")

Run once locally and keep the value safe:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Use the **same value** in three places:
- Directory app `.env` → `directory.prefillSecret`
- Each WebScheduler app `.env` → `directory.handoffSecret`
- (WebScheduler app also) `directory.handoffUrl = 'https://directory.webscheduler.co.za/list-your-practice/prefill'`

That's what lets "Add your business" pre-fill across the two systems. The payload
is still email-verified on this side, so the secret is tamper-evidence, not auth.

## 3. Directory app `.env` on the server

Copy `.env.production.example` → `.env` in the app root and fill it in (DB from step 1,
`directory.prefillSecret` from step 2, a strong `directory.adminPassword`, SMTP creds,
`app.baseURL = 'https://directory.webscheduler.co.za/'`). **Never commit `.env`** — the
deploy explicitly skips it.

### Verified Business badge (PayFast)

**Verification works without PayFast.** Document upload, the admin review queue, the badge
and the public `/verified` page are all live as soon as you deploy — a business can pay you
by EFT and you award the badge with the "Activate manually" button on the review queue,
choosing how many months to add. PayFast only adds *card* payment: the checkout page and
the owner's self-serve cancel button.

**The price and the on/off switch are editable from the admin panel** at
`/admin/settings`, so a price change does not need a server login. What is set there is
stored in the database and wins over the two keys below, which become the fallback used
until someone saves on that page. The settings page names which source the live value is
coming from, so a stale `.env` line never looks like a bug.

Everything else stays in `.env` — deliberately, in the case of the PayFast credentials.
The passphrase is a signing secret, and a value editable through a web form means one
stolen admin session could re-key payments rather than merely change a price.

```
directory.verifiedBadgeEnabled = true           # fallback; the admin panel overrides it

directory.payfastMerchantId    = '...'          # from the PayFast dashboard
directory.payfastMerchantKey   = '...'
directory.payfastPassphrase    = '...'          # set the SAME value in PayFast → Settings
directory.payfastSandbox       = false          # ONLY in production
directory.verifiedMonthlyAmount = '29.99'       # fallback; a point, not a comma
```

Five things worth knowing:

1. **The passphrase is not optional in practice.** The merchant id and key both travel in a
   form the buyer can read. The passphrase is the only part of the signature they cannot
   see. Without one, a forged payment notification is much easier to construct.
2. **`payfastSandbox` defaults to `true`**, so a half-configured environment takes no real
   money rather than the reverse. That also means forgetting to set it to `false` in
   production means you take *no* money at all and nothing complains — check the admin
   Status page, which shows the mode and warns while it is on sandbox.
3. **The notify URL must be publicly reachable**: PayFast posts to
   `https://<your-domain>/payfast/notify` server-to-server. It is exempt from CSRF and the
   honeypot filter by necessity, and defended instead by four checks in
   `App\Controllers\PayFastNotify` (signature, source IP, a confirmation POST-back to
   PayFast, and an amount match). Nothing on localhost will ever receive one — use a tunnel
   for testing.
4. **The self-serve cancel button uses PayFast's Subscriptions API**, which is a separate
   integration from the checkout with its own signature rule (alphabetical, not submission
   order — see `PayFast::apiSignature()`). It has **not been exercised against PayFast**;
   confirm it in the sandbox before you rely on it. If the API call fails, the owner is
   told plainly that it failed and you get an email to cancel it by hand — nothing is ever
   recorded as cancelled unless PayFast confirmed it.
5. **Write the price with a point, not a comma.** R29,99 is how it is written in South
   Africa and `29,99` is how PHP quietly reads it as 29.0 — so the config getter converts a
   comma to a point rather than letting that happen. Changing the price later only affects
   new applications: existing subscribers keep the amount stored on their verification row,
   which is what their renewals are validated against.

### Cron: the verification sweep

Add one daily job:

```
0 3 * * * cd /home/<cpuser>/<appdir> && php spark directory:verifications:sweep --quiet
```

It moves expired subscriptions to `lapsed` and emails renewal reminders three days ahead.

It is deliberately **not** load-bearing for correctness: the public badge is hidden by a
date comparison at render time, so a business whose payments stop loses the badge on the
right day whether or not this job ever runs. Missing a day costs a late reminder and a
briefly stale admin queue, nothing more.

### Mapping

The whole mapping stack is open-source: **Leaflet** for the maps, **OpenStreetMap** for
the data, **Nominatim** for geocoding and reverse geocoding. There is no API key to
obtain, no billing account, and nothing is charged per request. Nothing to configure here
beyond the tile source below.

### First deploy

After the first deploy, run `php spark directory:geocode` on the server to fill in
coordinates for listings that have none. It also confirms the host permits outbound
HTTPS: if it doesn't, every lookup fails and listings silently keep no coordinates.

Each listing records a `geocoding_status` (`pending`, `ok`, `failed`, `manual`), so a
batch that failed during an outage can be retried on its own with
`php spark directory:geocode --status failed`. Note the space — CodeIgniter's CLI reads
`--option value`, and `--option=value` is silently ignored.

Nominatim is rate-limited to about one request a second and the lookup tries several
progressively looser queries per listing, so a large backfill is slow by design. It is
free and donation-funded; let it grind rather than parallelising it.

Maps draw their tiles from **CARTO** (Voyager basemap) — the
same OpenStreetMap data, served from a CDN built for embedding. It deliberately does not
use `tile.openstreetmap.org`: those servers are donation-funded, the OSM usage policy
discourages commercial use, and being blocked shows up as a blank grey map with no
warning. Nothing to configure — but if you want a different look or host (MapTiler,
Stadia, Thunderforest all have free tiers), it is `directory.mapTileUrl` and
`directory.mapTileAttribution` in `.env`, no code change. Attribution is mandatory and
must credit both OpenStreetMap and the tile host.

## 4. GitHub → host auto-deploy (FTPS)

1. cPanel → *FTP Accounts* → create an FTP account (or use the main one). Note host,
   username, password.
2. GitHub → repo *Settings → Secrets and variables → Actions* → add:

   | Secret | Value |
   |---|---|
   | `FTP_SERVER` | your FTP host (e.g. `ftp.webscheduler.co.za`) |
   | `FTP_USERNAME` | FTP user |
   | `FTP_PASSWORD` | FTP password |
   | `FTP_MARKETING_DIR` | server path for the marketing site, e.g. `/public_html/` |
   | `FTP_DIRECTORY_DIR` | server path for the directory app, e.g. `/directory_app/` |
   | `SSH_HOST` | server SSH hostname |
   | `SSH_USERNAME` | SSH user (usually your cPanel user) |
   | `SSH_PRIVATE_KEY` | private key for that user (add the matching public key via cPanel → *SSH Access*) |
   | `SSH_PORT` | SSH port, if not 22 (optional) |
   | `SSH_DIRECTORY_APP_PATH` | absolute filesystem path to the app root, e.g. `/home/<cpuser>/directory_app` — same folder `FTP_DIRECTORY_DIR` points at, but as an SSH path rather than an FTP path |

   (`FTP_DIRECTORY_DIR` is the app root — the subdomain docroot points at
   `<that>/public`.)
3. Push to `main` (or run the workflow manually). The Action:
   - builds `dist/site/` and uploads it to `FTP_MARKETING_DIR`;
   - runs `composer install --no-dev` and uploads the CI4 app to `FTP_DIRECTORY_DIR`
     (excluding `.env`, `writable/` runtime, and the marketing/node files);
   - SSHes in and runs `php spark migrate --all`, so schema changes ship on every
     deploy, not just the first one.

> First deploy of a large `vendor/` over FTP is slow; subsequent deploys only sync
> changed files.

## 5. First-run only: seed the category taxonomy

Migrations now run automatically on every deploy (step 4). Seeding is still a
one-time manual step — open **cPanel → Terminal** (or SSH) after the first deploy
and run in the app root:

```bash
php spark db:seed DirectoryCategoriesSeeder
```

## 6. Marketing site — one manual step, then it publishes itself

The site is static apart from **one file**: `contact.php`, the demo-request endpoint
behind the form on the contact page. Once `FTP_MARKETING_DIR` is set the site publishes
on every push, but the endpoint needs its own configuration placed by hand, once —
nothing in the repo or the deploy bundle ever contains it.

Preview a build locally with `npm run site:build` then `npm run site:preview` (a PHP
server — `python3 -m http.server` would hand out `contact.php` as a text download).

### 6a. Requirements on the host

- **PHP 8.1+ for the main domain.** cPanel → *MultiPHP Manager*. The subdomain's version
  is set separately, so a healthy directory app tells you nothing about `webscheduler.co.za`.
- **A real mailbox to send from**, e.g. `no-reply@webscheduler.co.za`. The directory
  app's mailbox can be reused; a dedicated one isolates reputation better.

### 6b. The config file — ABOVE the docroot

```
/home/<cpuser>/.ws-contact.env       ← chmod 600, place by hand, never deployed
/home/<cpuser>/public_html/contact.php
```

`contact.php` reads `__DIR__ . '/../.ws-contact.env'`, which resolves exactly there.
Copy `marketing-site/.env.example` as the template and fill it in.

Above the docroot, not in it. In `public_html` the file would be one PHP misconfiguration
away from being served to anyone as plain text — and protecting it would need a root
`.htaccess`, which the deploy would then overwrite along with cPanel's AutoSSL redirect
and MultiPHP handler, taking the whole site down rather than just the form.

Generate the token secret with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Then create the rate-limit directory, also outside the docroot, and point
`RATE_LIMIT_DIR` at it:

```bash
mkdir -m 700 /home/<cpuser>/ws-contact-state
```

Leave it unset and the endpoint falls back to the system temp directory, which on shared
hosting may be swept or per-request — the limiter then quietly does nothing. It logs the
fallback, so check for that line if submissions stop being throttled.

> **Both paths start with a dot.** hPanel's file manager and FileZilla hide dotfiles by
> default (FileZilla: *Server → Force showing hidden files*). Turn that on first, or you
> will "upload" the file into thin air and see a 503 with no obvious cause.

### 6c. Smoke test after the first deploy

```bash
curl -sI  https://webscheduler.co.za/contact.php          # 303, X-Robots-Tag, text/html
curl -s  'https://webscheduler.co.za/contact.php?token=1' # {"ok":true,...,"token":"v1..."}
curl -sI  https://webscheduler.co.za/.ws-contact.env      # 404 — non-negotiable
```

A `Content-Type: text/plain` on the first call means PHP is **not** executing and the
source is being served — stop and fix MultiPHP before going further.

Then submit the real form in a browser and confirm the mail arrives at
`directory.adminEmail` (**check the spam folder**), that its headers show SPF/DKIM/DMARC
`pass`, and that pressing reply addresses the visitor rather than `no-reply@`. Submit
four times in a row: the fourth should be refused, and `ws-contact-state/` should now
contain `rl-*.json` files — which is how you know the limiter found its real directory.

On the **second** deploy, re-check that `.ws-contact.env` and the state directory are
still there and the form still works. That is the assertion that the FTP sync leaves
them alone.

If the endpoint is misconfigured it answers 503 with a message pointing the visitor at
`info@webscheduler.co.za` and logs the missing key names — deliberately loud, because a
contact form that silently swallows enquiries is worse than one that is visibly down.

## 7. Hardening before you announce it

- **HTTPS**: enable AutoSSL for both the domain and the subdomain; force HTTPS.
- **CSRF**: already global (`Config\Filters`), with an `except` only for `csp-report` —
  browsers can't send a token with a violation report. Nothing to switch on.
- **Admin**: generate the password hash with `php spark directory:adminhash` and set
  `directory.adminPasswordHash`. Do **not** use `directory.adminPassword` — it stores the
  password in cleartext and takes a deprecated code path. `/admin` is `noindex`.
- **Permissions**: ensure `writable/` is writable (755/775) on the server.
- **CSP**: ships in report-only mode. Watch `writable/logs/` for `CSP violation` lines for
  a week, then set `$reportOnly = false` in `Config\ContentSecurityPolicy` to enforce.
- Confirm the WebScheduler app's `directory.handoffUrl` points at the live subdomain.

## 8. Monitoring — do this before you announce it, not after

Almost every way this app breaks still returns HTTP 200. Mail can stop entirely, the cache
can become unwritable and silently disable every rate limit, uploads can start failing —
and the homepage renders fine throughout. A monitor pointed at `/` will tell you none of it.

`GET /health` exists for this. It returns **200 `{"status":"ok"}`** when the database,
cache, disk and mailer are all answering, and **503 `{"status":"degraded"}`** when any of
them isn't.

1. Set `directory.healthToken` in `.env` to a random value
   (`php -r "echo bin2hex(random_bytes(16));"`). Without the token `/health` returns only
   ok/degraded; with `?token=…` it returns a per-check breakdown. Keep the token out of the
   monitoring service — the status code is all it needs.
2. Point a free uptime monitor (UptimeRobot, Better Stack, Cronitor) at
   `https://directory.webscheduler.co.za/health`, 5-minute interval, alerting on any
   non-2xx.
3. When it alerts, open `/health?token=…` yourself to see which check failed.

**Why not email alerts:** the single most likely thing to break is outbound mail, and a
system that emails you when email is broken tells you nothing. The admin dashboard also
shows a banner when sends are failing, but that only helps if you happen to log in.

## 9. Backups — verify what you actually have

Two things exist in exactly one place. Neither is in git:

| Asset | Where it lives | If the disk dies |
|---|---|---|
| Code | git + GitHub | fine |
| Schema | migrations | `php spark migrate` rebuilds it |
| Category taxonomy | `DirectoryCategoriesSeeder` | reseeded |
| **Listing data** | the production database, only | **gone** |
| **Uploaded logos and photos** | `public/assets/listings/` on the server, only | **gone** |
| **Verification documents** | `writable/verification/` on the server, only | **gone** |

Uploads are safe from *deploys* — the FTP action excludes `public/assets/listings/**` and
`writable/verification/**` deliberately, so a release never touches them. That is not a
backup.

⚠️ **Verification documents are different from everything else in that table.** They are
company registration certificates and copies of owners' ID documents. Back them up if you
back them up *encrypted and off-host*, and think about where the copies end up: a backup of
this directory is a file full of other people's identity documents. Losing them is
recoverable — you can ask the affected owners to re-upload — so if you are not confident
the backup itself will be handled properly, **not backing them up is a defensible choice**.
Nothing else on this list has that property.

Do these three things:

1. **Check hPanel** for what your plan includes and, more importantly, how far back it
   goes. Backups are a plan feature on Hostinger, not a given, and the retention window is
   the number that matters.
2. **Confirm it covers both** the database *and* `public/assets/listings/`. A
   database-only backup restores a directory in which every listing has a broken logo.
3. **Do one restore** into a scratch database before you need it. An untested backup is a
   belief, not a backup.

If hPanel covers neither, the fallback is a cron'd `mysqldump` plus a `tar` of the uploads,
written somewhere off this host.

## Smoke test

1. `https://webscheduler.co.za` loads; "Find a provider" → the directory.
2. `https://directory.webscheduler.co.za/list-your-practice` → submit → verify email → published.
3. In a WebScheduler install, **Settings → Integrations → List your practice** →
   lands on the directory signup **pre-filled** (confirms the shared secret matches).
