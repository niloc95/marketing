---
name: listing-service
description: Work on the WebScheduler Directory CodeIgniter 4 app (app/) — public listing/search/detail pages, signup+verify, owner self-service manage flow, admin backend. Routes, models, services, local env, and end-to-end verify steps.
---

# Directory listing service (`app/`)

Public directory SaaS: browse/search South African service businesses, public profile
("show") pages, "list your business" signup gated by email verification, owner
self-service editing via a passwordless magic link, and an admin backend. Repo root
`README.md` has the setup runbook — read it for env vars and Mailpit; this skill is the
condensed working reference for making and verifying code changes.

**Deploying is a separate skill: `deploy-production`.** Use it for releases, migrations
against production, and diagnosing a deploy that went green without changing anything.
`DEPLOY.md` is the first-time server setup.

## Routes (`app/Config/Routes.php`)

| Route | Controller/action |
|---|---|
| `GET /` | `Directory::home` — featured listings, categories |
| `GET /directory` | `Directory::index` — browse/search (`q`, `category`, `province`, `city`) |
| `GET /directory/categories` | `Directory::categories` — all categories hub |
| `GET /directory/{category}/{province}` | `Directory::place` — landing page |
| `GET /directory/{slug}` | `Directory::segment` → category landing page **or** falls through to `show($slug)`, the listing detail page — category wins so a listing can never claim a category's slug |
| `GET /directory/verify/{token}` | `Directory::verify` — publishes a pending listing |
| `GET/POST /add-listing` | `Listing::create` / `Listing::store` — public signup |
| `GET/POST /manage` | `Manage::index` / `Manage::request` — owner requests an edit link by email |
| `GET /manage/{token}` | `Manage::redeem` — single-use, trades the token for a session |
| `GET/POST /manage/edit` | `Manage::edit` / `Manage::update` — the owner's edit form |
| `GET/POST /contact` | `Contact::index` / `Contact::submit` — emails `directory.adminEmail`, stores nothing |
| `GET /faq` | `Contact::faq` — copy lives in the view as one `$groups` array that renders the page **and** builds the `FAQPage` JSON-LD, so the two cannot drift. Edit answers there, not in two places. |
| `GET/POST /admin`, `/admin/login` | `Admin::*` — admin backend |

**Route-ordering gotcha** (called out in comments in `Routes.php`): literal segments
(`manage/edit`, `directory/categories`, `directory/verify/...`) must be declared *before*
the `{segment}`/`{token}` catch-alls, or the catch-all swallows them first.

## Data model

`DirectoryListingModel` — table `xs_directory_listings`, soft-deletes, FULLTEXT search
over `display_name`/`description`/`credentials`.

- `OWNER_EDITABLE` allowlist (what `/manage/edit` may write): business fields only —
  `type, display_name, contact_person, title, category_id, credentials, description,
  phone, website, social_facebook, social_instagram, social_linkedin, address_line,
  suburb, city, province, postal_code, country, logo_path`. Deliberately **excludes**
  `email`, `slug`, `status`, `is_featured`, `is_verified` — a crafted owner POST can't
  publish/feature a listing, hijack the address, or break the SEO'd URL.
- Other tables: `directory_categories` (seeded taxonomy, 147 categories / 12 groups),
  `directory_practice_locations` (extra locations per listing), `directory_tags` +
  `directory_listing_tags` (filterable areas of focus).

## Services split

- **`DirectoryService`** — read-side: `browse()`, `getProfile($slug)` (assembles the
  detail-page data: listing + category + locations + tags), `relatedListings()`,
  `categories()`/`categoriesGrouped()`, `provinces()`, sitemap URL helpers.
- **`DirectoryListingMutationService`** — write-side: `submitPublic()` (dedupes by email —
  a second signup with an existing email gets a manage link, not a second row, with an
  identical on-screen message either way), `verify()` (publishes), `requestManageLink()` /
  `redeemManageToken()` (single-use, 1h TTL), `updateOwn()` (applies only
  `OWNER_EDITABLE`).
- **`App\Libraries\Mailer`** — the **one** outbound-mail path. `send($to, $subject, $body,
  $replyTo = '')` returns whether it went out and records the result in `MailHealth`. It
  logs the recipient *domain* only, never the address or body. Signup/edit callers ignore
  the return value (a broken mailer must not block them, so `writable/logs/` and `/health`
  are the only signal); `Contact::submit()` uses it to tell the visitor the truth. Do not
  add a second send path — the error handling here exists because an earlier version
  checked for an exception that CI4's `Email::send()` never throws, so outbound mail could
  stop entirely and leave no trace.

## Local environment

- `.env` needs `CI_ENVIRONMENT=development` — CodeIgniter defaults to `production`, which
  turns on `Config\Cookie::$secure` and throws `SecurityException::forInsecureCookie` over
  plain HTTP.
- `app.baseURL` must match whatever port you serve on, or assets 404.
- First-time setup: `php spark migrate && php spark db:seed DirectoryCategoriesSeeder`.
- `directory.adminEmail` (new-listing notifications), `directory.adminPasswordHash` (admin
  login — generate with `php spark directory:adminhash`, prints the value to set).

### Keys that must NOT survive the trip to a production `.env`

A production env file is usually born as a copy of a working local one, and these four
are the ones that ride along silently. Checked against `.env.production.example`:

| Key | On the server |
|---|---|
| `directory.adminPassword` | **absent** — cleartext, deprecated code path. Setting `adminPasswordHash` does *not* neutralise it; it must be deleted |
| `database.tests.*` | **absent** — test DB credentials |
| `encryption.key` | **present** — `php spark key:generate` |
| `CI_ENVIRONMENT` | `production` (locally it must be `development`, see above) |

## Commands

| Command | Does |
|---|---|
| `npm run list:css` / `list:dev` | Tailwind → `public/assets/directory.css` (once / `--watch`) |
| `npm run list:serve` | Serve at http://localhost:8095 — **must** go through `vendor/codeigniter4/framework/system/rewrite.php`, not `public/index.php`, or `/assets/*` 404s and the app renders unstyled |
| `npm run list:build` | `list:css` + assembles the deploy bundle in `dist/listing/` |
| `npm run mail:dev` | Mailpit (idempotent adopt-or-spawn) — SMTP :1025, inbox http://localhost:8025 |

Pair `list:serve` with `list:dev` in a second terminal for live CSS.

## Verify workflow

- **Listing detail page**: `list:serve` + `list:dev`, visit
  `http://localhost:8095/directory/{slug}` for a published, seeded listing.
- **Signup flow**: submit `/add-listing`, open Mailpit
  (http://localhost:8025), click the verification link, confirm the listing is now live at
  its slug.
- **Owner self-service**: `/manage` → enter email → Mailpit link → `/manage/edit` → save →
  confirm the edit shows on the public page and that `status`/`slug`/`email` are unchanged.
- **Admin**: `php spark directory:adminhash`, set `directory.adminPasswordHash` in `.env`,
  log in at `/admin` (throttled 5 attempts / 15 min by IP).

## CSP is enforcing — it constrains how views may be written

`Config\ContentSecurityPolicy` has `$reportOnly = false`. Violations are **blocked**,
not merely logged, and the first symptom is usually a panel that renders empty rather
than an error. When editing views:

- **No inline `<style>` without the `{csp-style-nonce}` placeholder** — `style-src` is
  `'self'` with no `'unsafe-inline'` (a nonce makes browsers ignore `'unsafe-inline'`
  anyway, so adding it back buys nothing).
- **No inline event handlers** (`onclick=`, `javascript:` URLs) — `script-src-attr` is
  `'self'`. Bind listeners from a file instead.
- **A new off-site form POST needs adding to `form-action`, and so does wherever it
  redirects.** It does not fall back to `default-src`; an unlisted target dies silently at
  the last click. The redirect half is the one that bites: PayFast answers the checkout
  POST with a 302, and **live crosses to `https://payment.payfast.io`** while the sandbox
  stays on `sandbox.payfast.co.za` — so this passes every sandbox test and breaks the
  moment real payments are switched on. All of it is listed now
  (`www.payfast.co.za`, `sandbox.payfast.co.za`, `*.payfast.io`).
  The symptom is a console error quoting a policy that visibly *contains* the host it
  claims was violated, because Chrome names the URL the form pointed at rather than the
  redirect it actually refused. `writable/logs/` records what the browser enforced.
- **`img-src` picks up the map tile host at runtime** from `directory.mapTileUrl`. A
  blank grey map is the symptom of that being wrong, and it looks identical to a tile
  provider outage — check `writable/logs/` for `CSP violation` first.

## Schema history worth knowing

- `2026-07-28-120000_RenameProfessionsToCategories.php` — renamed
  `directory_professions`→`directory_categories`, `profession_id`→`category_id`,
  `qualifications`→`credentials` (the domain pivoted from healthcare-only to general
  service businesses).
- `2026-07-29-100000_AddManageTokenToListings.php` — added `manage_token`/`manage_expires`
  columns + indexes, separate from `verify_token`: `verify_token` publishes on first use
  (48h TTL), `manage_token` only proves ownership and is re-issuable anytime (1h TTL).
