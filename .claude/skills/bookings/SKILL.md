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
| 7 to 10 | not started; open owner decisions in docs/bookings/decisions.md |

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
- Nothing is sent in a request except the admin's own SMS or WhatsApp test.
- Team messages and Notices only for what the customer did online.
- A queued message is checked again at send time: bookings on, rule on, account on, not opted out, and still true (status, fingerprint). Otherwise it is cancelled, never sent.
- Customer emails link to `book/customer/{customer}/{appointment}`. That link is stable, unlike the rotated change token, which a queued message could not carry.
- Sensitive answers never reach a message, and the delivery logs keep masked recipients only.
- The unsubscribe POST can only stop emails. Only the business can switch them back on.
- SMS and WhatsApp accounts need `encryption.key`. Without it the tab says they are not available, and nothing is saved in plain text. Dev has no key, so dev shows that state. Decision D14: set the key on production before release.
- WhatsApp has three modes: "link" (a wa.me button in the details, never queued), Twilio (body text), and Meta, which sends only approved templates with five fixed values: first name, business, service, when, link.

## Next: Phase 7

Not started. Read `parity.md` and `README.md` for its scope before starting.

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
