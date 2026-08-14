---
name: deploy-production
description: Release the WebScheduler marketing site and/or the directory app to production — what ships automatically, what is a manual gate, how to verify a deploy actually landed, and how to roll back. Use when deploying, cutting a release, shipping a migration, debugging a red or misleadingly-green deploy run, or investigating why a change is not visible on the live site.
---

# Releasing to production

| App | Live at |
|---|---|
| Marketing site | `https://webscheduler.co.za` |
| Directory app | `https://listing.webscheduler.co.za` |

**The directory hostname is `listing.`, not `directory.`** A
`directory.webscheduler.co.za` DNS record exists in Cloudflare with nothing behind it
and answers `526` to everything. It is a leftover. Probing it and concluding the app is
down is a mistake that has already been made once — check `listing.` first, and treat
`/health` as the source of truth.

`.github/workflows/deploy.yml` fires on every push to `main`:

| Job | Does | Automatic? |
|---|---|---|
| `marketing` | builds `dist/site/`, FTPs it to `FTP_MARKETING_DIR`, purges Cloudflare | **yes** |
| `directory` | nothing — **disabled**, see below | no |
| `migrate` | `php spark migrate --all` over SSH — or announces that it didn't | only with `SSH_HOST` |

**Only the marketing site auto-deploys.** The directory app is released by hand.

`DEPLOY.md` in the repo root is the full setup reference. This skill is the recurring
release procedure.

## The directory app is deployed manually

The `directory` job is disabled because it uploads the repo root, while production uses
the split bundle from `npm run list:build`:

```
domains/listing.webscheduler.co.za/
├── directory-app/     ← app code, ABOVE the web root
└── public_html/       ← docroot; index.php requires ../directory-app/app/Config/Paths.php
```

**Never set `FTP_DIRECTORY_DIR`.** The FTP credentials are shared with the marketing
job, so an upload step running with an empty `server-dir` would sync the repo to the FTP
account root. A tripwire step now fails the job if that secret appears.

To release it:

```bash
npm run list:build      # → dist/listing/{directory-app,docroot}/ + DEPLOY.txt
```

Upload the two folders to their separate destinations, **never overwriting**
`directory-app/.env`, `public_html/assets/listings/**`, or
`directory-app/writable/verification/**` — none of which exist anywhere but that server.
Then run `php spark migrate --all` from hPanel → Advanced → Terminal.

## Before you push

1. `npm run site:build` — must exit 0. The build has content guards (banned phrases,
   `mailto:` form actions, missing PHPMailer) that fail loudly; hitting one in CI after
   pushing costs a whole cycle.
2. If the change touches `app/Database/Migrations/`, read "Migrations" below — that is
   the only part of a deploy that is not self-healing.
3. If the change touches the contact form or the privacy policy, they must ship
   together. See "The contact/privacy coupling".

## Deploy

Push to `main`, or run the workflow manually from the Actions tab. Then **read the run
summary**, not just the colour.

## Verifying a deploy actually landed

A green run is necessary, not sufficient. Three things can each produce "deployed
successfully, nothing changed":

```bash
# 1. Did the new HTML actually reach the origin?
curl -sI https://webscheduler.co.za | grep -i last-modified

# 2. Is Cloudflare still serving the old copy?
#    cf-cache-status: HIT on a page you just changed = purge did not happen
curl -sI https://webscheduler.co.za | grep -i cf-cache-status

# 3. Is the app healthy, not merely responding?
curl -s https://listing.webscheduler.co.za/health            # {"status":"ok"}

# 4. Which CSP mode is live? Tells you whether the app is running current code.
curl -sD- -o /dev/null https://listing.webscheduler.co.za/ \
  | grep -oiE '^content-security-policy(-report-only)?:'
```

**Use GET, not HEAD, when probing app routes.** CI4 routes registered with
`$routes->get(...)` do not match `HEAD`, so `curl -I` on `/` returns a `404` JSON body
from an app that is working perfectly. `curl -sI` is safe against the static marketing
site and misleading against the directory app — use `curl -s -o /dev/null -w '%{http_code}'`
there instead.

The strongest check is a content diff against what you just built — it catches a
partial FTP sync, which no status code reveals:

```bash
curl -s https://webscheduler.co.za/ -o /tmp/live.html
diff /tmp/live.html dist/site/index.html && echo "live matches build"
```

`/health` returns 200 `{"status":"ok"}` only when database, cache, disk **and** mailer
all answer; 503 `{"status":"degraded"}` otherwise. Add `?token=<directory.healthToken>`
for a per-check breakdown. This matters because almost every way this app breaks still
returns HTTP 200 from `/` — mail can stop entirely and the homepage renders fine.

## Migrations — the one manual gate

Automatic **only if `SSH_HOST` is set**. Otherwise the `migrate` job skips the SSH step
and writes a **⚠️ Migrations did not run** notice into the run summary.

When skipped, run this promptly from **hPanel → Advanced → Terminal**, in the app root:

```bash
php spark migrate --all
```

Why promptly: the FTP upload ships schema-dependent code whether or not the schema has
caught up. Between upload and migration the app runs new code against an old database,
which surfaces as a 500 on whichever page touches the new column — not as an outage
anyone notices.

**Do not run `migrate:rollback` against production.** Migrations here are forward-only
in practice; several drop or rewrite columns, and a rollback against live listing data
loses it. To undo a bad migration, write a new one that reverses it.

## A red run is not always a broken deploy — and green is not always a good one

This pipeline previously failed 10 times in a row with
`Error: Input required and not supplied: server`, which means **the FTP secrets are
missing**, not that anything in the code is wrong. The action aborts before it opens a
connection.

The `migrate` job is deliberately guarded so that a missing `SSH_HOST` skips a step
rather than reddening a run in which both uploads succeeded. An all-red run list stops
being read, and that is how a batch of commits sits unreleased without anyone noticing.
If you add a step that depends on an optional secret, guard it the same way.

**Guarding pattern — the `secrets` context does not work in `if:`.** It is unavailable
in job-level *and* step-level conditions. Map the secret into a job-level `env:` and
test that:

```yaml
jobs:
  example:
    env:
      MY_SECRET: ${{ secrets.MY_SECRET }}
    steps:
      - if: env.MY_SECRET != ''        # works
      # - if: secrets.MY_SECRET != ''  # silently gates nothing
```

Use a **job-level** `env`, not the step's own — a step's own `env` block is not
dependably in scope while that same step's `if:` is evaluated.

## Cloudflare

Both hostnames are proxied (orange cloud); DNS is on Cloudflare nameservers.

- **Cache**: asset filenames are not content-hashed, so a successful upload can still
  leave visitors on the old build. The workflow purges when `CF_ZONE_ID` and
  `CF_API_TOKEN` are set; otherwise it warns and you purge at *Caching → Configuration
  → Purge Everything*.
- **HTTP 526** means Cloudflare reached the origin but could not validate its
  certificate — usually a proxied subdomain whose AutoSSL never completed, because the
  orange cloud intercepts the HTTP-01 validation. Fix: grey-cloud the record, issue the
  cert in hPanel, verify, re-orange, then set SSL/TLS mode to **Full (strict)**. Never
  Flexible — that plus the app's HTTPS redirect is a redirect loop.
- `curl -I` against a proxied host reports the **edge**, not your origin (`cf-ray`
  confirms it). Use `--resolve` or grey-cloud temporarily to test the origin itself.

## What never ships, and must exist on the server

The deploy excludes these on purpose. Each is invisible until the thing that needs it
is used:

| Path | Why it is excluded |
|---|---|
| `.env` | environment-specific secrets; the server's copy is authoritative |
| `public/assets/listings/**` | owner-uploaded logos and photos live only here |
| `writable/verification/**` | uploaded ID and registration documents |
| `.ws-contact.env` | marketing contact-form config, kept **above** the docroot |

The two upload directories are excluded so a release never overwrites user files. That
is a deploy safety property, **not a backup** — nothing in this list exists anywhere
else.

## The contact/privacy coupling

`marketing-site/contact.php` is the only dynamic file on the marketing site, and
`privacy.html` describes what it does with personal information. The two versions
describe two different systems — the older policy says the form "does not send anything
to us… nothing is posted to a server", which is true of a `mailto:` form and false of
the SMTP endpoint.

A normal deploy ships both from the same build, so this is automatic. It only becomes a
problem if someone hand-uploads `contact.php` alone to "just fix the form" — that
publishes an inaccurate description of your own data processing. Deploy the build, not
the file.

## Rolling back

- **Marketing site**: re-run the workflow from the last good commit, or re-upload a
  previous `dist/site/`. Purge Cloudflare afterwards or you will not see the rollback
  either. Low risk — static files.
- **Directory app**: code rolls back the same way. **Schema does not.** If the bad
  release included a migration, roll the code back to a commit whose code still matches
  the migrated schema, and fix forward with a new migration.
- If the app must go down, point the subdomain at a holding page in Cloudflare rather
  than deleting files — an empty docroot on a proxied host gives visitors a raw error.

## First-launch-only steps

Not part of a routine release; see `DEPLOY.md` for detail.

```bash
php spark key:generate                        # encryption.key
php spark directory:adminhash                 # then set directory.adminPasswordHash
php spark migrate --all
php spark db:seed DirectoryCategoriesSeeder   # without this, no categories anywhere
php spark directory:geocode                   # slow by design; also proves outbound HTTPS
```

Plus: `.ws-contact.env` (chmod 600) and the rate-limit directory above the docroot, the
daily `directory:verifications:sweep` cron, an uptime monitor on `/health`, and a
verified backup of the database *and* `public/assets/listings/`.
