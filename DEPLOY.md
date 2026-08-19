# Deploying to production (Hostinger / hPanel) — superseded

> ## ⚠️ Production is moving to AWS Lightsail
>
> **For the new stack, provisioning, and the cutover runbook, read
> [`deploy/README.md`](deploy/README.md).** For a routine release, use the
> **`deploy-production` skill**.
>
> This file is the Hostinger shared-hosting record. It stays accurate for the
> environment that is live **until the DNS cutover completes**, and is worth keeping
> after that as the rollback target — rollback is repointing two Cloudflare A records
> back here, which only works while this environment still exists.
>
> What has already changed in the repo, and therefore no longer matches this file:
>
> | This document says | Now |
> |---|---|
> | FTPS upload via `SamKirkland/FTP-Deploy-Action` | `rsync` over SSH |
> | The `directory` job is disabled; deploy by hand | Both jobs deploy automatically |
> | Migrations are a manual hPanel terminal step | Run automatically after the rsync |
> | `FTP_SERVER` / `FTP_USERNAME` / `FTP_PASSWORD` / `FTP_MARKETING_DIR` | `SSH_HOST` / `SSH_USER` / `SSH_PRIVATE_KEY` / `SSH_KNOWN_HOSTS` |
> | Never set `FTP_DIRECTORY_DIR` | The secret is no longer read by anything |
> | Backups rely on the Hostinger plan | Lightsail snapshots + nightly `mysqldump` |
>
> The workflow's deploy steps are guarded on `SSH_HOST`, so until that secret is set it
> builds, runs every guard, and ships nothing — pushing to `main` before the instance
> exists is safe, it just does not deploy anywhere.

This repo ships **two deployables**:

| App | What | Production URL | Docroot |
|---|---|---|---|
| **Marketing site** | Static HTML built from `marketing-site/` → `dist/site/` | `https://webscheduler.co.za` | your main `public_html/` |
| **Directory app** | CI4 + MySQL app (repo root: `app/`, `public/`, …) | `https://listing.webscheduler.co.za` | a subdomain docroot → the app's `public/` |

The **developer portal** (`/developer`) is built and deployed from the *other* repo
(`niloc95/xscheduler_ci4`, `npm run docs:build`). The marketing pages link to it at
`/developer/`.

Auto-deploy is handled by **`.github/workflows/deploy.yml`** (GitHub Actions → FTPS).

> **Both apps are already deployed and serving.** This document is the *setup*
> reference — subdomain, database, `.env`, secrets, hardening — and most of it has
> already been done once. For a routine release (what ships automatically, what is a
> manual gate, how to confirm a deploy landed, how to roll back) use the
> **`deploy-production` skill**, not this file.
>
> Sections headed "first deploy" or "first-run only" have been completed for the
> current production environment. They stay here because they are what you would need
> to stand the app up somewhere else.

---

## 1. hPanel: subdomain + database

1. **Subdomain** — hPanel → *Domains → Subdomains* → create `listing.webscheduler.co.za`. Set its
   **Document Root** to the folder that will hold the app's `public/`, e.g.
   `/home/<cpuser>/directory_app/public`.
2. **Database** — hPanel → *Databases → Management*:
   - Create DB (e.g. `<cpuser>_directory`)
   - Create a user + strong password; add the user to the DB with **All Privileges**.
   - Note the final names (the panel prefixes them with `<cpuser>_`).

## 2. Generate the shared handoff secret (the cross-system "connection")

> **Not implemented — this step configures nothing.** The receiving endpoint
> (`/add-listing/prefill`) does not exist in the directory app: there is no route, no
> controller, and no `prefillSecret` read anywhere in `app/`. Both paths return 404,
> and did so under the old `/list-your-practice` name too — this is not fallout from
> the URL rename. `directory.prefillSecret` is set on the live server and is inert.
>
> Keep the secret provisioned if you intend to build the handoff, but do not expect
> "List your business" to pre-fill across the two systems today, and do not treat a
> 404 here as a broken deploy. Delete this section, the `.env` key, and the
> verification line in "After the first deploy" if the feature is abandoned.

Run once locally and keep the value safe:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Use the **same value** in three places:
- Directory app `.env` → `directory.prefillSecret`
- Each WebScheduler app `.env` → `directory.handoffSecret`
- (WebScheduler app also) `directory.handoffUrl = 'https://listing.webscheduler.co.za/add-listing/prefill'`
  > Path renamed from `/list-your-practice` when the URL was made generic. The old
  > path 301s, but only as an exact match — `/list-your-practice/prefill` was never
  > redirected and never existed.

The payload would still be email-verified on this side, so the secret is
tamper-evidence, not auth.

## 3. Directory app `.env` on the server

Copy `.env.production.example` → `.env` in the app root and fill it in (DB from step 1,
`directory.prefillSecret` from step 2, SMTP creds,
`app.baseURL = 'https://listing.webscheduler.co.za/'`). **Never commit `.env`** — the
deploy explicitly skips it.

Four things are easy to carry over from a development env file by accident. Check each
before the first boot:

| Key | Requirement |
|---|---|
| `encryption.key` | **Must be set.** Generate on the server: `php spark key:generate` |
| `directory.adminPasswordHash` | **Set this**, from `php spark directory:adminhash` |
| `directory.adminPassword` | **Must be absent.** It stores the password in cleartext and takes a deprecated code path — setting the hash is not enough if this one is still present |
| `database.tests.*` | **Must be absent.** Test-database credentials have no business on a production host |
| `CI_ENVIRONMENT` | `production` |

The last two are the ones that survive a copy-paste from a working local file and
never announce themselves.

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

> **To launch without card payments, leave the three `payfast*` credential keys
> empty.** `PayFast::isConfigured()` gates the signup block, the manage panel and the
> checkout on all three being non-empty, so an unconfigured deployment hides card
> payment cleanly rather than showing a button that fails. The badge, the document
> upload, the admin review queue and "Activate manually" all keep working — you take
> EFT and award the badge by hand. This is the recommended first-launch posture,
> because it removes the entire payment-failure surface on the day you have least
> attention to spare, and turning it on later is an `.env` edit with no redeploy.

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

1. hPanel → *Files → FTP Accounts* → create an FTP account (or use the main one). Note host,
   username, password.
2. GitHub → repo *Settings → Secrets and variables → Actions* → add:

   **Required** — without these the run fails immediately with
   `Error: Input required and not supplied: server`, before any connection is opened:

   | Secret | Value |
   |---|---|
   | `FTP_SERVER` | your FTP host (e.g. `ftp.webscheduler.co.za`) |
   | `FTP_USERNAME` | FTP user |
   | `FTP_PASSWORD` | FTP password |
   | `FTP_MARKETING_DIR` | server path for the marketing site, e.g. `/public_html/` |
   | `FTP_DIRECTORY_DIR` | server path for the directory app, e.g. `/directory_app/` |

   ⚠️ **`FTP_DIRECTORY_DIR` is the app ROOT, not the docroot.** The subdomain's
   document root points at `<that>/public`, one level deeper. Setting this to the
   `public/` folder uploads `app/`, `vendor/` and `writable/` *inside* the docroot,
   where they are web-reachable — a serious exposure, not just a broken deploy.

   **Optional** — each is individually guarded, so leaving one unset skips its step
   and prints a warning instead of failing the run:

   | Secret | Value |
   |---|---|
   | `CF_ZONE_ID` | Cloudflare zone id (*Overview*) — enables the post-deploy cache purge |
   | `CF_API_TOKEN` | Cloudflare token scoped to *Zone → Cache Purge → Purge* |
   | `SSH_HOST` | server SSH hostname — **the switch that turns on automatic migrations** |
   | `SSH_USERNAME` | SSH user (usually your hosting account user) |
   | `SSH_PRIVATE_KEY` | private key for that user (add the matching public key via hPanel → *SSH Access*) |
   | `SSH_PORT` | SSH port, if not 22 |
   | `SSH_DIRECTORY_APP_PATH` | absolute filesystem path to the app root, e.g. `/home/<cpuser>/directory_app` — same folder `FTP_DIRECTORY_DIR` points at, but as an SSH path rather than an FTP path |
   ⚠️ **`FTP_DIRECTORY_DIR` is deliberately not in either table — do not set it.**
   See "Deploying the directory app" below.

3. Push to `main` (or run the workflow manually). The Action:
   - builds `dist/site/`, uploads it to `FTP_MARKETING_DIR`, then purges Cloudflare;
   - does **not** deploy the directory app — that job is disabled (see below);
   - runs `php spark migrate --all` over SSH **if `SSH_HOST` is set** — otherwise it
     announces that migrations did *not* run (see below).

> First deploy of a large `vendor/` over FTP is slow; subsequent deploys only sync
> changed files.

### Deploying the directory app — manual, and why

**The `directory` job in `deploy.yml` is disabled.** It uploads the repo root and
assumes the docroot is `<FTP_DIRECTORY_DIR>/public`. Production is laid out the other
way — the split bundle `npm run list:build` produces:

```
domains/listing.webscheduler.co.za/
├── directory-app/     ← app code, ABOVE the web root (not web-reachable)
└── public_html/       ← docroot; index.php requires ../directory-app/app/Config/Paths.php
```

The two are incompatible. Because the workflow never completed a run, the mismatch was
never discovered — production has always been deployed by hand.

**Do not set `FTP_DIRECTORY_DIR` to "turn the job on".** The FTP credentials are shared
with the marketing job, so once those exist, an unguarded upload step would run with an
empty `server-dir` and sync the repo to the FTP account root. The upload step has been
removed and a tripwire fails the job if that secret appears, but the underlying point
stands: the job needs rewriting, not enabling.

To release the directory app today:

```bash
npm run list:build          # → dist/listing/{directory-app,docroot}/ + DEPLOY.txt
```

Upload the two folders to their **separate** destinations, and preserve the exclusions
below — they are what stops a release from destroying user data:

| Never overwrite | Holds |
|---|---|
| `directory-app/.env` | the server's own configuration |
| `public_html/assets/listings/**` | owner-uploaded logos and gallery photos |
| `directory-app/writable/verification/**` | uploaded ID and registration documents |

Nothing in that table exists anywhere else — not in git, not in the build. Overwriting
it is unrecoverable.

Then run migrations from **hPanel → Advanced → Terminal**:

```bash
cd ~/domains/listing.webscheduler.co.za/directory-app
php spark migrate --all
```

**To re-enable automatic deploys**, rewrite the job to run `npm run list:build` and FTP
`dist/listing/directory-app/` and `dist/listing/docroot/` to their two destinations,
then delete the tripwire step.

### Migrations without SSH

Shared hosting does not always include key-based SSH. The `migrate` job handles both
cases rather than assuming:

| `SSH_HOST` | What happens |
|---|---|
| set | `php spark migrate --all` runs automatically after every upload |
| unset | The step is skipped and the job writes a **⚠️ Migrations did not run** notice into the run summary, naming the command to run by hand |

This guard is not cosmetic. `appleboy/ssh-action` errors on an empty host, which
fails the whole workflow **even when both uploads succeeded** — a deploy that shipped
correctly still shows red, and an all-red run list stops being read. That is precisely
how a batch of commits can sit unreleased without anyone noticing.

When it is skipped, run this from **hPanel → Advanced → Terminal** in the app root:

```bash
php spark migrate --all
```

Do it **promptly**. The FTP upload ships schema-dependent code whether or not the
schema has caught up, so between the upload and the migration the app is running new
code against an old database. That surfaces as a 500 on whichever page touches the new
column — not as an obvious outage.

## 4b. Cloudflare — in front of both hostnames

DNS for `webscheduler.co.za` is on Cloudflare (`tadeo`/`gail.ns.cloudflare.com`) and
both `webscheduler.co.za` and `listing.webscheduler.co.za` are **proxied** (orange
cloud). Nothing in this repo configures that, and it is load-bearing in three ways.

### ⚠️ The hostname is `listing.`, not `directory.`

The app serves from **`listing.webscheduler.co.za`**. The marketing site links to it,
`app.baseURL` is set to it, and the sitemap publishes it.

A `directory.webscheduler.co.za` record also exists in Cloudflare with **nothing behind
it** — no hPanel subdomain, so it answers `526` to everything. It is a leftover, not a
staging environment. Either delete the record or point it at the app; leaving it live
means anyone following an old link gets a Cloudflare error page, and anyone debugging
gets a false alarm. This document previously named it as the production hostname, which
is how that confusion started.

### Origin certificate — HTTP 526

`526 Invalid SSL certificate` means Cloudflare reached the origin but could not
validate its certificate. On a proxied subdomain this is usually a chicken-and-egg:
hPanel's AutoSSL validates over HTTP-01, and the orange cloud intercepts the
validation request, so the certificate is never issued. It is also exactly what a DNS
record with no subdomain behind it looks like — check that first.

Break the cycle in this order:

1. hPanel → *Domains → Subdomains* — confirm the subdomain exists and its document
   root is the app's `public/`.
2. Cloudflare → *DNS* — set the record to **DNS only (grey cloud)**.
3. hPanel → *SSL* — issue the certificate. Wait for it to report issued.
4. Verify against the origin while still grey-clouded:
   ```bash
   curl -sI https://listing.webscheduler.co.za    # anything but 526
   ```
   A 403 or 404 from an empty docroot is a **success** here — it means TLS terminated.
5. Cloudflare → *DNS* — set the record back to **Proxied (orange)**.
6. Cloudflare → *SSL/TLS → Overview* — set **Full (strict)**.

Do not leave the zone on **Flexible**. Cloudflare would speak plain HTTP to an origin
that redirects to HTTPS, and the result is a redirect loop, not a working site.

### Cache — a green deploy that changes nothing

The apex is proxied and the built asset filenames are not content-hashed, so a
successful FTP upload can still leave every visitor on the previous HTML and CSS. The
deploy goes green, the site looks unchanged, and the time goes into debugging a bug
that is not there.

`deploy.yml` purges automatically when `CF_ZONE_ID` and `CF_API_TOKEN` are set (token
scope: *Zone → Cache Purge → Purge*). Leave them unset and the workflow prints a
warning telling you to purge by hand at *Caching → Configuration → Purge Everything* —
it does not fail the run.

### Debugging through the proxy

`curl -I` against a proxied hostname reports Cloudflare's response, not your origin's.
`cf-ray` in the headers confirms you are talking to the edge. To test the origin
itself, either grey-cloud the record temporarily or resolve past the proxy:

```bash
curl -sI https://listing.webscheduler.co.za --resolve listing.webscheduler.co.za:443:<origin-ip>
```

## 5. First-run only: seed the category taxonomy

Migrations run automatically on every deploy **when SSH is configured** (step 4);
otherwise run them by hand first. Seeding is a one-time manual step either way — open
**hPanel → Advanced → Terminal** (or SSH) after the first deploy and run in the app
root:

```bash
php spark migrate --all                      # skip if the migrate job already ran it
php spark db:seed DirectoryCategoriesSeeder
```

Without the seeder the category taxonomy is empty, so the homepage and the browse page
render with no categories and the signup form offers nothing to pick. It looks like a
broken deploy; it is a missing one-liner.

## 6. Marketing site — one manual step, then it publishes itself

The site is static apart from **one file**: `contact.php`, the demo-request endpoint
behind the form on the contact page. Once `FTP_MARKETING_DIR` is set the site publishes
on every push, but the endpoint needs its own configuration placed by hand, once —
nothing in the repo or the deploy bundle ever contains it.

> ⚠️ **`contact.php` and `privacy.html` must ship together.** The privacy policy
> describes how the form handles personal information, and the two versions describe
> two different systems: the older text says *"the form does not send anything to
> us… nothing is posted to a server"*, which is true of the `mailto:` form and
> **false** of the SMTP endpoint. Deploying the endpoint while an older policy is live
> means publishing an inaccurate description of your own processing — including the
> retained sender IP and the consent basis, which the current text covers and the old
> text does not. A normal full deploy ships both from the same build, so this only
> becomes a hazard if you hand-upload `contact.php` on its own. Don't.

Preview a build locally with `npm run site:build` then `npm run site:preview` (a PHP
server — `python3 -m http.server` would hand out `contact.php` as a text download).

### 6a. Requirements on the host

- **PHP 8.1+ for the main domain.** hPanel → *Advanced → PHP Configuration*. The subdomain's version
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
`.htaccess`, which the deploy would then overwrite along with the panel's AutoSSL redirect
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
- **CSP**: **enforcing**, not report-only — `$reportOnly = false` in
  `Config\ContentSecurityPolicy`. Nothing to switch on; the switch has already been
  thrown. A violation now means a resource was *blocked*, not merely noticed, so the
  first deploy is the first time this policy meets real traffic. Watch
  `writable/logs/` for `CSP violation` lines for the first week and treat any of them
  as a live breakage rather than noise. See "CSP: what breaks first" below.
- ~~Confirm the WebScheduler app's `directory.handoffUrl` points at the live
  subdomain.~~ Nothing to confirm — the prefill endpoint is unimplemented and
  returns 404. See step 2.

### CSP: what breaks first

The policy is built partly at runtime, so a production `.env` can change it without a
code change. Two directives are worth knowing before you debug a blank panel:

- **`img-src` includes the map tile host, derived from `directory.mapTileUrl`** by
  `ContentSecurityPolicy::__construct()`. Point that key at a different tile provider
  and the policy follows it — but a **blank grey map** is the symptom of getting it
  wrong, and it looks exactly like a tile-server outage. Check the log before assuming
  CARTO is down.
- **`form-action` lists both PayFast hosts** because `payfastSandbox` is env-switchable
  and the policy is built before the request knows which one it will use. It does *not*
  fall back to `default-src`; if you ever add another off-site form POST, it must be
  added here or the submit dies silently at the last click.

`style-src` is `'self'` with no `'unsafe-inline'`, and every inline `<style>` the app
emits carries the `{csp-style-nonce}` placeholder. If you add an inline style or an
inline event handler (`onclick=`), it will be blocked — use the nonce or move it to a
file.

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
   `https://listing.webscheduler.co.za/health`, 5-minute interval, alerting on any
   non-2xx.

   ⚠️ **Configure the monitor to send `GET`, not `HEAD`.** Routes are registered with
   `$routes->get(...)`, which does not match `HEAD`, so a HEAD request to `/health`
   returns **404** from a completely healthy app. Several monitoring services default
   to HEAD because it is cheaper. Set the method explicitly and confirm the first check
   passes before you walk away, or you will have built an alarm that is always ringing
   — which is worse than no alarm, because you will learn to ignore it.

   Verify by hand, which is also why `curl -I` is the wrong tool here:

   ```bash
   curl -s  -o /dev/null -w 'GET  %{http_code}\n' https://listing.webscheduler.co.za/health
   curl -sI -o /dev/null -w 'HEAD %{http_code}\n' https://listing.webscheduler.co.za/health
   ```

   If you would rather fix the cause than configure around it, register the health
   route with `$routes->match(['get', 'head'], 'health', 'Health::index')` — but do that
   as its own change with its own verification, not as part of a release.
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
2. `https://listing.webscheduler.co.za/add-listing` → submit → verify email → published.
3. In a WebScheduler install, **Settings → Integrations → List your practice** →
   lands on the directory signup **pre-filled** (confirms the shared secret matches).
