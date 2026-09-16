---
name: listing-service
description: Work on the WebScheduler Directory CodeIgniter 4 app (app/) — public listing/search/detail pages, signup+verify, the paid Verified Business badge (application, PayFast checkout, admin review queue, team members and extra branches), owner self-service manage flow, admin backend. Routes, models, services, settings precedence, local env, and end-to-end verify steps.
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
| `GET /add-listing/verified` | `Listing::createVerified` — the **same** form and the same POST target, rendered with the documents panel already open. Not a second signup path; see "Signup form" below |
| `GET /verified` | `Contact::verified` — public explainer for what the badge claims. Every badge on a profile links here |
| `POST /manage/verification`, `GET /manage/verification/checkout`, `POST .../cancel`, `GET .../done` | `Manage::*` — apply, pay, cancel, PayFast return |
| `POST /payfast/notify` | `PayFastNotify::index` — PayFast ITN |
| `GET /admin/verifications`, `POST /admin/verifications/{id}/{approve,reject,activate,revoke}` | `Admin::*` — the review queue |
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
  phone, phone_alt, website, address_line, address_line_2, suburb, city, province,
  postal_code, country, logo_path, trading_hours, accepts_card_payments, offers_delivery,
  offers_online_booking`. Deliberately **excludes** `email`, `slug`, `status`,
  `is_featured`, `is_verified` — a crafted owner POST can't publish/feature a listing,
  hijack the address, or break the SEO'd URL. Note the `social_*` columns exist on the
  table but are **not** on this list; read the constant rather than trusting this copy of
  it, since it is a security boundary and this doc has drifted from it before.
- **`is_verified` and `verified_until` are unrelated.** `is_verified` (bool) means the
  signup email was confirmed — `verify()` sets it alongside `status = published`.
  `verified_until` (date) is the paid Verified Business badge. Neither implies the other.
- Other tables: `directory_categories` (seeded taxonomy, 147 categories / 12 groups),
  `directory_practice_locations` (extra locations per listing), `directory_tags` +
  `directory_listing_tags` (filterable areas of focus), `directory_listing_team` (team
  members), `directory_listing_services` + `directory_listing_attributes` ("Services &
  prices" and "Features & amenities" — free for every listing, written only through
  `ServiceMenuService`; feature keys and labels live in `Config\ListingAttributes`, keyed
  by category `group_name`, and saves drop keys the chosen category's group doesn't offer.
  Both sections carry a `*_present` marker input: absent = leave alone, present-but-empty =
  clear), `directory_settings` (admin-editable badge price/enabled), and the verification
  set — `directory_verifications`, `_verification_documents`, `_verification_events`,
  `_verification_submissions`, `_verification_itn_rejections`.

## Services split

- **`DirectoryService`** — read-side: `browse()`, `getProfile($slug)` (assembles the
  detail-page data: listing + category + locations + tags), `relatedListings()`,
  `categories()`/`categoriesGrouped()`, `provinces()`, sitemap URL helpers.
- **`DirectoryListingMutationService`** — write-side: `submitPublic()` (dedupes by email —
  a second signup with an existing email gets a manage link, not a second row, with an
  identical on-screen message either way), `verify()` (publishes), `requestManageLink()` /
  `redeemManageToken()` (single-use, 1h TTL), `updateOwn()` (applies only
  `OWNER_EDITABLE`).
- **Description cap** is `RichText::MAX_PLAIN_LENGTH` (1000 plain-text chars; the JS counter
  reads it from `data-rich-text-max`). `RichText::exceedsCap()` exempts an *unchanged*
  description, so profiles saved under the old 5000 cap can still save other edits.
- **`App\Libraries\Mailer`** — the **one** outbound-mail path. `send($to, $subject, $body,
  $replyTo = '')` returns whether it went out and records the result in `MailHealth`. It
  logs the recipient *domain* only, never the address or body. Signup/edit callers ignore
  the return value (a broken mailer must not block them, so `writable/logs/` and `/health`
  are the only signal); `Contact::submit()` uses it to tell the visitor the truth. Do not
  add a second send path — the error handling here exists because an earlier version
  checked for an exception that CI4's `Email::send()` never throws, so outbound mail could
  stop entirely and leave no trace.

## Verified Business — the one paid feature

A monthly badge on an otherwise free listing — R29.99 at the time of writing, but the live
figure comes from `DirectorySettings::badgePrice()` (see precedence below), so read it there
rather than trusting this number. Everything else on the site is free and must stay free;
the FAQ, T&Cs and signup copy all promise that in writing.

**Documents are what apply, not any plan field.** `Listing::store()` starts an application
when `resolveVerificationDocuments()` returns files — it never reads the form's `plan`
input, which only decides whether the "we didn't receive both documents" notice shows. So a
form that says "Free" while carrying attached files still submits an application. Anything
that switches a person back to free must clear the file inputs, or the UI is lying about
what is about to happen.

**The listing is saved before the documents are touched**, deliberately, and the comment in
`store()` says why: *a rejected document must never cost someone their listing.* Keep any
new validation out of that path — tell the person, don't block the save.

What the badge actually gates:

| | Free | Verified |
|---|---|---|
| Publish, search, profile, gallery (8), self-service editing | yes | yes |
| Green badge on profile + every search result | — | yes |
| Team members (`TeamMemberService::MAX_MEMBERS`, 12) | — | yes |
| Extra branches (`PracticeLocationService::MAX_LOCATIONS`, 6) | — | yes |
| Matching a search for a team member's name/specialisation | — | yes |
| **A higher position in results** | **no** | **no** |

That last row is load-bearing. Only `is_featured` affects ordering, and only in
`DirectoryService::featured()`. Nothing in any view may hint that paying moves a business
up — it would be false today and a promise we'd then have to keep.

- **`VerificationService`** — `isEnabled()` (just `DirectorySettings::badgeEnabled()`; when
  false the offer must not render at all), `canTakePayment()` (adds "PayFast is
  configured" — an approved application without it waits for manual/EFT activation),
  `monthlyAmount()`, and `applyPaidUntil()`, the **only** writer of
  `xs_directory_listings.verified_until`.
- The public badge is a render-time date compare on `verified_until` —
  `listing_is_verified_business()` in `app/Helpers/directory_ui_helper.php`. There is no
  boolean to keep in sync, and in particular **`is_verified` is not it** (that one means the
  signup email was confirmed — see Data model).
- **The price is snapshotted** onto `directory_verifications.amount` at application time, so
  changing the price never reprices existing subscribers.
- **Benefit copy lives in two partials that must change together**:
  `_verification_pitch.php` (prose — `/verified` and the owner dashboard) and
  `_plan_cards.php` (the ✓/✗ matrix on the signup form). Deliberately not merged; each
  docblock points at the other. Caps come from the service constants in both, never typed.

### Settings precedence: DB → `.env` → config default

`DirectorySettings::badgePrice()` / `badgeEnabled()` read `xs_directory_settings` first,
then `directory.verifiedMonthlyAmount` / `directory.verifiedBadgeEnabled` in `.env`, then
the committed default in `app/Config/Directory.php`. **A DB row silently wins**, so editing
`.env` does nothing once `/admin/settings` has ever been saved. `/admin/settings` renders
`priceSource()` specifically to answer "I changed it and nothing happened".

### Signup form: one form, two states

`/add-listing` and `/add-listing/verified` are the **same view, the same fields and the
same POST target**. `Listing::renderForm($plan)` serves both; the only difference it passes
down is whether the documents `<details>` arrives `open`.

Three rules hold that together, and each exists because the obvious alternative fails:

1. **Never render both variants.** `_verification_fields.php` would appear twice, giving the
   form two inputs named `verify_doc_registration` and two elements sharing an id.
2. **The card buttons are real links** carrying `data-plan-pick`. That is what makes the
   page work with JS off and the verified URL shareable. The plan-picker module in
   `public/assets/directory.js` intercepts them and swaps in place — following them as
   links reloads the page and resets the scroll, which reads as the page jumping.
3. **`applyPlan()` writes the hidden `plan` input before setting `details.open`.** The
   `toggle` listener compares against that input to tell a person opening the panel from
   `applyPlan` opening it. Swap the order and the two handlers drive each other in circles.

Markup contract: `data-plan-cards`, `data-plan-card="free|verified"`, `data-plan-pick`,
`data-plan-input`, `data-plan-cleared`, `data-verify-offer`. The "Remove" control is just
another `data-plan-pick="free"` link, so it cannot drift from the Free card and still works
without JS.

After a failed submission `redirect()->back()` uses the **Referer**, which a `pushState`
plan switch does not change — so `renderForm()` lets a posted `plan` outrank the route.

## Local environment

- `.env` needs `CI_ENVIRONMENT=development` — CodeIgniter defaults to `production`, which
  turns on `Config\Cookie::$secure` and throws `SecurityException::forInsecureCookie` over
  plain HTTP.
- `app.baseURL` must match whatever port you serve on, or assets 404.
- `app.devHostnames` is a comma-separated list of other hosts this machine answers on —
  LAN IPs and the Bonjour `.local` name — for opening the dev server from a phone or
  another PC. `Config\App::__construct()` reads it into `$allowedHostnames` outside
  production, which makes `base_url()` follow the host the browser used. Without it every
  asset URL stays absolute on `localhost`, and since CSP is enforcing in development with
  `defaultSrc 'self'`, the browser blocks them as cross-origin and the page renders
  unstyled. Set it per machine; `.env` is gitignored, and the IPs are DHCP leases.
- First-time setup: `php spark migrate && php spark db:seed DirectoryCategoriesSeeder`.
- `directory.adminEmail` (new-listing notifications), `directory.adminPasswordHash` (admin
  login — generate with `php spark directory:adminhash`, prints the value to set).
- `directory.mapTileKey` — CARTO basemap key, free from https://carto.com/basemaps/apikey
  (no account, 5M tiles/month, commercial use allowed). `Directory::mapTileUrl()` appends
  it as `?key=`. **Its absence is invisible**: CARTO returns tiles stamped "API KEY
  REQUIRED" as a valid HTTP 200 PNG, so there is no error, no failed request, no CSP
  violation and nothing in the logs — and a wrong key is byte-identical to no key. Check
  a map by eye: neither the status code nor the byte size will tell you, since a real
  tile can be *smaller* than a watermarked one (the watermark is extra pixels over the
  same map — 2627 bytes keyed vs 3965 unkeyed on one sparse rural tile).

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
| `npm run list:serve` | Serve at http://localhost:8095 — **must** go through `vendor/codeigniter4/framework/system/rewrite.php`, not `public/index.php`, or `/assets/*` 404s and the app renders unstyled. Binds `[::]`, not `localhost`, so the LAN can reach it — `[::]` and not `0.0.0.0` because the Bonjour name resolves to IPv6 and an IPv4-only bind would refuse it |
| `npm run list:hot` | **All three at once** — Tailwind `--watch`, `php -S` :8095, and the file watcher. Ctrl-C stops the lot. The usual way to work |
| `npm run list:watch` | The file watcher alone, to pair with an existing `list:serve` |
| `npm run list:build` | `list:css` + assembles the deploy bundle in `dist/listing/` |
| `npm run mail:dev` | Mailpit (idempotent adopt-or-spawn) — SMTP :1025, inbox http://localhost:8025 |

`npm run list:hot` is the one-command version of `list:serve` + `list:dev`, and adds
hot reload: save a view, helper or controller and the browser reloads itself; save
`resources/directory.css` and the compiled stylesheet is swapped in **without
navigating**, so scroll position and the scrolled header state survive.

Two things worth knowing about it:

- The reload client is only served while the watcher is running — it writes
  `public/assets/.dev-reload.json` on startup and deletes it on exit, and
  `layouts/public.php` loads the client only when that file exists. So plain
  `list:serve` behaves exactly as it always did.
- Both `list:serve` and `list:hot` are reachable from other devices on the network;
  `list:hot` prints every URL it can be opened on at startup. That needs the host in
  `app.devHostnames` too — see Local environment above.
- The 500ms poll means Playwright/Puppeteer `waitUntil: 'networkidle'` never
  resolves against the dev server. Use `'load'` or `'domcontentloaded'` in local
  browser scripts. Production is unaffected — the client is development-only and
  is excluded from the deploy bundle.

Pair `list:serve` with `list:dev` in a second terminal if you want the old
two-terminal split without hot reload.

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
- **Verified Business**: apply from `/add-listing/verified` or the owner dashboard, approve
  at `/admin/verifications`, then check `verified_until` on the listing and that the badge
  renders on the profile *and* in search results.

### Three things that make a working change look broken

Each of these produced a false failure while testing, and none of them says so:

- **The signup throttle is 3/hour per IP and 2/hour per email.** Over the limit you get
  "Too many submissions. Please wait a little while and try again." — which during repeated
  local testing is indistinguishable from a real bug. Note it takes the `redirect()->back()`
  path *without* `->with('old', …)`, so the form comes back **empty**, which reads as data
  loss on top. `php spark cache:clear` resets it. There is also a 3-second form-timing floor
  (`MIN_FORM_SECONDS`) that silently fakes success, so scripted submits must wait.
- **`DirectorySettings` caches the whole settings table for 24h** (`CACHE_TTL = 86400`).
  Saving through `/admin/settings` drops the entry, but editing the DB row or `.env`
  directly does **not** — so the change appears to do nothing. `php spark cache:clear`.
- **CSP is enforcing, which breaks browser-automation helpers.** Playwright's
  `page.addStyleTag()` is refused by `style-src 'self'` (see the CSP section below). Set
  properties through CSSOM (`el.style.setProperty(...)`) instead, which is not covered by
  `style-src`.

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
  provider outage — check `writable/logs/` for `CSP violation` first. `originOf()` parses
  out scheme+host only, so the `?key=` that `mapTileUrl()` appends does not affect the
  policy — and a *watermarked* map, as opposed to a blank one, is never CSP: that is
  `directory.mapTileKey` missing.

## Schema history worth knowing

- `2026-07-28-120000_RenameProfessionsToCategories.php` — renamed
  `directory_professions`→`directory_categories`, `profession_id`→`category_id`,
  `qualifications`→`credentials` (the domain pivoted from healthcare-only to general
  service businesses).
- `2026-07-29-100000_AddManageTokenToListings.php` — added `manage_token`/`manage_expires`
  columns + indexes, separate from `verify_token`: `verify_token` publishes on first use
  (48h TTL), `manage_token` only proves ownership and is re-issuable anytime (1h TTL).
