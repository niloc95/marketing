# Launch checklist (Phase 10)

How online bookings go from the branches to the first businesses. Every production step
follows the `deploy-production` skill and waits for the owner's OK, one step at a time.
Nothing here has run on production yet.

## 1. Merge (owner's go ahead)

Branches stack: `bookings-p5-public` → p6 → p7 → p8 → p9 → `bookings-p10-launch`, all off
`main` after the Phase 0 to 4 merge (`f3bb15b`). Merging p10 into `main` brings all of them.
Full suite green before the merge (`php vendor/bin/phpunit`).

## 2. Before the code goes out

| Step | Why | Notes |
|---|---|---|
| `encryption.key` in the production `.env` (T21) | WhatsApp, payment, Zoom, Google and webhook accounts are stored encrypted; without it they cannot be saved | `php spark key:generate --show`, paste by hand; back up `.env` first. Never rotate it afterwards: saved accounts would no longer decrypt |
| Database dump | the migrations below add 18 tables | the nightly `/home/deploy/backups/db-*.sql.gz` from the same day, or a fresh one |

## 3. Release

1. Migrations (all additive, new tables and nullable columns): `2026-11-12` to `2026-11-20`
   (`CreateBookingFoundation` … `CreateBookingPlans`), then `2026-11-20` (`RemoveBookingSms`). Additive, so they may run before the
   code copy (memory: additive migration first).
2. Code, `npm run list:build` on the server (the business bundle and the stylesheet).
3. Cron: reinstall `/etc/cron.d/webscheduler` from `deploy/cron/webscheduler`. New lines:
   `bookings:dispatch` (every minute), `bookings:payments` (every 5 minutes),
   `bookings:prune` 04:35, `bookings:plans` 04:40, `bookings:retention` 04:45.
4. Still dark: `bookings.enabled` stays off. Check both hosts: no Book online button, `/business`,
   `/api/v1/*` and `/developers/bookings` answer 404, .app shows nothing of it.

## 4. Drills on production (owner's OK)

- **Restore drill:** copy `scripts/bookings-restore-drill.php` to the server and restore the
  newest dump into a scratch database (its name must end in `_tests`; create it, then drop it
  after). Every `xs_booking_*` table must be there. Record the restore time.
- **Load test:** on the server, against `https://127.0.0.1` with `HOST=local.webscheduler.co.za`
  and `BOT_TOKEN` read from `.env` (never printed): `SLUG=… SERVICE=… scripts/bookings-load-test.sh`
  on the test profile. Local dev (5 Nov 2026): API availability ~72 req/s; the page numbers
  need the bot token, because ScrapeGuard rightly stops one address after 120 requests a minute.

## 5. Switch on for a test profile

1. `.env`: `bookings.enabled = true`, `bookings.planRequired = false` for the first days.
2. Turn bookings on for the owner's own test profile from `/manage/bookings`, set it up, book
   from a phone, change and cancel by link, pay a sandbox deposit, check every email in a real
   inbox (SES), check `/admin/bookings`.
3. Turn off again or keep, as the owner decides.

## 6. Paid plan (D1)

| Setting | Value |
|---|---|
| Prices (T23) | Solo R160, Team R420, Business R890 a month by bookable people, already in `Config\Bookings::$planTiers`; `bookings.planPrices = 160,420,890` in `.env` only to change them |
| `bookings.planRequired` | `true` when the plan goes on sale; every business then needs a trial or a paid plan for its booking page and API |
| Trial | 30 days (`Config\Bookings::$trialDays`), once per business, started when bookings are turned on |

Businesses that turned bookings on before `planRequired = true` have no plan row: they show
"You have no plan yet" and stop taking bookings. Before switching it on, give each of them a
trial from `/admin/bookings` (Add free days), which starts it.

The brand site's pricing (`/pricing.json`) does not list the Bookings plan yet.

## 7. Google Calendar (T22, owner's step)

Create the OAuth client in Google Cloud (Web application, redirect
`https://local.webscheduler.co.za/business/connections/google/callback`, scope
`https://www.googleapis.com/auth/calendar.events`), set `bookings.googleClientId` and
`bookings.googleClientSecret`, and apply for Google's verification of the sensitive scope.
Until verified, only test users added in Google Cloud can connect.

## 8. Privacy

Section 12a "Online bookings" in `app/Views/legal/privacy.php` shows only while bookings are
on. The owner reads it before the switch. The Terms may need a matching line for businesses
(the plan, deposits going to their own account, we are not a party to their bookings).

## 8a. local.webscheduler.app (T24)

- Nothing extra to switch: `bookings.enabled` covers both sites (`Config\Bookings::$sites`).
- The plan abroad needs the Plus rate: `usd_zar_rate@app` in `/admin/settings` (already set for
  Plus). Without it the trial runs and the plan shows as not on sale.
- Google Calendar: add the second redirect URI
  `https://local.webscheduler.app/business/connections/google/callback` to the OAuth client.
- Check both hosts after the switch: a London test profile books on .app in pounds, no deposit
  field, no rand anywhere; its `/business` and `/api/v1` only on .app; co.za pages unchanged
  (diff a co.za booking page against `main`).
- The .app privacy policy has an "Online bookings" part (shown only while bookings are on); the
  lawyer who reviewed the .app legal pages should read it, then bump `Legal::APP_LAST_UPDATED`.

## 9. First businesses

- Pick three to five co.za profiles that already use a paper diary or WhatsApp.
- Turn on for them, or send them to `/manage/bookings`; give extra free days from
  `/admin/bookings` if agreed.
- Watch `/admin/bookings` (setup "to fix", bookings in 30 days), `/admin/forms` and the logs for
  the first week.

## Rollback

`bookings.enabled = false` hides everything at once: booking pages, `/business`, the API,
emails (queued messages are cancelled at send time). Data stays. The migrations have `down()`
methods, but rolling them back deletes booking data: dump first (see `ci4-rollback` notes).
