# WebScheduler Local (SaaS)

A free public place to find local services, professionals and home industry across South
Africa — doctors, attorneys, vets, dog walkers, home bakers, plumbers and more.
Browse/search by category and location, public profile pages, and an "add your business"
signup gated by email verification.

Separate from the standalone WebScheduler product, which is self-hosted per customer.

- **Stack:** CodeIgniter 4.7 + MySQL, Tailwind for the views.
- **Production:** https://listing.webscheduler.co.za (its own subdomain — see *Deploying*)
- **Local:** http://localhost:8095

This repo also holds the static marketing site (`marketing-site/`), which links to the
directory. The two share a Tailwind palette via `tailwind.tokens.cjs` but build and deploy
independently.

## Setup

```bash
composer install
cp env .env          # then fill in DB, directory.*, email.*
php spark migrate
php spark db:seed DirectoryCategoriesSeeder
npm install && npm run list:css
php spark serve --port 8095
```

`CI_ENVIRONMENT` is **required** in `.env`. CodeIgniter defaults it to `production`, which
turns on `Config\Cookie::$secure` — over plain `http://` that throws
`SecurityException::forInsecureCookie` on every request. Set `development` locally.

`.env` keys of note:

| Key | Purpose |
|---|---|
| `CI_ENVIRONMENT` | `development` locally, `production` on the server |
| `app.baseURL` | Must match the host/port you serve on, or assets 404 |
| `database.default.*` | MySQL (DB `webscheduler_directory`, prefix `xs_`) |
| `directory.adminEmail` | New-listing notifications **and** the contact address published on `/privacy` and `/terms`. Use a role address, not a personal inbox |
| `directory.adminPasswordHash` | Password for `/admin` — generate with `php spark directory:adminhash` |
| `email.*` | SMTP (dev: Mailpit on :1025) |

## Build commands

| Command | Does |
|---|---|
| `npm run list:css` | Tailwind → `public/assets/directory.css` |
| `npm run list:dev` | Same, in watch mode |
| `npm run list:build` | Runs `list:css`, then assembles a deploy bundle in `dist/listing/` |
| `npm run site:css` / `site:dev` / `site:build` | The static marketing site → `dist/site/` |

`site:build` needs `composer install` to have run: it vendors three PHPMailer classes
into `dist/site/lib/` for `contact.php`, the marketing contact form's endpoint, and fails
rather than shipping an endpoint that would 500 on every submission.

## Tests

`composer test` (PHPUnit). Most tests are pure units and need nothing set up.

The tests under `tests/database/` build the real schema from the migrations and need a
**separate MySQL database** — not just a table prefix. Every model hardcodes the prefix
(`protected $table = 'xs_directory_listings'`), which bypasses CodeIgniter's `DBPrefix`
swap, so a prefix-only test config would silently run the suite against your live tables.
SQLite is not an option either: the migrations use `FULLTEXT`, a spatial `POINT` column
and `SHA2()`.

One-time setup:

```bash
mysql -u USER -p -e "CREATE DATABASE webscheduler_directory_tests CHARACTER SET utf8mb4"
```

then add to `.env` (`DBPrefix` must match the prefix baked into the models):

```
database.tests.hostname = localhost
database.tests.database = webscheduler_directory_tests
database.tests.username = YOUR_USER
database.tests.password = 'YOUR_PASSWORD'
database.tests.DBDriver = MySQLi
database.tests.DBPrefix = xs_
```

The schema is created and dropped by the tests themselves — nothing to migrate by hand.

## Local servers (visual testing)

| Command | Serves | URL |
|---|---|---|
| `npm run list:serve` | the directory app | http://localhost:8095 |
| `npm run site:serve` | marketing **source** (`marketing-site/`) | http://localhost:8096 |
| `npm run site:preview` | marketing **build output** (`dist/site/`) | http://localhost:8097 |

Pair a server with its watcher in a second terminal — `list:dev` or `site:dev` — for live
CSS. `site:serve` needs `npm run site:css` to have run at least once; `site:preview` needs
`npm run site:build`.

Both marketing servers are PHP, not static file servers, because the site has one dynamic
file — `marketing-site/contact.php`. To exercise it locally, put a `.ws-contact.env`
alongside the docroot's parent: `./.ws-contact.env` for `site:serve` (docroot is
`marketing-site/`) and `./dist/.ws-contact.env` for `site:preview` (docroot is
`dist/site/`). Copy `marketing-site/.env.example` and uncomment its Mailpit block. Both
names are git-ignored; `dist/site` is wiped per build but `dist/` itself is not, so the
preview copy survives a rebuild.

Two deliberate choices, both learned the hard way:

- **`list:serve` uses CI4's `rewrite.php` router, not `public/index.php`.** With `index.php`
  as the router, PHP hands *every* request to CodeIgniter, so `/assets/*` 404s and the app
  renders unstyled.
- **Fixed ports, and `php -S` rather than `spark serve`.** `spark serve` silently falls
  forward when a port is taken; ports 8080 and 8090 are already held by sibling projects,
  so it will quietly serve you a *different* codebase. `php -S` fails loudly instead.

`app.baseURL` in `.env` must match the port you serve on, or the pages will request their
CSS from the wrong origin.

## Email in development (Mailpit)

The app sends two emails: a verification link to the submitter, and a "new published
listing" notification to `directory.adminEmail`. Locally both are caught by
[Mailpit](https://mailpit.axllent.org/) instead of being delivered — as is the marketing
site's contact form, once its `.ws-contact.env` points at `localhost:1025` with an empty
`SMTP_CRYPTO` (the empty value matters: with a crypto set, PHPMailer tries STARTTLS
against a plaintext server and the send fails in a way that reads like a Mailpit fault).

```bash
brew install mailpit     # once
npm run mail:dev         # SMTP on :1025, web inbox on http://localhost:8025
```

`mail:dev` is **idempotent** — Mailpit is a machine-level tool usually shared with sibling
projects, so the script probes first: it adopts a running instance and exits 0, spawns one
only if the ports are free, and errors clearly if something that *isn't* Mailpit holds
:1025. Running plain `mailpit` instead fails with `bind: address already in use`, which
reads like a broken setup when it actually means "already working".

The email keys in the `env` template are **live, not commented out**, so `cp env .env`
catches mail locally straight away. Leaving them commented makes `email.protocol` fall back
to PHP `mail()` — and because send failures are swallowed by design
(`DirectoryListingMutationService::send()` logs and continues, so a broken mailer never
blocks a signup), the verification email would vanish with no error.

That silent-failure behaviour is the thing to remember in production: if a verification
email never arrives, `writable/logs/` is the only signal. Switch `email.SMTPCrypto` to
`tls` on port 587 with real credentials, and send one test listing immediately after
deploying. The production template is separate —
`dist/listing/directory-app/.env.example`, generated by `npm run list:build`.

## Routes

| Route | Purpose |
|---|---|
| `GET /` | Home: hero, search, featured listings |
| `GET /directory` | Browse/search (category, province, city, `?q=` FULLTEXT) |
| `GET /directory/{slug}` | Business profile (SEO + `LocalBusiness` JSON-LD) |
| `GET /list-your-practice` | Signup form |
| `POST /list-your-practice` | Submit → pending + email verification |
| `GET /directory/verify/{token}` | Verify email → **auto-publish** |
| `GET|POST /manage` | Owner self-service: request an edit link by email |
| `GET /manage/{token}` | Redeem the single-use link → session |
| `GET|POST /manage/edit` | The owner's edit form |
| `GET /faq` | FAQ (also emits `FAQPage` JSON-LD) |
| `GET|POST /contact` | Contact form → emails `directory.adminEmail` |
| `GET /admin`, `GET|POST /admin/login` | Admin backend |
| `GET /sitemap.xml` | Sitemap incl. all published listings |

`/contact` is deliberately on this domain rather than a link out to
`webscheduler.co.za/contact.html`: that page is a *Book a demo* form for the scheduling
product, which is the wrong ask for someone browsing the directory or trying to correct
their own profile. It sends through the same `email.*` SMTP config as the verification and
manage emails, sets `Reply-To` to the visitor, and stores nothing.

## Editing a listing

**Owners** edit their own listing without an account. Control of the email address on the
listing is the proof of ownership — the same principle as the signup verification step.

```
/manage → enter email → emailed link → /manage/{token} → /manage/edit → save
```

The token is single-use, expires in an hour (`directory.manageTtl`), and is exchanged for a
session on redemption so it never sits in the address bar — a token in the URL leaks through
the `Referer` header on any outbound click. `POST /manage` answers identically whether or
not the address is listed, so it cannot be used to enumerate the directory's emails, and it
is throttled by both IP and target address because it sends mail on demand.

**Duplicates are prevented here.** Submitting the signup form with an email that already has
a listing creates no second row — it emails a manage link instead, with the same on-screen
message as a fresh signup. One email owns one listing.

Owners may edit business details only. `status`, `is_featured`, `is_verified`, `email` and
`slug` are absent from `DirectoryListingModel::OWNER_EDITABLE`, so a crafted POST cannot
publish or feature a listing, hijack an address, or change a live URL. The slug never
changes on an owner edit — a rename would discard the profile's SEO. Edits stay live and
notify `directory.adminEmail`.

**Admins** work at `/admin`:

| | |
|---|---|
| Listings | search (name, email, city, phone), filter by category/province, status tabs |
| Edit / create | every field, including email, status, featured and slug |
| Trash | soft-deleted listings, restore or delete permanently |
| Categories | add, rename, re-group, reorder, activate/deactivate |

A category in use cannot be hard-deleted — the listings FK is `ON DELETE SET NULL`, so that
would silently strip the category from live listings. Deactivate instead.

Admin auth is one shared password. Hash it:

```bash
php spark directory:adminhash          # prints directory.adminPasswordHash = '...'
```

Set `directory.adminPasswordHash` in `.env` and remove `directory.adminPassword`. The
plaintext key still works if no hash is set, but logs a deprecation warning. Failed logins
are throttled by IP (5 per 15 min).

> The `/list-your-practice` path predates the pivot from healthcare-only to all service
> businesses. The visible copy says "List your business"; renaming the URL is a loose end.

## Data model (`xs_directory_*`)

- `directory_categories` — seeded taxonomy (147 categories across 12 groups)
- `directory_listings` — core record (status pending→published, soft-deletes, FULLTEXT
  search over `display_name`/`description`/`credentials`, `source`/`source_url` provenance,
  `claim_token` reserved for a future claim flow)
- `directory_practice_locations` — additional locations per listing
- `directory_tags` + `directory_listing_tags` — areas of focus (filterable)

## Deploying

`npm run list:build` produces `dist/listing/`, laid out for the server:

```
dist/listing/
├── DEPLOY.txt        full runbook — read this
├── directory-app/    → ABOVE the web root (never web-reachable)
└── docroot/          → INTO the web root
```

The split is not optional: `app/`, `vendor/`, `writable/` and `.env` must not sit under a
document root. The build refuses to ship a bundle that has CSRF disabled, contains dev
dependencies or a stray `.env`, or whose `.htaccess` downgrades HTTPS.

**Behind Cloudflare** (as `webscheduler.co.za` is): use SSL/TLS mode **Full (strict)**.
In Flexible mode the origin sees plain HTTP, so `forcehttps` redirect-loops *and* Secure
cookies throw. `Config\App::$proxyIPs` already ships populated with Cloudflare's edge
ranges — leave it alone unless you move off Cloudflare, in which case empty it. Without
it every visitor shares a single throttle bucket. See `DEPLOY.txt`.

**Admin password**: generate it with `php spark directory:adminhash` and set
`directory.adminPasswordHash`. The older `directory.adminPassword` stores the password in
cleartext and takes a deprecated code path.

## Not yet built

- **Category × location landing pages** (`/directory/hair-salons/cape-town`) — the main SEO
  opportunity for a directory of this kind.
- **Claim this listing** — `claim_token` is reserved in the schema. Whoever builds it must
  store a `hash('sha256', $token)` and never the raw value, matching `verify_token` and
  `manage_token` (see `DirectoryListingMutationService::hashToken()`); the column is
  `VARCHAR(64)`, which already fits a SHA-256 digest exactly.
- **Quote/enquiry flow** per listing, and paid featured placement (`is_featured` exists and
  the admin panel toggles it).
- **`/admin` hardening** — a single shared password, no username, no 2FA. It *is* throttled
  (5 attempts / 15 min per IP) and stored as a bcrypt hash, but consider IP-restricting the
  path as well.


1. Create it in hPanel — Domains → Subdomains, name listing.

⚠️ Watch the document root. Hostinger defaults a subdomain's root to public_html/directory, which would make that folder reachable at both listing.webscheduler.co.za and webscheduler.co.za/directory — the exact overlap that confused things last time. Set a custom root outside public_html:


/home/uXXXXXXX/domains/listing.webscheduler.co.za/public_html
2. Issue the SSL certificate — SSL → Manage, for the subdomain. Do this before the first request. With CI_ENVIRONMENT = production, Secure cookies are on, and CodeIgniter throws forInsecureCookie (a 500 on every request) over plain HTTP.

3. Cloudflare — set SSL/TLS to Full (strict). In Flexible mode the origin sees HTTP, so forcehttps redirect-loops and Secure cookies throw. Config\App::$proxyIPs already carries Cloudflare's ranges; no action needed. If Hostinger lets you firewall the origin to those ranges, do — it stops anyone who finds the origin address bypassing Cloudflare's WAF.

4. Upload npm run list:build output — the two halves go to different places:


/home/uXXXXXXX/domains/listing.webscheduler.co.za/
├── directory-app/     ← dist/listing/directory-app/   (ABOVE the web root)
└── public_html/       ← contents of dist/listing/docroot/
They must be siblings; docroot/index.php resolves the app via ../directory-app.

5. .env in directory-app/:


CI_ENVIRONMENT = production
app.baseURL = 'https://listing.webscheduler.co.za/'

6. Migrate and seed:

cd ~/domains/listing.webscheduler.co.za/directory-app
php spark migrate --all                        # 18 migrations as of 2026-08-14
php spark db:seed DirectoryCategoriesSeeder    # 147 categories
php spark directory:adminhash                  # then set adminPasswordHash

7. Verify — the checks in dist/listing/DEPLOY.txt, especially:

curl -s -o /dev/null -w '%{http_code}\n' https://listing.webscheduler.co.za/   # 200
# Use GET, not curl -I: routes are registered with $routes->get(), so HEAD returns 404
# from a perfectly healthy app. Same trap when configuring an uptime monitor.
curl -sI https://webscheduler.co.za/directory/          # 404 — old broken copy gone