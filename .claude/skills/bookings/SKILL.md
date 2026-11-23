---
name: bookings
description: Bookings inside WebScheduler Local — a Verified (co.za) or Plus (.app) profile takes online bookings. Where the spec lives (docs/bookings/), the frozen reference app (xscheduler_ci4) and how to port from it, the data model (listing_id on every booking table, own services and providers tables, snapshots), accounts and per business roles, scoping, settings, copy, and where each part lives in the code. Use for any work on accounts, /business booking pages, the calendar, availability, appointments, public booking on a profile, booking notifications, deposits, integrations or the booking API. Also load listing-service, and two-sites for anything a page shows.
---

# Bookings inside Local

A Verified (co.za) or Plus (.app) profile can turn on online bookings: a calendar for the
business, booking on the profile for customers, reminders, deposits. It is a port of
WebScheduler Bookings (a separate app, one install per business) into Local's one install,
one database.

**State:** live on production since 10 Nov 2026 (`bookings.enabled` on). Never free:
`BookingSetupService::whyNot()` and `PublicBookingService::takesBookings()` check
`listing_has_premium()`; a lapsed Verified/Plus stops booking and deletes nothing. There
is no separate Bookings plan (retired 23 Nov 2026, T32: `xs_booking_plans` and
`xs_booking_plan_payments` stay empty with no writer, `WSL-BKG-M-` is never reused).
Never bring a plan back, or SMS (T20), without the owner. Launch runbook:
`docs/bookings/launch.md`.

**Paths:** every booking page is under `/business` (the owner area every owner has, see
the listing-service skill, "Owner area"). Old `/bookings/*` GETs 301 and POSTs 307 there.
Pages use the default `businessauth` level and show `business/locked` when bookings are
not usable. Turning on is `POST business/bookings/turn-on` (`Business\Bookings`).

## Where things are

- **Spec:** `docs/bookings/`: README, `parity.md` (every feature: Port / Rebuild / New /
  Later / Skip), `data-model.md`, `permissions.md`, `routes.md`, `settings.md`, `copy.md`,
  `decisions.md` (T numbers), `launch.md`, `security-review.md`. Read the matching
  section before building, and change the spec in the same commit when the build differs.
- **Reference app (frozen, read only):** `/Volumes/Nilo_512GB/projects/xscheduler_ci4`
  (v2.7.0), skills in `.github/copilot/skills/` (scheduling, database, auth-rbac,
  notifications, public-booking, api-contract). Never edit it, run its migrations against
  Local's databases, or deploy it. Its server is `ssh dental-prod`: reading only.

## Porting from Bookings

Port logic and its tests, not shells:

1. Copy the Bookings test first, adapt it to `Tests\Support\FreshDatabase` with **two
   business fixtures**, and watch it fail.
2. Port the class to `App\Services\Bookings\…` (controllers `App\Controllers\Business\…`,
   models `Booking…Model`).
3. Standard adaptations (end of `parity.md`): scope by `listing_id`; settings from
   `BookingSettings::for($listingId)`; the profile timezone (`hours_context()`); mail via
   `Mailer`; one writer service per table; Local's layout, Lucide icons, no inline
   script, style or handler (CSP); copy rewritten to `copy.md`.
4. Don't port a Bookings gap as if it worked (`parity.md` lists them).

Rules that still hold: store UTC, convert only for display (`stored_timezone` is the zone
the appointment belongs to); provider hours are always read with a provider filter;
notifications are enqueued, never sent inside the request; calendar mutations reload the
server view model (`loadData()`).

## Data model in one screen

- A business is a profile: `listing_id` on every `xs_booking_*` table.
  `BookingScopedModel` (`Model::forBusiness($id)`) throws on unscoped find, count, update,
  delete or insert; a test proves one business never sees another's rows.
- **Own tables for services and providers** (`xs_booking_services`,
  `xs_booking_providers`): `ServiceMenuService` deletes and reinserts the profile's
  services on every save, so those ids are unstable.
- **One person, one record (T31):** every provider is its own team card
  (`team_member_id` unique). Name, position, qualifications, registration, bio and photo
  are the card, written only by `TeamMemberService` (`syncFromForm()`, `saveOne()` via
  `TeamService::saveProvider()`); the provider keeps colour, bookable, hours, services,
  sign in and a copy of name/title/bio. `show_on_profile` off = Bookings only (people
  added in Bookings start hidden). Removing a card Bookings still uses only hides it. Both
  forms render `directory/_person_fields.php`.
- Locations are the profile's address (`location_id` null) or a branch id.
- Appointments snapshot service name, duration, price, provider and location.
- People: `xs_accounts` (sign in) plus `xs_business_members` (account × listing × role
  admin, provider, staff). Customers have no accounts; they get hash and token links.
- `xs_directory_listings.bookings_enabled` plus the global `bookings.enabled` decide
  whether anything shows.

## Hard rules

- Every Bookings change updates the system handbook in the same commit: a new
  `xs_booking_*` table needs its `Config\Handbook::$tables` entry (product `bookings`;
  `SystemHandbookTest` fails without it), features in `app/Views/admin/handbook/bookings.php`,
  reference formats in `ids.php`, plus a dated `changelog.php` line.
- Nothing booking related may change discovery ranking or the quality score (one accepted
  exception, T28: the service mirror writes `xs_directory_listing_services`).
- Public profile pages stay sessionless. The booking flow starts a session only at the
  details step; `/business` is the only signed in area.
- Every new POST route goes into `Config\Forms` (`FormHealthServiceTest`), and every
  webhook gets the `payfast/notify` exemption pattern plus signature checks.
- Payment references come from `App\Libraries\PaymentReference` (`WSB-BOOK-`).
- Customer copy follows `docs/bookings/copy.md`: no dashes, no hyphenated words, "Book
  online", "online bookings".
- Both sites (T24): each business on its own site's host (`BusinessAuth` redirects GETs
  and refuses POSTs on the wrong host, `BookingApi` answers 404 `wrong_site`); clock and
  currency from `BusinessLocale` (never `Africa/Johannesburg` or `ZAR` by hand); no
  deposits abroad (Payments tab and deposit field hidden, `amountFor()` is rand only); no
  rand on any .app page. Tests: `tests/database/BookingAppSiteTest.php`.
- Transactions: `$db->resetTransStatus()->transStart()` and throw when `transComplete()`
  is false. Without the reset, strict mode silently rolls back after any earlier failure
  on the connection.
- `directory_hours` is **not** autoloaded: `helper('directory_hours')` before
  `hours_decode()` and the holiday helpers.
- Colours: `brand-ocean`, `primary-50..900`, `surface-*` (`tailwind.tokens.cjs`).
  `bg-ocean` does not exist. Provider and category colours are palette names turned into
  CSS by `CatalogueService::hex()`.

## Local dev

- `.env` has `bookings.enabled = true` (it is on in production too).
- `dev-owner@example.test` / `dev password 123` is admin of profile 2 and a provider on
  **profile 308, Dr Bertus Mensveld** (`/directory/dr-bertus-mensveld`), the owner's test
  case: Verified, two places (Birnam Park, Netcare Sunninghill branch), provider 6 ↔ team
  card 7, a never bookable receptionist card. Check booking changes there. (On production
  308 is a demo profile; the public demo is Fernwood, `spark demo:fernwood`.)
- Messages are only queued in a request: the launchd jobs from `npm run dev:install` run
  `bookings:dispatch` (every minute) and `bookings:payments` (every five) into Mailpit.
- Dev has its own `encryption.key`, so the Payments and WhatsApp forms work. PayFast's
  notice cannot reach localhost (a sandbox payment stays due unless marked paid);
  Paystack test keys are verified on return.
- Script: `resources/business/*.js` → Vite (`vite.business.config.mjs`) →
  `public/assets/business/business.js`; `npm run biz:build` (inside `list:build`),
  `biz:dev` to watch; `list:hot` does not watch it. Pages swap without reloads
  (`resources/shell/`): a page script registers with `registerPage(selector, init)` in
  `main.js` and returns a cleanup for its timers.

## Foundation: accounts, gate, settings

| Piece | Where |
|---|---|
| Flag, sites, lockout, session, setting defaults | `Config\Bookings` (pinned off in `tests/_support/bootstrap.php`) |
| Permissions | `Config\BookingPermissions`, read only by `BookingAuthorization` (`providerScope()`, `canManageProvider()`) |
| Services | `AccountService` (passwords, lockout per IP and account, hashed one use links), `BusinessMemberService`, `BookingSetupService` (only writer of `bookings_enabled`), `BookingSettings::for()`, `BookingAudit`, `BusinessContext` (`service('businessContext')`, `providerScope()`, `timezone()`), `BusinessSession`, `BusinessLocale` |
| Gate | `App\Filters\BusinessAuth` (`businessauth:guest` / `:account` / `:owner` / none: membership checked against the DB every request, `no-store`), `BusinessCan` (`businesscan:<permission>`, 403 page) |
| Pages | `Business\{Auth,Home,Account,Settings,Audit}`, views `app/Views/business/`, `layouts/business.php` (app shell; `main` needs `min-w-0` or a wide week grid widens the page), owner home `business/overview.php`, emails `emails/booking-*.php` |
| Settings tabs | `Business\Settings::TABS`: General, Booking rules, Booking form (questions: text, textarea, select, checkbox; `business/_question.php`), Messages, Payments, Connections, API keys, Local time |
| Tests | `BookingFoundationTest` |

## Catalogue, team, time off

| Piece | Where |
|---|---|
| Services, categories | `CatalogueService` (only writer of services, categories, provider_services; cents, 5 to 720 min in steps of 5, deposit needs a price, archive not delete) |
| People, hours, invitations | `TeamService` (only writer of providers, provider hours, staff assignments, invitations, roles; last admin stays; `ensureDefaultProvider()` on turn on) |
| Time off | `TimeOffService` (local input, UTC storage, whole business or one provider) |
| What is missing | `SetupCheck::problems()`, on `/business` and `php spark bookings:check [--business id]` |
| Pages | `Business\{Services,Team,TimeOff,Invite}` |
| Tests | `BookingCatalogueTest` |

**Schedule periods (T29):** `xs_booking_provider_hours` holds several periods per
weekday, each with `location_id` and `service_ids` (null = all). `TeamService::hours()`
returns `periods` per day, `hoursWarnings()` flags periods outside their place's hours.
`AvailabilityService::windows($p, $date, $serviceId)` clamps each period to
`businessWindow($date, $locationId)` (the branch's own hours and holidays); `locationAt()`
gives an appointment its place. `CatalogueService::listed()` vs `bookable()` (= listed +
`length_checked`): the profile shows listed, booking uses bookable. Tests
`BookingSchedulePeriodsTest`, `BookingProviderHoursModeTest`.

## Scheduling engine

| Piece | Where |
|---|---|
| Engine | `AvailabilityService($listing, ?$now)`: `slots()`, `slotsForAnyone()`, `problem()` (reason codes), `nextAvailable()`, `availableDates()`, `businessWindow()`, `periods()`, `weekPeriods()` |
| Creating | `BookingLock::reserve($listing, $data, $public, ?$now)` only: transaction, `SELECT … FOR UPDATE` on the provider row, `problem()` again, insert with snapshots, reference `WSB-BOOK-000123`. `BookingLock::move()` for reschedules |
| Rules | `booking.slot_step` (0 = service length), `booking.lead_minutes`, `business.buffer_time`, `business.future_limit` (`Config\Bookings::$settingRanges`) |
| Tests | `BookingAvailabilityTest` (fixed clock 1 Jan 2030 Johannesburg) |

Deliberate differences from Bookings: half open intervals (back to back is fine); gaps
are the service's before/after plus the business buffer, kept on each appointment
(`hold_before_min`, `hold_after_min`) and must fit inside working time; the business
window is the profile's hours, and a public holiday needs the owner's answer (none = no
online bookings); notice, horizon and grid apply to customers only, the business may book
any free minute; customers cannot book a person marked not bookable.

## Calendar, appointments, customers

| Piece | Where |
|---|---|
| Calendar models | `Services\Bookings\Calendar\`: `CalendarRange`, `TimeGrid`, `CalendarHours`, `EventLayout`, `CalendarQuery`, `DayView`, `WeekView`, `MonthView` |
| Appointments | `AppointmentService` (create, reschedule, status, notes, `findMatching()`, `notify()`), `AppointmentStatus` |
| Customers | `CustomerService` (`findOrCreate()` fills only empty fields, scoped `list()`/`history()`, `stats()`, `deletionImpact()`/`delete()`, custom fields 1 to 6) |
| Find | `BookingSearch`: `customers()`, `appointments()` ranked exact, prefix, fuzzy; names either way round, accents folded, phones by national digits, references, typos (Levenshtein, metaphone). Discover's `fold()`/`words()` are deliberately not used. Tests `BookingSearchTest` |
| Pages | `Business\Calendar` (JSON `business/api/calendar/{day,week,month,agenda}`), `Business\Appointments` (JSON `business/api/appointments/*`, form `business/appointments/new`), `Business\Customers` |
| Script | `resources/business/calendar/` (all copy in `messages.js`, checked by the copy test) |
| Tests | `BookingCalendarLayoutTest` (unit), `BookingCalendarTest`, `BookingAppointmentsTest` |

Rules: days are the business's local dates; a day shows a column for everyone in scope;
cancelled appointments stay off the grid unless filtered for; a customer with an upcoming
appointment cannot be deleted; JSON writes are POST; a booked service does not change
(cancel and book again). CSRF regenerates on every POST: each JSON answer carries
`csrf.hash` and `calendar/api.js` sends it as `X-CSRF-TOKEN`. Calendar classes are the
`.cal-*` components at the end of `resources/directory.css`; positions are set with
`element.style` (CSSOM, CSP allows it), never a style attribute.

## Public booking and the profile

| Piece | Where |
|---|---|
| Service | `PublicBookingService`: `takesBookings()`, `bookUrl()`, `services()`, `providersFor()`, `days()`, `times()`, `book()`, `byToken()`, `byCustomer()`, `reschedule()`, `cancel()`, `profileView()`, `profileServices()`, `practitioners()`, `weekLines()` |
| Controller | `App\Controllers\Book` (sessionless GET steps). Routes `directory/{slug}/book` (+ `/days`, `/slots`, `/terms`), `book/find`, `book/r/{ref}`, `book/customer/{hash}[/{appointment hash}]`, `book/{token}` (+ `/reschedule`, `/cancel`) |
| Views | `app/Views/book/*`, email `emails/booking-links.php`; on the profile `_panel_services.php` (booking rows replace the services list), `_team_panel.php` + `_team_booking.php`, Book online button in `_contact_panel.php`, `listing_books_online_badge()` on cards, ReserveAction in `schema_helper` |
| Service mirror (T28) | `CatalogueService::seedFromProfile()` on turn on, `mirrorToProfile()` after catalogue changes via `ServiceMenuService::mirror()`; the profile form shows services read only (`servicesFromBookings`) while the catalogue owns them |
| Tests | `BookingPublicTest`, `BookingProfileTest`, `BookingUrlTest` |

Rules: `takesBookings()` = flag, site allowed, the profile's own site, Verified/Plus,
`bookings_enabled`, published, and a bookable person offering a service. The change link
is a 64 hex token stored hashed, rotated on every change, ending 30 days after the
appointment; the customer page key is the customer's 64 char hash. A reference never opens
a booking. Pages with a key send `Referrer-Policy: no-referrer` and `no-store`. Page tests
run on the real clock: give the test business `booking.lead_minutes` 2880.

## Notifications

| Piece | Where |
|---|---|
| Catalogue | `Config\BookingNotifications`: events, default rules and templates, placeholders, retry limits |
| Services | `Services\Bookings\Notifications\`: `NotificationRules`, `NotificationTemplates`, `MessageContext` (values read at send time), `NotificationQueue`, `NotificationDispatcher`, `Channels` (email via Mailer; WhatsApp via Twilio or Meta, or a wa.me link), `BookingIntegrations` (encrypted accounts), `OptOuts`, `DeliveryLog`, `NotificationCentre` |
| Cron | `bookings:dispatch` every minute (MySQL `GET_LOCK`, 50 s box), `bookings:prune` nightly (`deploy/cron/webscheduler`) |
| Pages | Messages tab (`Business\NotificationSettings`), `business/notifications` (+ `/sent`, `.csv`), Send again in appointment details, public `book/unsubscribe/{customer hash}` (CSRF exempt, RFC 8058) |
| Mail | `Mailer::send()` with `fromName` and `headers`: From stays ours ("Business via WebScheduler Local"), Reply-To the business |
| Tests | `BookingNotificationsTest` (fixed clock 7 Jan 2030; `MockEmail`; fake `curlrequest`) |

Rules: nothing is sent in a request except the admin's WhatsApp test. Team messages only
for what the customer did online. A queued message is checked again at send time (bookings
on, rule on, account on, not opted out, status and fingerprint unchanged) or cancelled.
Customer emails link to the stable `book/customer/{customer}/{appointment}`. Sensitive
answers never reach a message; logs keep masked recipients. Unsubscribe can only stop
emails. WhatsApp accounts need `encryption.key`; Meta sends only approved templates with
five fixed values (first name, business, service, when, link).

## Dashboard and analytics

| Piece | Where |
|---|---|
| Dashboard | `DashboardService($listing, $scope, ?$now)`: `cards()`, `schedule()`, `waiting()`, `comingUp()`, `team()`, `alerts($member)`; `GET business/api/dashboard/today` |
| Analytics | `AnalyticsService`: `report($period, ?$providerId)`, periods `7d`, `30d`, `90d`, `12m`; `business/analytics` (`analytics.view`: admin and team member, not reception) |
| Cache | `BookingMetricsCache::remember()` (5 min, per business generation); `AppointmentService::notify()` calls `forget()`. Bump `SHAPE` when a cached array changes shape |
| Charts | `directory/_stacked_bar_chart.php`, `_meter_rows.php` (inline SVG, no Chart.js) |
| Tests | `BookingDashboardTest` |

"Value" is the price on pending, confirmed or completed appointments, never called
revenue. A no show took the time but has no value. Only figures the data carries.

## Deposits

| Piece | Where |
|---|---|
| Tables | `xs_booking_payments` (`BookingPaymentModel`), appointments `payment_due_by`, `BookingAppointmentModel::PAYMENT_*` |
| The account | `BookingIntegrations` channel `payment` (PayFast or Paystack keys, encrypted, write only); `GatewayConfig` → `Services::bookingPayFast()` / `bookingPaystack()` |
| Service | `Payments\DepositService`: only writer of payments and an appointment's payment columns (`ask()`, `start()`, `returned()`, `payfastNotified()`, `checkPaystack()`, `paid()` idempotent, `markPaid()`, `markRefunded()`, `sweep()`) |
| Routes | `book/{token}/pay`, `book/customer/{c}/{a}/pay`, `book/paid/{hash}`, `POST book/payfast/notify` (exempt from CSRF, BotTrap, honeypot; in `Config\Forms::$notForms`); `business/payments`, `business/api/appointments/{id}/payment` |
| Cron | `*/5 bookings:payments` |
| Tests | `BookingPaymentsTest` |

Money goes to the business, never us; no fee; refunds in the business's gateway. A deposit
is the service's percentage of a rand price, at least R5, only when an account is on; hold
`payments.hold_minutes` (30). PayFast needs a passphrase here. Paystack has no webhook:
verify on return and in the sweep. A late payment restores the booking if the time is
free, else "Deposit to refund". Test payments are marked `test`.

## Integrations and API

| Piece | Where |
|---|---|
| Outbox | `Integrations\Outbox` (only writer of `xs_booking_outbox`), run by `IntegrationWorker::run()` inside `bookings:dispatch` |
| Sync | `Integrations\IntegrationSync` (only writer of `video_link`, `video_meeting_id`, `google_event_id`) |
| Clients | `Integrations\Http` (never throws, no redirects), `Zoom`, `GoogleCalendar` (platform OAuth `bookings.googleClientId/Secret`), `Webhooks` (signed), `SafeUrl` (https, 443, public addresses; tests set `SafeUrl::$resolver`), `DeliveryModes` |
| API | `App\Filters\BookingApi` (`bookingapi:<scope>`: key, business on, 120 a minute, scope and role); `Api\ApiKeys` (`wsk_<prefix>_<secret>`, SHA-256); controllers `Api\V1\{Catalogue,Availability,Appointments,Customers}` |
| Docs | `docs/bookings/openapi.json` (`scripts/bookings-openapi.py`; a test keeps it equal to the routes), `/developers/bookings` |
| Tests | `BookingIntegrationsTest` |

Nothing calls out inside a request except Check / Send a test buttons and the Google
OAuth exchange. A webhook body is frozen at the event; Google and Zoom read the
appointment when the job runs. A key acts as its member: scopes narrow it, a removed
member's keys stop at once. `api/v1/*` is exempt from CSRF, BotTrap and honeypot,
sessionless, no CORS.

## Admin, privacy, scripts

| Piece | Where |
|---|---|
| Admin | `AdminBookings` (`/admin/bookings`: counts, Verified/Plus, setup, connections; support view audited as `support.opened`) |
| POPIA | `Privacy\Retention` (`bookings:retention`), `Privacy\CustomerExport` (`business/customers/{hash}/export`), privacy policy section 12a (shown while bookings are on) |
| Scripts | `scripts/bookings-restore-drill.php`, `scripts/bookings-load-test.sh` (needs `BOT_TOKEN` past ScrapeGuard) |
| Tests | `BookingLaunchTest` (includes the route guard test), `BookingsInPremiumCopyTest` |
