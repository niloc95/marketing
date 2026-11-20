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
  view helpers in `app/Helpers/` (autoloaded), one public layout (`layouts/public.php`;
  `/admin` and `/bookings` use the app shell, `layouts/_shell.php`), one
  stylesheet (`resources/directory.css` → `public/assets/directory.css`, Tailwind with
  tokens shared with the marketing site in `tailwind.tokens.cjs`), one script
  (`public/assets/directory.js`).
- **Money.** Free business profiles everywhere. Paid: the Verified Business badge on co.za
  (documents checked, rand), **Plus** on .app (no checks, priced in US dollars, charged in
  rand), the profile setup service, all through PayFast. Payment never changes the order
  of discovery results.
- **Documents.** Every PDF we publish lives in `public/assets/documents/` (staff only:
  `resources/documents/`) and is listed in `Config\Documents`; nowhere else.
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
- **Medical first** (owner, 16 Nov 2026; plan `docs/plans/2026-11-medical-focus.md`):
  discovery copy leads with practices ("Discover local practices and businesses"), the
  free product is the **practice profile** ("Create your free practice profile"), and
  owner calls to action say "practice or business" so shops still see themselves. The
  header button reads **"Add your practice or business"** ("Add profile" below 1280px).
  Never "listing". Per profile wording comes from `Config\Verticals` (noun), not new maps.
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
- **Claude does its own git** (owner, 15 Nov 2026; replaces "git is Copilot's job" of
  11 Nov, because every Copilot question had to be relayed by hand): Claude makes the
  feature branch, commits its finished work (handbook changelog line in the same commit)
  and pushes the branch, following the git rules in `.github/copilot-instructions.md`.
  Merging into `main`, pushing `main`, deleting branches and releasing still need the
  owner's explicit OK, each time. Copilot keeps small copy tasks, reviews and cleanups
  and uses the same rules. `npm run agent:check` is the shared gate (the pre-commit hook
  runs it; `npm run agent:hooks` installs it). Read `git log` before trusting a note
  about what is merged.
- **One checkout, one dev server, one dev DB, one test DB.** Work in this checkout: make
  the feature branch here, switch back to `main` when done. Sessions take turns. No git
  worktrees, no second port, no extra databases.
  - Dev site: always http://localhost:8095, kept running by launchd (`npm run dev:install`
    once; `dev:status`, `dev:restart` after a composer or `.env` change). It serves whatever
    branch is checked out. Never start another server.
  - The same `dev:install` also installs the booking jobs (launchd, every minute:
    `bookings:dispatch`, and `bookings:payments` every five), so booking messages reach
    Mailpit as on production. A job, not a server. Logs: `~/Library/Logs/webscheduler-dev-jobs.log`.
  - Dev DB `webscheduler_directory`, test DB `webscheduler_directory_tests`.
  - One exception (owner, 20 Nov 2026): the load test DB `webscheduler_loadtest`, a copy of the dev DB
    seeded by `php spark load:seed`. `npm run load:on` points 8095 at it, `npm run load:off` back; only
    one is live at a time, and always `load:off` when done. Check `npm run load:status` first: if 8095
    is on the load DB, the dev data you expect is not there.
- **System handbook** (`/admin/documents/system`): every change that adds or alters a
  table, route group, service, dependency, design token, cron job, external service, id
  format, a per site difference, Bookings or the brand site updates `Config\Handbook`
  and/or `app/Views/admin/handbook/` in the same commit, with a dated changelog line.
  Live figures need nothing. Rules in the `listing-service` skill, "System handbook".
- Tests: `php vendor/bin/phpunit`, about 30 seconds for the full suite. The schema is
  built once per run and every DB test class uses `Tests\Support\FreshDatabase`, which
  empties the tables before each test (never `DatabaseTestTrait` directly). Use the id
  an insert returns, never assume 1. All tests must pass before a release.
- Check a change on **both** sites when it touches public pages, emails, prices or links:
  co.za must not change by accident and .app must show nothing South African.
- Production is reached with `ssh lightsail`. Reading is fine; any change to production
  (code, `.env`, database rows, Cloudflare) needs the owner's OK first.
