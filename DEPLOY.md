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

That's what lets "List on our directory" pre-fill across the two systems. The payload
is still email-verified on this side, so the secret is tamper-evidence, not auth.

## 3. Directory app `.env` on the server

Copy `.env.production.example` → `.env` in the app root and fill it in (DB from step 1,
`directory.prefillSecret` from step 2, a strong `directory.adminPassword`, SMTP creds,
`app.baseURL = 'https://directory.webscheduler.co.za/'`). **Never commit `.env`** — the
deploy explicitly skips it.

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

## 6. Marketing site — nothing else

It's static. Once `FTP_MARKETING_DIR` is set it publishes on every push. To preview a
build locally: `npm run site:build` then open `dist/site/`.

## 7. Hardening before you announce it

- **HTTPS**: enable AutoSSL for both the domain and the subdomain; force HTTPS.
- **CSRF** (directory app): enable the `csrf` global filter with an `except` for
  `list-your-practice/prefill` (that route is a legitimate cross-origin POST).
- **Admin**: set a strong `directory.adminPassword`; `/admin` is `noindex`.
- **Permissions**: ensure `writable/` is writable (755/775) on the server.
- Confirm the WebScheduler app's `directory.handoffUrl` points at the live subdomain.

## Smoke test

1. `https://webscheduler.co.za` loads; "Find a provider" → the directory.
2. `https://directory.webscheduler.co.za/list-your-practice` → submit → verify email → published.
3. In a WebScheduler install, **Settings → Integrations → List your practice** →
   lands on the directory signup **pre-filled** (confirms the shared secret matches).
