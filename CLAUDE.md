# WebScheduler marketing repo

Two products live here:

| Product | Code | Live at | Skill |
|---|---|---|---|
| WebScheduler marketing site (static) | `marketing-site/` | `webscheduler.co.za` | `marketing-site` |
| **WebScheduler Local** (CodeIgniter 4.7, PHP 8.3, MySQL 8) | `app/`, `public/`, `resources/`, `tests/` | `local.webscheduler.co.za` (South Africa) and `local.webscheduler.app` (every other country) | `listing-service`, plus `two-sites` for anything that differs per site |

Releases follow the `deploy-production` skill. Load the skill before working in its area:
they hold the rules that are not obvious from the code.

## Architecture in one screen

- **One Local install, two sites.** One codebase, one database, one admin (on co.za). The
  request's host picks the site (`Config\Sites`, `service('site')`, `Filters\SiteResolver`);
  a profile's business country (`country_code`) picks the site it belongs to. Visitor IP
  never decides. Every public query is scoped by site, links that leave the request use
  `listing_url()` / `site_url_for()`, caches use `site_cache_key()`. `sites.app.enabled`
  in `.env` switches .app on and off (the rollback).
- **Layers.** Thin controllers → one service per feature, which is the only writer of its
  tables (`DirectoryService` reads, `DirectoryListingMutationService` writes for the
  public and owners, `DirectoryAdminService` for admin) → models (`xs_` tables). Shared
  view helpers in `app/Helpers/` (autoloaded), one layout (`layouts/public.php`), one
  stylesheet (`resources/directory.css` → `public/assets/directory.css`, Tailwind with
  tokens shared with the marketing site in `tailwind.tokens.cjs`), one script
  (`public/assets/directory.js`).
- **Money.** Free business profiles everywhere. Paid: the Verified Business badge on co.za
  (documents checked, rand), **Plus** on .app (no checks, priced in US dollars, charged in
  rand), the profile setup service, all through PayFast. Payment never changes the order
  of discovery results.
- **Security boundaries to respect:** `DirectoryListingModel::OWNER_EDITABLE`, the CSRF and
  honeypot exemptions in `Config\Filters`, enforcing CSP (no inline styles, scripts or
  handlers), Cloudflare Access plus a JWT check in front of `/admin*`, `Mailer` as the only
  mail path, `listing_public_view()` before anything renders a profile.

## Copy rules for WebScheduler Local

- **Key words: discover, discovery, platform.** Local is "a local business discovery and
  visibility platform", never a directory. On co.za it is "built for South Africa"; on
  .app it never says so. Our own feature is never "Search": say **Discover** on buttons,
  placeholders and aria labels, **discovery results** for results, and **discovered on
  the platform** for being found.
- Keep "search" only for other people's search: search engines, Google's job search,
  paid search ads, address lookup.
- Tagline: **Discover local. Be discovered.** Never write "More than a business listing."
- Prefer "business profile" over "listing". The header button reads "Add your business".
- No "—" and no hyphenated words in anything customers see (proper names such as
  KwaZulu-Natal are fine).
- **On .app:** no rand figures in customer copy (the rate moves); say Plus is billed in
  South African rand by a South African company. The badge is "Verified", the .app upgrade
  is "Plus"; Plus must never claim or look like a check of the business.
- Copy rules apply to what customers read, not to code: identifiers, routes, CSS classes
  and config names (`_search_composer`, `Config\Search`) stay as they are.

## Working rules

- Big changes go on a feature branch, run on local dev only, and are merged or released
  only on the owner's explicit go ahead. Release step by step, confirming before each
  production change.
- Another session may be using the main checkout (port 8095): use a git worktree under
  `../marketing-worktrees/`, never `git switch` there. A worktree needs a **copied**
  `vendor/` (a symlink autoloads the main checkout's `app/`), its own port, its own dev and
  test databases (`listing-service` → Worktrees). Push to `main` from the worktree.
- Tests: `php vendor/bin/phpunit`, slow by design (10 to 20+ minutes). Never run two
  suites against one test database. `SmartSearchTest::testTheHomeExamplesComeFromRealListings`
  is a known stale failure; everything else must pass before a release.
- Check a change on **both** sites when it touches public pages, emails, prices or links:
  co.za must not change by accident and .app must show nothing South African.
- Production is reached with `ssh lightsail`. Reading is fine; any change to production
  (code, `.env`, database rows, Cloudflare) needs the owner's OK first.
