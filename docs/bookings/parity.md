# Feature parity matrix

Every Bookings feature with its decision and phase. Sources: the Bookings code and its 13
skills (`xscheduler_ci4/.github/copilot/skills/`), read 1 to 4 Nov 2026.

**Decisions**

- **Port**: Bookings' logic and tests move across with the standard adaptations (see the
  end of this file).
- **Rebuild**: same behaviour, new code, because the Bookings version assumes a single
  business or doesn't fit Local.
- **New**: Bookings describes it but never built it, or it is needed only because many
  businesses share one install. Build it properly.
- **Later**: wanted, but after launch (Phase 10). Listed so it is not forgotten.
- **Skip**: not needed on one hosted install.

Items marked ⚠ have an open decision in [decisions.md](decisions.md).

## 1. Accounts and sign in

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Email + password sign in, sign out | `Auth.php`, session `user{role, roles[], active_role}` | Rebuild | 1 | `xs_accounts`; session only under `/business` |
| Owner claims an account | none | New | 1 | from the `/manage` magic link: redeem, then set a password |
| Lockout 5 attempts per 15 min per IP | CI4 Throttler | Port | 1 | plus a per account key so one IP cannot try many accounts slowly |
| Password reset (1 h token) | `reset_token`, `reset_expires` | Rebuild | 1 | hashed token (`TokenHash`), sent through `Mailer` |
| Inactivity warning, keepalive ping, cross tab sync | `inactivity-monitor.js`, `GET /auth/ping` | Port | 1 | JS moves as is; 2 h session |
| Profile page (name, photo, password, notification preference) | `Profile.php`, `ProfilePageService` | Rebuild | 1 | account level, not business level |
| Switch active role | `POST /api/auth/switch-role` | Rebuild | 1 | becomes "switch business" plus role per business |
| Remember me | checkbox the server ignores | Later | | |
| Two factor sign in | not built | Later ⚠ | | |
| Customer accounts | not built (customers use hash links) | Skip | | customers stay link based |

## 2. Roles and permissions

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Roles admin, provider, staff | `xs_user_roles`, global | Rebuild | 1 | per business in `xs_business_members`; an account can hold different roles in different businesses |
| Permission matrix | `UserPermissionModel::ROLE_PERMISSIONS` (hard coded) | Port | 1 | as a config class, see [permissions.md](permissions.md) |
| Per user permission overrides | `xs_users.permissions` JSON | Skip | | unused in the UI |
| Provider sees own, staff sees assigned providers | `CalendarController`, `CustomerAppointmentService` | Port | 1, 4 | one `BookingAuthorization` service |
| Route filters `auth`, `role:` | `RoleFilter` | Rebuild | 1 | `businessauth` + `businessrole:` filters |
| API token identity | `ApiAuthFilter`, `ApiIdentity` | Port | 9 | key bound to a membership, not a global user |

## 3. Dashboard and analytics

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Stat cards (today, customers, pending payments, revenue this month) | `DashboardService` | Port | 7 | cached per business, 5 min |
| Provider cards with free slots, quick book | `/api/dashboard/provider-slots` | Port | 7 | |
| Today's schedule with refresh | `dashboard-refresh.js` | Port | 7 | |
| Role alerts (pending confirmations, missing hours, booking off, closures, overbooking) | `AuthorizationService` | Port | 7 | |
| Analytics: overview, revenue, customers, providers | `AnalyticsService`, `BookingMetricsService`, Chart.js | Port | 7 | Chart.js in the dashboard bundle only; public pages keep inline SVG charts |
| Business stats on the public profile | Local `ListingStatsService` | keep | | separate from booking analytics; `taps_booking` stays |

## 4. Calendar and appointments

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Day, week, month, agenda, today views | `Services/Calendar/*` view models, `scheduler/*` JS (~9.3k) | Port | 4 | server mode only; drop the flat fallback mode. Built: day, week, month and a week list; the separate today view and the right panel are not carried |
| Overlap layout, DST safe time grid | `EventLayoutService`, `TimeGridService` | Port | 4 | port their tests first |
| Drag to reschedule | `scheduler-drag-drop.js` | Port | 4 | server checks conflicts again |
| Create and edit form, inline customer create | `Appointment/*` services, `appointments-form.js` | Port | 4 | |
| Statuses pending, confirmed, completed, cancelled, no show | `AppointmentStatus` | Port | 4 | `booked` and `rescheduled` aliases dropped (data is new) |
| Details modal: status, notes, mark paid, resend | `appointment-details-modal.js` | Port | 4 | |
| Filters by provider, service, location, status | `status-filters.js`, `advanced-filters.js` | Port | 4 | |
| Custom fields 1 to 6 (text, textarea) | `booking.custom_field_*`, `xs_appointment_custom_fields` | Port | 4 | built with select (choices one per line) and checkbox types too; Settings, Questions tab |
| Global search | `GlobalSearchService` | Port | 4 | scoped to the business |
| Deep link `?open={hash}` | yes | Port | 4 | |

## 5. Scheduling engine

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Slots from provider hours and breaks | `AvailabilityService`, `xs_business_hours` | Port | 3 | one hours table; the old `xs_provider_schedules` fallback is not carried |
| Business window | `business.work_start/work_end` (one window for every day) | Rebuild | 3 | the profile's own `trading_hours` per weekday (richer than Bookings) |
| Closures | `business.blocked_periods` setting | Rebuild | 3 | the profile's holiday hours plus a closures list |
| Blocked times per provider | read by availability, **no UI** | New | 2, 3 | UI and API |
| Location operating days | `xs_location_days` | Port | 2, 3 | |
| Buffer between appointments | global `business.buffer_time` | Port | 3 | |
| Buffer before and after per service | columns exist, only displayed | New | 3 | service values add to the global buffer |
| Minimum notice (lead time) | none (only past slots trimmed) | New | 3 | per business setting, default 0 |
| Booking horizon | `business.future_limit` (public only) | Port | 3 | |
| Double booking prevention | `ConflictService` check before insert | Port + New | 3 | plus a transaction with a row lock on the provider |
| UTC storage, `stored_timezone` | `TimezoneService` | Port | 3 | business timezone = the profile's `timezone` (`hours_context()`) |
| Public availability API | `/api/v1/availability/*` | Port | 5 | per profile, rate limited |

## 6. Catalogue

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Services: duration, price, category, active, slug, description | `xs_services` | Rebuild | 2 | `xs_booking_services`, linked to the profile's services list, see [data-model.md](data-model.md) |
| Service categories with colour | `xs_categories` | Port | 2 | per business |
| Delivery modes per service (on site, Zoom, Jitsi) | `delivery_modes[]` | Port | 2 | video links work in Phase 9 |
| Providers with slug, bio, colour, photo | `xs_users` with provider role | Rebuild | 2 | `xs_booking_providers`, optionally tied to a team member and an account |
| Provider weekly hours and breaks | `xs_business_hours` | Port | 2 | |
| Staff to provider assignments | `xs_provider_staff_assignments` | Port | 2 | |
| Locations per provider, primary, active | `xs_locations` | Rebuild | 2 | use the profile's own address and branches (`xs_directory_practice_locations`) |
| User management: invite, roles, deactivate, delete preview | `UserManagement*` | Rebuild | 2 | invite by email to an account plus membership |
| Provider assignment audit | `audit:provider-assignments` | Port | 2 | spark command per business |

## 7. Customers

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Customers CRUD, search, history, stats, autofill | `CustomerManagement`, `CustomerService` | Port | 4 | email unique **per business** |
| Customer custom fields | `xs_customers.custom_fields` | Port | 4 | |
| Customer page by hash (upcoming, history) | `/my-appointments/{hash}` | Port | 5 | |
| Delete with impact preview | `CustomerDeletionService` | Port | 4 | built: refused while an upcoming appointment exists; past ones stay without the person (Bookings refused while any existed) |
| Data export for a customer (POPIA access request) | not built | New | 10 | admin action, per business |
| Retention and purge | none | New | 10 ⚠ | rule needed for POPIA |

## 8. Public booking

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Flow: service, provider or any, mode and location, date and slot, details, deposit, confirmation | `PublicBookingService`, `public-booking.js` | Port | 5 | on the profile at `/directory/{slug}/book`, no session until the details step |
| Which customer fields show and are required | `booking.*_display/_required` | Port | 5 | |
| Manage, reschedule, cancel by token, with policy windows | `PublicBookingPolicy` | Port | 5 | |
| Look up bookings by email or phone | `/booking/search` | Rebuild | 5 | emails a link instead of listing bookings on screen (anyone can type an email) |
| Short links `WS-YYYY-NNNN` | `BookingLinkService` | Rebuild | 5 | `WSB-BOOK-` reference from `PaymentReference` |
| Legal page per business | `/booking/legal`, `legal.*` settings | Port | 5 | per business terms, cancellation and rescheduling text |
| Provider and service SEO pages, discover by service and city, sitemap | `/booking/p`, `/booking/s`, `/booking/discover` | Skip | | Local's profiles, categories and discovery already do this; profile JSON-LD gets `ReserveAction` |
| Honeypot, rate limits, CSRF | yes | Port | 5 | Local's `BotTrap`, honeypot, throttles and `Config\Forms` registry |
| "Book online" in discovery results | none | New | 5 | never changes ranking |

## 9. Notifications

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Queue with locks, attempts, idempotency, correlation | `xs_notification_queue`, `NotificationQueueDispatcher` | Port | 6 | one dispatcher command for all businesses, cron each minute |
| Web request heartbeat fallback | `NotificationReminderHeartbeatService` | Skip | | cron exists on our server |
| Events: pending, confirmed, reminder, cancelled, rescheduled, payment confirmed | `NotificationCatalog` | Port | 6 | add no show |
| Rules matrix event × channel, two reminder offsets | `xs_business_notification_rules` | Port | 6 | |
| Stale reminder cancellation after reschedule | `schedule_fingerprint` | Port | 6 | |
| Customer and internal templates, 37 placeholders | `NotificationTemplateService` | Port | 6 | copy rewritten to Local rules; emails in Local's `emails/_layout.php` style |
| Email | `MailerService` (SMTP per business) | Rebuild | 6 | Local `Mailer` (SES) only, From: the platform, Reply-To: the business |
| SMS (Clickatell, Twilio) | yes | Port | 6 | credentials per business, encrypted |
| WhatsApp (link, Twilio, Meta Cloud) | yes | Port | 6 | |
| Delivery logs, export, purge | yes | Port | 6 | |
| In app notification centre | `/notifications` | Port | 6 | |
| Opt out | table exists, **no route** | New | 6 | unsubscribe link and page, RFC 8058 for email |
| Manual resend | `POST appointments/{id}/notify` | Port | 6 | |

## 10. Payments

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Deposit percentage per service | `deposit_percentage`, `calculate_deposit_amount()` | Port | 8 | |
| PayFast deposits | `PayFastPaymentService` | Rebuild ⚠ | 8 | gateway choice open: Paystack subaccounts favoured |
| Stripe deposits | `StripePaymentService` | Later ⚠ | | .app only, if .app gets bookings |
| Transactions, mark paid | `xs_payment_transactions`, `adminMarkPaid` | Port | 8 | |
| Refunds | status exists, no flow | Later ⚠ | | |

## 11. Integrations and API

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Encrypted integration store with health and test | `xs_business_integrations` | Port | 6, 9 | needs `encryption.key` on production (still missing) |
| Zoom meeting on booking, Jitsi link | `VideoSessionService` | Port | 9 | |
| Google Calendar | OAuth works, **push never called** | New | 9 | push on create, update and cancel |
| Outgoing webhooks, signed | defined, **never dispatched** | New | 9 | |
| API keys | CLI only, scopes not enforced | New | 9 | UI, enforced scopes |
| REST API `/api/v1` with `{data, meta}` | yes | Port | 9 | per business; OpenAPI file ported and checked in CI |
| Developer page (Redoc) | `/developers` | Port | 9 | Redoc from a pinned CDN version, CSP permitting |
| GA4 or Matomo per business | `analytics_head_html()` | Skip | | the profile stats cover it |
| "List on our directory" handoff | `DirectoryHandoffService` | Skip | | bookings now live on the profile |

## 12. Settings

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| General: name, email, phone, address, logo | `general.*` | Skip | | the profile is the source, see [settings.md](settings.md) |
| Localization: timezone, currency, time format, first day | `localization.*` | Rebuild | 1 | timezone and currency from the profile and site; time format and first day per business |
| Booking, business, legal, notification, calendar keys | `booking.*`, `business.*`, `legal.*`, `calendar.*` | Port | 1 to 6 | `xs_booking_settings` per business |
| Database backup tab | yes | Skip | | server backups |
| System update tab | yes | Skip | | releases through git |

## 13. Platform

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Setup wizard | `/setup` | Skip | | |
| Zip updater, packaging, `version.json` | `Services/Updater/*`, `scripts/package.js` | Skip | | git branches, `deploy-production`, tags `bookings-pN`, `/health` commit |
| Audit log | `xs_audit_logs` | Port | 1 | per business, plus a viewer (Bookings has none) |
| Dark mode | inline bootstrap script | Rebuild | 1 | from a file (CSP) |
| SPA shell, sidebar | `spa.js`, `unified-sidebar.js` | Rebuild | 1 | plain page loads first; partial swaps only if needed |
| Help centre | stub | Skip | | |
| Languages other than English | `localization.language` key, no UI | Later ⚠ | | |
| PWA | none | Later | | |
| Maintenance mode | `MaintenanceFilter` | Skip | | |

## Standard adaptations for every ported file

1. Namespace `App\Services\Bookings\…`, `App\Controllers\Business\…`, models `Booking…Model`.
2. Every query is scoped by `listing_id`. `current_business_id()` and `business_id = 1` are
   removed. Scoping is enforced by a base model and checked by a test.
3. Settings reads go through `BookingSettings::for($listingId)`, never a global key.
4. Timezone is the profile's (`hours_context()`), not `localization.timezone`.
5. Mail goes through `App\Libraries\Mailer`. Writes go through the one service that owns
   the table.
6. Views use Local's layout and tokens. There are no inline scripts, styles or handlers,
   and Lucide icons replace Material Symbols.
7. Customer facing copy is rewritten to [copy.md](copy.md).
8. The ported tests come over first and use `Tests\Support\FreshDatabase` with two
   business fixtures.
