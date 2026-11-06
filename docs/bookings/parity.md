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
| Email + password sign in, sign out | `Auth.php`, session `user{role, roles[], active_role}` | Rebuild | 1 | `xs_accounts`; session only under `/bookings` |
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
| Stat cards (today, customers, pending payments, revenue this month) | `DashboardService` | Port | 7 | Built 5 Nov 2026: today, next 4 hours, next 7 days, waiting for confirmation, customers (own for a team member), booked value this month (from appointment prices, not money received). Deposits received this month show in the value tile's note since Phase 8. Cached 5 min per business and scope, emptied on every appointment change (`BookingMetricsCache`) |
| Provider cards with free slots, quick book | `/api/dashboard/provider-slots` | Port | 7 | Built: state now in the business's time zone (working, on a break, off, time off; Bookings used the server clock), today's next free times as links to the prefilled form, next free time when today is full. Server rendered, no JSON endpoint |
| Today's schedule with refresh | `dashboard-refresh.js` | Port | 7 | Built: by person, in team order; `GET /bookings/api/dashboard/today` returns the partial every 2 min while the tab is visible |
| Role alerts (pending confirmations, missing hours, booking off, closures, overbooking) | `AuthorizationService` | Port | 7 | Bookings only built pending confirmations. Built: pending, setup problems (`SetupCheck`), public holidays with no answer, whole business closures in 14 days, messages not sent in 7 days (admin). Overbooking skipped: `BookingLock` refuses it |
| Analytics: overview, revenue, customers, providers | `AnalyticsService`, `BookingMetricsService`, Chart.js | Port | 7 | Built: tabs Overview, Services, Team, Customers; 7, 30, 90 days and 12 months against the period before. **Inline SVG, not Chart.js** (the admin dashboard's partials: no new script, a table under each chart). Left out as made up in Bookings: staff utilisation (fixed 160 a month), retention by month (rate × 0.9…), revenue forecast |
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
| Services: duration, price, category, active, slug, description | `xs_services` | Rebuild | 2 | `xs_booking_services`; while bookings are on they are the profile's services list (T28, mirrored), see [data-model.md](data-model.md) |
| Service categories with colour | `xs_categories` | Port | 2 | per business |
| Delivery modes per service (on site, Zoom, Jitsi) | `delivery_modes[]` | Port | 2 | video links work in Phase 9 |
| Providers with slug, bio, colour, photo | `xs_users` with provider role | Rebuild | 2 | `xs_booking_providers`, optionally tied to a team member and an account. No photo column: the profile and the booking page use the linked team card's photo (Verified or Plus) |
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
| Data export for a customer (POPIA access request) | not built | New | 10 | built 5 Nov 2026: "Download their data" on the customer page (admins), JSON, audited |
| Retention and purge | none | New | 10 | built 5 Nov 2026 (T19): `bookings:retention` nightly, personal fields cleared 24 months after the last appointment, older audit lines deleted |

## 8. Public booking

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Flow: service, provider or any, mode and location, date and slot, details, deposit, confirmation | `PublicBookingService`, `public-booking.js` | Port | 5 | built: on the profile at `/directory/{slug}/book`, plain GET steps (no script needed, no session until the form is sent); deposit in Phase 8; the delivery mode choice comes with video in Phase 9 |
| Which customer fields show and are required | `booking.*_display/_required` | Port | 5 | built; first name always; email or phone must stay required |
| Manage, reschedule, cancel by token, with policy windows | `PublicBookingPolicy` | Port | 5 | built: the link alone is the key (hashed, rotated on every change, ends 30 days after); windows in hours per business, 0 = not online |
| Look up bookings by email or phone | `/booking/search` | Rebuild | 5 | built: `/book/find` emails customer page links, same answer either way; phone only customers contact the business |
| Short links `WS-YYYY-NNNN` | `BookingLinkService` | Rebuild | 5 | built: `/book/r/{reference}` opens the find form only (references are sequential) |
| Legal page per business | `/booking/legal`, `legal.*` settings | Port | 5 | built: `/directory/{slug}/book/terms`; any terms make the form ask to accept them |
| Provider and service SEO pages, discover by service and city, sitemap | `/booking/p`, `/booking/s`, `/booking/discover` | Skip | | Local's profiles, categories and discovery already do this; profile JSON-LD gets `ReserveAction` |
| Honeypot, rate limits, CSRF | yes | Port | 5 | built: `honeypot_field()`, throttles `book-ip-` 10/h, `book-to-` 5/day, `bookfind-*`, JSON 60/min |
| "Book online" in discovery results | none | New | 5 | built: card badge `listing_books_online_badge()`, profile button and JSON-LD `ReserveAction`; never changes ranking |

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
| SMS (Clickatell, Twilio) | yes | Skip | | built in Phase 6, removed 5 Nov 2026 (owner, T20) |
| WhatsApp (link, Twilio, Meta Cloud) | yes | Port | 6 | |
| Delivery logs, export, purge | yes | Port | 6 | |
| In app notification centre | `/notifications` | Port | 6 | |
| Opt out | table exists, **no route** | New | 6 | unsubscribe link and page, RFC 8058 for email; built 5 Nov 2026 (`book/unsubscribe/{customer hash}`; only the business can switch emails back on) |
| Manual resend | `POST appointments/{id}/notify` | Port | 6 | queued, not sent in the request (Bookings sent email and SMS at once; SMS since removed, T20) |

## 10. Payments

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Deposit percentage per service | `deposit_percentage`, `calculate_deposit_amount()` | Port | 8 | Built 5 Nov 2026 (`DepositService::amountFor()`): percentage of the price, rand only, at least R5 (PayFast's smallest payment). No account on: the booking goes through without a deposit and SetupCheck says so |
| PayFast deposits | `PayFastPaymentService` | Rebuild ⚠ | 8 | Built 5 Nov 2026 on the business's **own** PayFast account (T15): Local's `PayFast` client on the business's keys (`GatewayConfig`), signed form, notification at `book/payfast/notify` checked four ways under the business's passphrase (required here, optional in PayFast) and its merchant id |
| Paystack deposits | none | New | 8 | Built 5 Nov 2026 on the business's own secret key: hosted checkout, verified with Paystack when the customer comes back and by `bookings:payments` every 5 minutes. No webhook: a Paystack account has one webhook address, which the business may use for its own shop |
| Hold while paying | none (Bookings booked first, then asked for payment) | New | 8 | The booking waits as pending with `payment_due_by` (`payments.hold_minutes`, 30); no messages until paid; paid moves it to the usual status and queues the messages; unpaid by then, `bookings:payments` cancels it quietly. A payment after that brings it back if the time is still free, otherwise "deposit to refund" |
| Stripe deposits | `StripePaymentService` | Later | | .app has bookings since T24 but no deposits; Stripe (or Paystack in the business's currency) would add them |
| Transactions, mark paid | `xs_payment_transactions`, `adminMarkPaid` | Port | 8 | Built 5 Nov 2026: `/bookings/payments` (every try, To refund list, received this month, scoped), Mark deposit paid and Mark refunded in the appointment details (`payments.manage`, own people for a team member) |
| Refunds | status exists, no flow | Later ⚠ | | T16: done in the business's own gateway, then marked refunded. A dashboard alert lists deposits paid on a cancelled or freed booking |

## 11. Integrations and API

| Feature | Bookings today | Decision | Phase | Notes |
|---|---|---|---|---|
| Encrypted integration store with health and test | `xs_business_integrations` | Port | 6, 9 | needs `encryption.key` on production (still missing); built (Check buttons for Zoom and Google, Send a test for the webhook) |
| Zoom meeting on booking, Jitsi link | `VideoSessionService` | Port | 9 | built 5 Nov 2026: the business's own Zoom app; unlike Bookings a new time moves the meeting and a cancellation deletes it; Jitsi link hashed from the appointment hash; customers choose how when a service offers more than one way; confirmations wait up to 10 minutes for a Zoom link |
| Google Calendar | OAuth works, **push never called** | New | 9 | built 5 Nov 2026: one platform OAuth client (T22), push on create, update and cancel through the outbox, one way only. Reading busy times from Google: Later. Per person calendars: Later |
| Outgoing webhooks, signed | defined, **never dispatched** | New | 9 | built 5 Nov 2026: `WebScheduler-Signature: t=..,v1=HMAC(t.body)`, retries 1, 5, 30, 120, 360 min, delivery log with Send again, SSRF guard (`SafeUrl`) |
| API keys | CLI only, scopes not enforced | New | 9 | built 5 Nov 2026: Settings, API keys; six scopes, each also needs the member's role |
| REST API `/api/v1` with `{data, meta}` | yes | Port | 9 | built 5 Nov 2026, per business; `docs/bookings/openapi.json` checked against the routes by a test |
| Developer page (Redoc) | `/developers` | Rebuild | 9 | Redoc needs inline styles (CSP), so `/developers/bookings` is rendered by us from the same OpenAPI file |
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
