---
name: bookings
description: The Bookings rebuild inside WebScheduler Local (branch `bookings`, started Nov 2026) — any business profile can take online bookings. Where the spec lives (docs/bookings/), the frozen reference app (xscheduler_ci4) and how to port from it, the data model (listing_id on every booking table, own services and providers tables, snapshots), accounts and per business roles, scoping, settings, copy, phases and their status. Use for any work on accounts, /business, the calendar, availability, appointments, public booking on a profile, booking notifications, deposits, or the booking API. Also load listing-service, and two-sites for anything a page shows.
---

# Bookings inside Local

Any business profile can turn on online bookings: a calendar for the business, booking
on the profile for customers, reminders, deposits. It is a port of WebScheduler Bookings
(a separate app with one install per business) into Local's one install, one database.

## Status

| Phase | State |
|---|---|
| 0 Specification | done 4 Nov 2026, branch `bookings` (docs/bookings/) |
| 1 Foundation | built 4 Nov 2026 on `bookings-p1-foundation` (not merged): accounts, members, gate, settings, audit, /business shell, Vite |
| 2 Catalogue | built 4 Nov 2026 on `bookings-p2-catalogue` (off p1, not merged): services, categories, providers, hours, reception, time off, invitations, setup check |
| 3 Scheduling engine | built 4 Nov 2026 on `bookings-p3-scheduling` (off p2, not merged): AvailabilityService, BookingLock, appointments table, booking rule settings, /business/availability |
| 4 to 10 | not started; open owner decisions in docs/bookings/decisions.md |

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


## Next: Phase 4 (appointments and customers)

Branch `bookings-p4-appointments` off `bookings-p3-scheduling`. Scope from `parity.md` §4
and §7 and `routes.md`:

- `xs_booking_customers` (unique listing + email, 64 char hash) and the FK on
  `xs_booking_appointments.customer_id`; `xs_booking_appointment_fields`.
- `AppointmentService`: create (always through `BookingLock::reserve()`), edit, reschedule
  (`problem()` with `$excludeAppointmentId`), status changes (pending, confirmed,
  completed, cancelled, no show; port `AppointmentStatus`), notes. Scope every list by
  `BusinessContext::providerScope()`.
- `CustomerService`: create, edit, find, history, delete with impact preview, custom
  fields 1 to 6 (`booking.custom_field_*` settings).
- Calendar: port Bookings `app/Services/Calendar/*` (Day, Week, Month, TimeGrid,
  EventLayout) with their tests first, then `resources/js/modules/scheduler/*` into
  `resources/business/` (Vite), server mode only, drag to reschedule, details modal.
  Routes `/business/calendar`, `/business/api/calendar/{view}`, `/business/customers`.
- Not yet: notifications (Phase 6, enqueue only later), payments (Phase 8).
