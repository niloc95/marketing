---
name: bookings
description: The Bookings rebuild inside WebScheduler Local (branch `bookings`, started Nov 2026) — any business profile can take online bookings. Where the spec lives (docs/bookings/), the frozen reference app (xscheduler_ci4) and how to port from it, the data model (listing_id on every booking table, own services and providers tables, snapshots), accounts and per business roles, scoping, settings, copy, phases and their status. Use for any work on accounts, /business, the calendar, availability, appointments, public booking on a profile, booking notifications, deposits, or the booking API. Also load listing-service, and two-sites for anything a page shows.
---

# Bookings inside Local

Any business profile can turn on online bookings: a calendar for the business, booking
on the profile for customers, reminders, deposits. It is a port of WebScheduler Bookings
(a separate app with one install per business) into Local's one install, one database.

## Status

Phases 0 to 4 were merged to `main` on 4 Nov 2026 (`f3bb15b`), behind `bookings.enabled`
(off). Not released to production. Later phases branch off `main`.

| Phase | State |
|---|---|
| 0 Specification | done 4 Nov 2026, branch `bookings` (docs/bookings/) |
| 1 Foundation | built 4 Nov 2026 on `bookings-p1-foundation` (not merged): accounts, members, gate, settings, audit, /business shell, Vite |
| 2 Catalogue | built 4 Nov 2026 on `bookings-p2-catalogue` (off p1, not merged): services, categories, providers, hours, reception, time off, invitations, setup check |
| 3 Scheduling engine | built 4 Nov 2026 on `bookings-p3-scheduling` (off p2, not merged): AvailabilityService, BookingLock, appointments table, booking rule settings, /business/availability |
| 4 Appointments, customers, calendar | built 4 Nov 2026 on `bookings-p4-appointments` (off p3, not merged): calendar view models, scheduler JS, AppointmentService, CustomerService, customers table |
| 5 Public booking | built 4 Nov 2026 on `bookings-p5-public` (off `main`, not merged): booking page on the profile, change and cancel links, customer page, find by email, terms page, Book online button and badge |
| 6 Notifications | built 5 Nov 2026 on `bookings-p6-notifications` (off p5, not merged): queue, dispatcher, rules, reminders, templates, email, SMS and WhatsApp on the business's own account, delivery logs, opt out, notices |
| 7 Dashboard and analytics | built 5 Nov 2026 on `bookings-p7-dashboard` (off p6, not merged): figures, today by person with refresh, waiting list, free times with quick book, alerts, /business/analytics with four tabs |
| 8 Payments | built 5 Nov 2026 on `bookings-p8-payments` (off p7, not merged): deposits into the business's own PayFast or Paystack account, hold while paying, `bookings:payments` sweep, mark paid and refunded, /business/payments, Payments settings tab |
| 9 Integrations and API | built 5 Nov 2026 on `bookings-p9-integrations` (off p8, not merged): outbox run by `bookings:dispatch`, signed webhooks, Zoom and Jitsi meetings, Google Calendar push, API keys, `/api/v1`, OpenAPI file, `/developers/bookings` |
| 10 Hardening and launch | built 5 Nov 2026 on `bookings-p10-launch` (off p9, not merged): Bookings plan with trial, POPIA retention and export, `/admin/bookings`, route guard test, security review, restore drill and load test scripts, launch checklist. Production steps wait for the owner (docs/bookings/launch.md) |

Update this table and the decisions file as phases land.

## Where things are

- **Spec:** `docs/bookings/`. The README, `parity.md` (every feature: Port / Rebuild /
  New / Later / Skip, with its phase), `data-model.md`, `permissions.md`, `routes.md`,
  `settings.md`, `copy.md` and `decisions.md`. Read the matching section before building
  a feature, and change the spec in the same commit when the build differs.
- **Reference app (frozen, read only):** `/Volumes/Nilo_512GB/projects/xscheduler_ci4`
  (v2.7.0), with its skills in `.github/copilot/skills/` (scheduling, database,
  auth-rbac, notifications, public-booking, api-contract are the useful ones). Never
  edit it, never run its migrations against Local's databases, and never deploy it. Its
  production server is `ssh dental-prod`. Reading is fine there, and changes are out of
  scope.

## Porting from Bookings

Port logic and its tests, not shells. For each file:

1. Copy the Bookings test for it first, adapt it to `Tests\Support\FreshDatabase` with
   **two business fixtures**, and watch it fail.
2. Port the class to `App\Services\Bookings\…` (controllers `App\Controllers\Business\…`,
   models `Booking…Model`).
3. Apply the standard adaptations (end of `parity.md`):
   - scope by `listing_id`
   - read settings from `BookingSettings::for($listingId)`
   - use the profile timezone (`hours_context()`)
   - send mail through `Mailer`
   - keep one writer service per table
   - use Local's layout, Lucide icons and no inline script, style or handler (CSP)
   - rewrite the copy to `copy.md`
4. Don't port a Bookings gap as if it worked. `parity.md` lists them: webhook dispatch,
   Google Calendar push, blocked times UI, unsubscribe, per service buffers, lead time,
   2FA, refunds and others.

Bookings rules that still hold after the port:
- Store UTC, convert only for display. `stored_timezone` is the zone the appointment
  belongs to, not the input's zone.
- Provider hours are always read with a provider filter.
- Notifications are enqueued, never sent inside the request.
- Mutations from the calendar reload the server view model (`loadData()`).

## Data model in one screen

- A business is a profile: `listing_id` on every `xs_booking_*` table. `BookingScopedModel`
  refuses unscoped reads, and a test proves one business never sees another's rows.
- **Own tables for services and providers** (`xs_booking_services`, `xs_booking_providers`).
  Local's `ServiceMenuService` deletes and reinserts the profile's services on every save,
  so those ids are unstable. Team members and extra branches are Verified or Plus features.
  A provider may link to a team member, and a service to a profile service by name.
- Locations are the profile's address (`location_id` null) or a branch id.
- Appointments snapshot service name, duration, price, provider and location.
- People: `xs_accounts` (sign in) plus `xs_business_members` (account × listing × role
  admin, provider, staff). Customers have no accounts. They get hash and token links.
- `xs_directory_listings.bookings_enabled` plus the global flag `bookings.enabled` decide
  whether anything shows.

## Hard rules

- Nothing booking related may change discovery ranking or the quality score.
- Public profile pages stay sessionless. The booking flow starts a session only at the
  details step, and `/business` is the only place with a signed in session.
- Every new POST route goes into `Config\Forms` (`FormHealthServiceTest` enforces it), and
  every webhook gets the `payfast/notify` exemption pattern plus signature checks.
- Payment references come from `App\Libraries\PaymentReference` (`WSB-BOOK-`, reserved
  for this).
- Customer copy follows `docs/bookings/copy.md`. No dashes, no hyphenated words, "Book
  online", no rand on .app.
- Check both sites. Bookings is co.za first (decision D2), and .app must show nothing of
  it until decided.

## Phase 1 in the code

| Piece | Where |
|---|---|
| Flag, sites, lockout, session length, setting defaults | `Config\Bookings` (`bookings.enabled`, off by default and pinned off in `tests/_support/bootstrap.php`) |
| Permission matrix, role labels | `Config\BookingPermissions`, read only by `BookingAuthorization` |
| Tables | migration `2026-11-12-100000_CreateBookingFoundation`: `xs_accounts`, `xs_business_members`, `xs_booking_settings`, `xs_booking_audit_logs`, listings `bookings_enabled(_at)` |
| Scoping | `App\Models\Bookings\BookingScopedModel`: `Model::forBusiness($id)`; unscoped find, count, update, delete or insert throws |
| Services | `App\Services\Bookings\`: `AccountService` (passwords, lockout per IP and per account, hashed one use links), `BusinessMemberService`, `BookingSetupService` (turn on and off, the only writer of `bookings_enabled`), `BookingSettings::for()`, `BookingAudit`, `BookingAuthorization`, `BusinessContext` (`service('businessContext')`), `BusinessSession` (session keys) |
| Gate | `App\Filters\BusinessAuth` (`businessauth:guest` / `:account` / none: membership checked against the DB every request, `no-store`), `BusinessCan` (`businesscan:<permission>`, 403 page) |
| Pages | `App\Controllers\Business\{Auth,Home,Account,Settings,Audit}`, `ManageBookings` (`/manage/bookings`), views `app/Views/business/`, `layouts/business.php`, owner panel `directory/_bookings_panel.php`, emails `emails/booking-*.php` |
| Script | `resources/business/*.js` → Vite (`vite.business.config.mjs`) → `public/assets/business/business.js`; `npm run biz:build` (also inside `list:build`), `biz:dev` to watch. `list:hot` does not watch it. |
| Tests | `tests/database/BookingFoundationTest.php` (two businesses, flag, scope, turn on, lockout, links, gate, roles, audit, copy) |

**Local dev:** `.env` has `bookings.enabled = true` on this branch's machine. Dev account
`dev-owner@example.test` / `dev password 123` is admin of profile 2 in the dev DB.
Emailed links land in Mailpit. Switch the flag off (and `npm run dev:restart`) when back
on `main`.

## Phase 2 in the code

| Piece | Where |
|---|---|
| Tables | migration `2026-11-13-100000_CreateBookingCatalogue` (see data-model.md) |
| Services and categories | `CatalogueService` (only writer of services, categories, provider_services; cents, 5 to 720 min in steps of 5, deposit needs a price, archive not delete) |
| People, hours, reception, invitations, roles | `TeamService` (only writer of providers, provider hours, staff assignments, invitations and roles; hours rules from Bookings' ScheduleValidationService; last admin stays; `ensureDefaultProvider()` on turn on) |
| Time off | `TimeOffService` (local input, UTC storage, whole business or one provider) |
| Scope | `BookingAuthorization::providerScope()` / `canManageProvider()`, `BusinessContext::providerScope()` and `timezone()` |
| What is missing | `SetupCheck::problems()`, shown on /business and by `php spark bookings:check [--business id]` |
| Pages | `Business\{Services,Team,TimeOff,Invite}`, views `business/{services,service_form,team,provider,time_off,invite}.php`, email `emails/booking-invite.php` |
| Tests | `tests/database/BookingCatalogueTest.php` |

**Transactions:** call `$db->resetTransStatus()->transStart()` and throw when
`transComplete()` is false. Without the reset, strict mode rolls a transaction back
silently after any earlier failure on the connection (it broke the random order suite).

**Colours:** the palette is `brand-ocean`, `primary-50..900`, `surface-*`
(`tailwind.tokens.cjs`). `bg-ocean` does not exist and compiles to nothing.

## Phase 3 in the code

| Piece | Where |
|---|---|
| Appointments table | migration `2026-11-14-100000_CreateBookingAppointments`, `BookingAppointmentModel` (`HOLDS_TIME` = every status but cancelled). Customer FK arrives in Phase 4 |
| Engine | `AvailabilityService($listing, ?$now)`: `slots()`, `slotsForAnyone()`, `problem()` (reason codes), `nextAvailable()`, `availableDates()`, `businessWindow()`, `holdMinutes()` |
| Creating an appointment | `BookingLock::reserve($listing, $data, $public, ?$now)` only: transaction, `SELECT ... FOR UPDATE` on the provider row, `problem()` again, insert with snapshots, reference `WSB-BOOK-000123` |
| Rules | `booking.slot_step` (0 = service length), `booking.lead_minutes`, `business.buffer_time`, `business.future_limit` (`Config\Bookings::$settingRanges`), tab "Booking rules" |
| Pages | `Business\Availability` (`/business/availability`, JSON `/business/api/availability/slots`, scoped to the member's providers) |
| Tests | `tests/database/BookingAvailabilityTest.php` (fixed clock 1 Jan 2030 Johannesburg) |

**Engine rules (deliberate differences from Bookings):** half open intervals, so back to
back bookings are fine; busy time is anything overlapping the day; gaps are the service's
before and after plus the business buffer, kept on each appointment
(`hold_before_min`, `hold_after_min`); the gaps must fit inside working time; the business
window is the profile's hours per weekday and a public holiday needs the owner's answer
(none means no online bookings); minimum notice, the horizon and the grid apply to
customers, the business may book any free minute in working time; customers cannot book
a person marked not bookable.

**Helpers:** `directory_hours` is **not** autoloaded. Call `helper('directory_hours')`
before `hours_decode()` and the holiday helpers (Phase 2 shipped without it and only
worked when another test had loaded it).


## Phase 4 in the code

| Piece | Where |
|---|---|
| Tables | migration `2026-11-15-100000_CreateBookingCustomers`: `xs_booking_customers` (unique listing + email, 64 char hash), `xs_booking_appointment_fields`, appointment `customer_id` FK (SET NULL) |
| Calendar models | `App\Services\Bookings\Calendar\`: `CalendarRange`, `TimeGrid`, `CalendarHours` (replaces ProviderWorkingHoursTrait and the fixed work window), `EventLayout`, `CalendarQuery` (replaces AppointmentQuery and Formatter), `DayView`, `WeekView`, `MonthView` |
| Appointments | `AppointmentService` (create via `BookingLock::reserve()`, reschedule via `BookingLock::move()`, status, notes, `findMatching()`), `AppointmentStatus` (`no_show`, labels from copy.md) |
| Customers | `CustomerService` (save, `findOrCreate()` fills only empty fields, scoped `list()` and `history()`, `stats()`, `deletionImpact()` and `delete()`, custom fields 1 to 6) |
| Pages | `Business\Calendar` (`/business/calendar`, JSON `/business/api/calendar/{day,week,month,agenda}`), `Business\Appointments` (JSON `/business/api/appointments/*`, plain form `/business/appointments/new`), `Business\Customers` (`/business/customers*`, JSON `/business/api/customers?q=`) |
| Script | `resources/business/calendar/` (scheduler core, time grid, month, list, drag, details, Book dialog; all copy in `messages.js`, checked by the copy test) |
| Tests | `tests/unit/BookingCalendarLayoutTest.php`, `tests/database/BookingCalendarTest.php`, `tests/database/BookingAppointmentsTest.php` |

**Deliberate differences from Bookings:** days are the business's local dates (Bookings
grouped on the UTC date); a day shows a column for everyone in scope, not only people with
appointments; the grid widens to the profile's hours, people's hours and any appointment
outside them; cancelled appointments stay off the grid unless the status filter asks;
a customer with an upcoming appointment cannot be deleted, otherwise past appointments
stay without the person (Bookings refused delete whenever any appointment existed); the
list view shows the week; JSON writes are POST, not PATCH; the service of a booked
appointment does not change (cancel and book again); a cancelled appointment comes back
only if its time is still free.

**JSON and CSRF:** the token regenerates on every POST. Each JSON answer carries
`csrf.hash`; `resources/business/calendar/api.js` sends it as `X-CSRF-TOKEN`.

**Styles:** Tailwind now scans `resources/business/**/*.js` too; calendar classes are the
`.cal-*` components at the end of `resources/directory.css`. Positions are set with
`element.style` (CSSOM), which CSP allows; never write a style attribute.

## Phase 5 in the code

| Piece | Where |
|---|---|
| Service | `PublicBookingService` (`takesBookings()`, `bookUrl()`, `services()`, `providersFor()`, `days()`, `times()`, `book()`, `byToken()`, `byCustomer()`, `customerAppointments()`, `refusal()`, `reschedule()`, `cancel()`, `emailLinks()`). Writes go through `AppointmentService::issuePublicToken()`, `moveForCustomer()`, `cancelForCustomer()` |
| Controller | `App\Controllers\Book` (public, sessionless GET steps). Routes `directory/{slug}/book` (+ `/days`, `/slots` JSON, `/terms`), `book/find`, `book/r/{ref}`, `book/customer/{hash}[/{appointment hash}]`, `book/{token}` (+ `/reschedule`, `/cancel`) |
| Views | `app/Views/book/*` (page, manage, customer, terms, find, expired, `_when`), email `emails/booking-links.php` |
| Profile | `Directory::show` sets `_book_url`; `_contact_panel.php` Book online button (own page wins over `booking_url`); `schema_helper` ReserveAction; `_card.php` `listing_books_online_badge()` |
| Settings | Booking rules tab: change and cancel hours, online status; Booking form tab (was Questions): customer fields, questions, the business's terms |
| Tests | `tests/database/BookingPublicTest.php` |

**Rules:** `takesBookings()` = flag, site allowed (co.za only, D2), the profile's own site,
`bookings_enabled`, published, and a bookable person offering a service. The change or
cancel link is a 64 hex token stored hashed, rotated on every change, ending 30 days after
the appointment; the customer page key is the customer's 64 char hash. A reference never
opens a booking. Pages with a key send `Referrer-Policy: no-referrer` and `no-store`.
Customers keep their name: `findOrCreate()` fills only empty fields.

**Testing gotcha:** the page tests run on the real clock; give the test business
`booking.lead_minutes` 2880 so the first free time is outside the 24 hour windows.

**D3 settled (T14, 5 Nov 2026):** the profile keeps its own services list; bookings only add
the Book online button and the booking page.

## Phase 6 in the code

| Piece | Where |
|---|---|
| Tables | migration `2026-11-16-100000_CreateBookingNotifications`: rules, templates, queue, delivery logs, opt outs, inbox, integrations (all `xs_booking_*`, scoped models) |
| Catalogue | `Config\BookingNotifications`: events (pending, confirmed, rescheduled, cancelled, reminder, no show), default rules, default customer templates, fixed team templates, placeholders, retry limits |
| Services | `App\Services\Bookings\Notifications\`: `NotificationRules`, `NotificationTemplates`, `MessageContext` (values read at send time), `NotificationQueue` (enqueue, reminders, team recipients), `NotificationDispatcher`, `Channels` (Mailer, Clickatell, Twilio, Meta), `BookingIntegrations` (encrypted accounts), `OptOuts`, `DeliveryLog`, `NotificationCentre` |
| Hooks | `AppointmentService::notify()` after create, reschedule, status, customer move and cancel; `PublicBookingService::book()` |
| Cron | `bookings:dispatch` each minute (MySQL `GET_LOCK`, 50 s time box), `bookings:prune` nightly; both in `deploy/cron/webscheduler` |
| Pages | Settings tab Messages (`Business\NotificationSettings`), `/business/notifications` (Notices, every member, own email switch), `/business/notifications/sent` (+ `.csv`), "Messages" and "Send again" in the appointment details (`POST /business/api/appointments/{id}/notify`), customer page "Send booking emails again", public `book/unsubscribe/{customer hash}` (GET asks, POST acts, CSRF exempt for RFC 8058) |
| Mail | `Mailer::send()` takes `fromName` and `headers`; From stays ours ("Business via WebScheduler Local"), Reply-To the business |
| Tests | `tests/database/BookingNotificationsTest.php` (fixed clock 7 Jan 2030; `MockEmail` archive for headers, `returnValue = false` for failures; fake `curlrequest` for providers) |

**Rules worth knowing:**
- Nothing is sent in a request except the admin's own WhatsApp test.
- Team messages and Notices only for what the customer did online.
- A queued message is checked again at send time: bookings on, rule on, account on, not opted out, and still true (status, fingerprint). Otherwise it is cancelled, never sent.
- Customer emails link to `book/customer/{customer}/{appointment}`. That link is stable, unlike the rotated change token, which a queued message could not carry.
- Sensitive answers never reach a message, and the delivery logs keep masked recipients only.
- The unsubscribe POST can only stop emails. Only the business can switch them back on.
- **SMS was removed (owner, 5 Nov 2026, T20):** channels are email and WhatsApp only; never add SMS back without the owner.
- WhatsApp accounts need `encryption.key`. Without it the tab says they are not available, and nothing is saved in plain text. Dev has no key, so dev shows that state. Decision T21: set the key on production before release.
- WhatsApp has three modes: "link" (a wa.me button in the details, never queued), Twilio (body text), and Meta, which sends only approved templates with five fixed values: first name, business, service, when, link.

## Phase 7 in the code

| Piece | Where |
|---|---|
| Dashboard | `DashboardService($listing, $scope, ?$now)`: `cards()` (cached), `schedule()`, `waiting()`, `comingUp()`, `team()` (state now, free times, next free), `alerts($member)` |
| Analytics | `AnalyticsService($listing, $scope, ?$now)`: `report($period, ?$providerId)`, periods `7d`, `30d`, `90d`, `12m` (by month); one query per period, summed in PHP, cached |
| Cache | `BookingMetricsCache::remember()` (5 min, key carries a per business generation); `AppointmentService::notify()` calls `forget()` after every change. Bump `SHAPE` when a cached array changes shape |
| Pages | `Business\Home` (`/business`, `GET /business/api/dashboard/today` = `_dash_today.php` partial), `Business\Analytics` (`/business/analytics`, `businesscan:analytics.view`: admin and team member, not reception); quick book links prefill `/business/appointments/new?provider_id&service_id&date&time` |
| Views | `business/home.php`, `_dash_today.php`, `analytics.php`; charts are `directory/_stacked_bar_chart.php` and `_meter_rows.php` (inline SVG, as the admin dashboard; no Chart.js) |
| Script | `resources/business/dashboard.js` (refresh every 2 min while visible, person colour dots via CSSOM, `data-autosubmit` selects) |
| Tests | `tests/database/BookingDashboardTest.php` (bookings on a clock of 1 Dec 2029, dashboard read Monday 7 Jan 2030 10:30) |

**Rules:** "Value" is the price on appointments that are pending, confirmed or completed;
it is never called revenue or money received (that is Phase 8). A no show is booked (it
took the time) but has no value. Customers are counted in scope. Only figures the data
carries: Bookings' utilisation, monthly retention and forecast were invented and are not
ported. A holiday alert only fires inside the booking horizon (30 days at most) and not on
a weekday the profile is closed anyway.

## Phase 8 in the code

| Piece | Where |
|---|---|
| Tables | migration `2026-11-17-100000_CreateBookingPayments`: `xs_booking_payments` (`BookingPaymentModel`), appointments `payment_due_by`; payment statuses on `BookingAppointmentModel::PAYMENT_*` |
| The account | `BookingIntegrations` channel `payment` (payfast: merchant id, key, passphrase, mode; paystack: secret key), encrypted, write only. `GatewayConfig` hands the keys to Local's own `PayFast` and `Paystack` clients; `Services::bookingPayFast()` / `bookingPaystack()` build them (tests inject a closure) |
| Service | `Payments\DepositService`: `amountFor()`, `depositFor()`, `ask()`, `start()`, `returned()`, `payfastNotified()`, `checkPaystack()`, `paid()` (idempotent), `markPaid()`, `markRefunded()`, `summary()`, `forCustomer()`, `payments()`, `toRefund()`, `receivedThisMonth()`, `sweep()`. The only writer of payments and of an appointment's payment columns |
| Booking | `PublicBookingService::book()`: with a deposit the booking is pending with `payment_status` due and no messages; email is always asked (`customerFields(true)`) |
| Customer pages | `book/manage.php` Deposit panel, `POST book/{token}/pay` and `book/customer/{c}/{a}/pay` (`Book::payDeposit()`), `book/pay.php` (PayFast form, honeypot exempt), `GET book/paid/{hash}`, `POST book/payfast/notify` (CSRF, BotTrap, honeypot exempt; in `Config\Forms::$notForms`) |
| Business | Settings tab Payments (`Business\PaymentSettings`, `_settings_payments.php`, check button, email to every admin on a change), `/business/payments` (`Business\Payments`), `POST business/api/appointments/{id}/payment` (details panel buttons), nav item only once an account is on, dashboard refund alert and deposits in the value tile, SetupCheck `deposit_no_account` |
| Cron | `*/5 bookings:payments` (`BookingsPayments`, MySQL lock) in `deploy/cron/webscheduler` |
| Tests | `tests/database/BookingPaymentsTest.php` (FakePaystack, a PayFast subclass whose IP and POST-back checks say yes) |

**Rules:** money goes to the business, never us (T15); no fee; refunds in the business's
gateway (T16). A deposit is the service's percentage of a rand price, at least R5, asked
only when an account is on. The hold is `payments.hold_minutes` (30). PayFast needs a
passphrase here (PayFast lets it be empty). Paystack has no webhook: verify on return and
in the sweep. A late payment restores the booking if the time is free, else "Deposit to
refund". Test mode (PayFast sandbox, `sk_test_`) shows on the customer page and marks rows
`test`; test payments are left out of "received this month".

**Dev:** since 5 Nov 2026 the local `.env` has its own dev only `encryption.key`, so the
Payments and WhatsApp forms work on dev (before that they showed "not available").
PayFast's notice cannot reach localhost, so a sandbox payment there stays due unless
marked paid; Paystack test keys are verified on return. Production needs the key
before release (T21, owner OK at that step), plus the new cron line.

## Phase 9 in the code

| Piece | Where |
|---|---|
| Tables | migration `2026-11-18-100000_CreateBookingIntegrations`: `xs_booking_api_keys` (`BookingApiKeyModel`), `xs_booking_outbox` (`BookingOutboxModel`), appointments `video_meeting_id`, `google_event_id` |
| Accounts | `BookingIntegrations` channels `zoom`, `jitsi` (typed in, `save()`) and `google`, `webhook` (made by us, `store()`); `canEncrypt()`, `setActive()` |
| Outbox | `Integrations\Outbox` (only writer of the outbox): `appointmentChanged($id, created|rescheduled|status)` from `AppointmentService::notify()` (new 4th argument `$change`), `depositPaid()` from `DepositService::paid()`, release of an unpaid hold, `backfillGoogle()`, `dropQueued()`, `retry()` |
| Worker | `Integrations\IntegrationWorker::run()` inside `bookings:dispatch`, before the messages; retries `Config\Bookings::$outboxRetryMinutes`, then failed; `bookings:prune` deletes finished rows after `$outboxKeepDays` |
| Sync | `Integrations\IntegrationSync` (only writer of `video_link`, `video_meeting_id`, `google_event_id`, raw update so `updated_at` stays): `jitsi()` at once, `zoom()`, `google()`, `event()` |
| Clients | `Integrations\Http` (never throws, no redirects, `request()` on the shared `curlrequest`), `Zoom`, `GoogleCalendar` (platform OAuth client `bookings.googleClientId/Secret`, T22), `Webhooks` (events, signing, test, deliveries), `SafeUrl` (https, port 443, public addresses only; tests set `SafeUrl::$resolver`) |
| Delivery mode | `Integrations\DeliveryModes::offered()/pick()/label()`; `AppointmentService::create($input, $source)` and `PublicBookingService::book()` take `delivery_mode`; the booking page asks "How would you like it?" when a service has more than one way; `_appointment_fields.php` has a How select; SetupCheck `zoom_no_account` |
| Messages | `{video_link}` placeholder, "Join online" in the email details, "Where: Online"; `NotificationDispatcher` holds a customer message up to 10 minutes while a Zoom link is coming |
| API | `App\Filters\BookingApi` (`bookingapi:<scope>`: key, business on, 120 a minute, scope and role) fills `businessContext` (`setApiKey()`); `Api\ApiKeys` (only writer of keys, `wsk_<prefix>_<secret>`, SHA-256, `SCOPES` scope => [label, permission]); `Api\Resources` (shapes shared with webhooks); controllers `App\Controllers\Api\V1\{ApiController,Catalogue,Availability,Appointments,Customers}` |
| Docs | `docs/bookings/openapi.json` (generated by `scripts/bookings-openapi.py`, kept equal to the routes by `testTheOpenApiFileDescribesEveryRouteAndNothingElse`), `Developers::bookings` + `views/developers/bookings.php` (rendered from the file; Redoc would need inline styles) |
| Pages | Settings tabs Connections (`Business\ConnectionSettings`, `_settings_connections.php`) and API keys (`Business\ApiKeys`, `_settings_api.php`, key shown once by flash) |
| Tests | `tests/database/BookingIntegrationsTest.php` (fake `curlrequest` with `request()` answering by URL part; fixed clock 7 Jan 2030 for the worker) |

**Rules:** nothing calls out inside a request except the admin's Check and Send a test
buttons (and the Google OAuth exchange). A webhook body is frozen when the event happens;
Google and Zoom read the appointment when the job runs. A key acts as its member: scopes
narrow it, the role still applies, and a removed member's keys stop at once. `api/v1/*` is
exempt from CSRF, BotTrap and honeypot, sessionless, and has no CORS. On release:
`encryption.key` (T21) and, for Google, the OAuth client and its verification (T22, the owner's step).

## Phase 10 in the code

| Piece | Where |
|---|---|
| Plan | migration `2026-11-19-100000_CreateBookingPlans`, `BookingPlanModel` (not scoped), `Plans\BookingPlanService` (`required()`, `allows()`, `status()`, `startTrial()` from `BookingSetupService::turnOn()`, `people()`, `priceFor()`, `checkout()` (also a move to the right tier; the old subscription is disabled once the new one is paid), `rowForCharge()`, `recordPayment()` once per Paystack id, `cancel()`, `extendTrial()`, `sweep()`). Config `planRequired`, `planTiers` (T23: Solo 1 person R160, Team up to 5 R420, Business R890; `bookings.planPrices` overrides; `planTierFor()`), `trialDays` 30, `planGraceDays` 3 (`bookings.*` in .env). Reference `WSL-BKG-M-000123` |
| Paystack | `PaystackPayments` routes our plan's charges (reference, `booking_plan_id` metadata, or the mandate) and its subscription events to the plan service; Paystack plan name "WebScheduler Local online bookings, monthly" |
| Enforced | `PublicBookingService::takesBookings()`, `BookingApi` (403 `plan_required`), SetupCheck `plan_ended`, banner in `layouts/business.php`. `/business` itself always works |
| Pages | Settings tab Plan (`Business\PlanSettings`, `_settings_plan.php`, hidden while not required), email `emails/booking-plan.php`, offer lines on `/manage/bookings` and the owner panel |
| POPIA | `Privacy\Retention` (`bookings:retention`), `Privacy\CustomerExport` (`/business/customers/{hash}/export`, admins), privacy policy section 12a (shown only while bookings are on; owner to read) |
| Admin | `AdminBookings` (`/admin/bookings`: counts, plan, setup, connections; Add free days; support view with a reason, audited as `support.opened`) |
| Scripts | `scripts/bookings-restore-drill.php` (restore a dump into the test DB, compare every booking table, reset), `scripts/bookings-load-test.sh` (ab; needs `BOT_TOKEN` past ScrapeGuard) |
| Tests | `tests/database/BookingLaunchTest.php` (FakePaystack; includes the route guard test) |

## Next

Release, step by step with the owner: docs/bookings/launch.md.

**Custom fields (built):** Settings, Questions tab (`/business/settings/questions`). Types
text, textarea, select (choices one per line in `booking.custom_field_N_options`) and
checkbox (stored as "Yes"). One partial renders them: `business/_question.php`.

**Browser check (4 Nov 2026):** Playwright with Chromium is in node_modules; a headless
run booked, opened details, saved notes and status, dragged to a new time, was refused an
overlap, and walked day, month, list, Find, dark mode and 390px width with no console or
CSP errors. Note `layouts/business.php` `main` needs `min-w-0`: the body is a flex
container, so a wide week grid otherwise widens the page. Provider and category colours
are palette names (`ocean`), turned into CSS by `CatalogueService::hex()`.

## Open housekeeping (as of 4 Nov 2026)

- **The dev site serves the bookings branches.** The local `.env` has
  `bookings.enabled = true` (appended at the end of the file). Before another session
  works on `main`: switch to `main`, remove that line (or set it to `false`) and run
  `npm run dev:restart`. Put it back when returning to a bookings branch.
- **A test order leak already on `main`, not caused by bookings.** In random order,
  `NewsletterTest::testAnAppSignupIsAnAppRowWithAppLinks` followed by
  `SpatialSearchTest::testPositionsOutsideSouthAfricaAreIgnored` fails: the newsletter test
  leaves the shared `site` service on .app. Reproduce with
  `php vendor/bin/phpunit --filter 'testAnAppSignupIsAnAppRowWithAppLinks|testPositionsOutsideSouthAfricaAreIgnored'`.
  Fix it on its own branch off `main` (reset the site service in NewsletterTest's
  tearDown), not on a bookings branch.
