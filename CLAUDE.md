# WebScheduler marketing repo

**WebScheduler Local** (CodeIgniter 4.7, PHP 8.3, MySQL 8; `app/`, `public/`, `resources/`,
`tests/`) serves `local.webscheduler.co.za` (South Africa) and `local.webscheduler.app`
(every other country) from one codebase, DB and admin; the request's host picks the site.
The brand site is its own repo (`niloc95/webscheduler-site`); `marketing-site/` here is the
old site, kept only for the outreach builders: never build or release it.

**Load the skill before working in its area:** `listing-service` (code map, copy rules,
security boundaries, tests, handbook), `two-sites` (anything that differs per site),
`bookings`, `deploy-production` (releases, production). They hold the non obvious rules.

## Copy (customer facing text only; code identifiers stay as they are)

- Local is "a local business discovery and visibility platform", never a directory. Our
  feature is **Discover**, never "Search" (search stays for search engines etc.). Never
  "listing". Medical first: "practice or business". Full rules in `listing-service`.
- No "—" and no hyphenated words (proper names like KwaZulu-Natal are fine).
- .app: nothing South African, no rand figures; "Plus" never looks like a check.

## Working rules

- Big changes: feature branch, local dev only; merge or release only on the owner's
  explicit go ahead, confirming each production step.
- Claude does its own git (rules in `.github/copilot-instructions.md`): branch, commit
  finished work (handbook changelog line in the same commit), push the branch. Merging
  into `main`, pushing `main`, deleting branches and releasing need the owner's OK each
  time. `npm run agent:check` is the gate (pre-commit hook). Read `git log` before
  trusting a note about what is merged.
- One checkout, one dev server (http://localhost:8095, launchd; never start another),
  one dev DB, one test DB. No worktrees, no second port, no extra DBs. Sessions take
  turns. Load test DB exception: see `listing-service` (`npm run load:status` first).
- System handbook (`Config\Handbook`, `app/Views/admin/handbook/`) is updated in the same
  commit as any change to tables, routes, services, dependencies, tokens, cron, external
  services, id formats, per site differences, Bookings or the brand site.
- Tests: `php vendor/bin/phpunit` (~30 s); DB tests use `Tests\Support\FreshDatabase`.
  All pass before a release. Check public pages, emails, prices and links on both sites.
- Production: `ssh lightsail`. Reading is fine; any change needs the owner's OK first.
