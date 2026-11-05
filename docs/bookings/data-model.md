# Data model

How Bookings' 23 tables map onto Local. All new tables use the `xs_` prefix, `utf8mb4`,
UTC datetimes, `created_at` / `updated_at`. Migrations omit `'after' => …` (Local rule).

## The two findings that shape it

1. **Local's services list is replaced on every save.** `ServiceMenuService` deletes all
   of a profile's `xs_directory_listing_services` rows and inserts them again
   (`ServiceMenuService.php:154`), so their ids change each time an owner saves the form.
   Appointments can't point at those ids.
2. **Team members and extra branches are Verified (or Plus) features**
   (`TeamMemberService::MAX_MEMBERS`, `PracticeLocationService::MAX_LOCATIONS`). A free
   profile has neither, and a lapsed badge hides them. Bookings has to work for a business
   with no team and only its main address.

So bookable services and providers get **their own tables with stable ids**, each
**optionally linked** to the profile row it mirrors. Appointments **snapshot** the names,
durations and prices they were booked with, so later edits and deletions never rewrite
history.

## Identity and membership (Phase 1)

| Table | Columns | Notes |
|---|---|---|
| `xs_accounts` | id, email (unique), name, password_hash, status (active, disabled), email_verified_at, last_login_at, photo_path, reset_token_hash, reset_expires, created_at, updated_at | A person who signs in. Not tied to one business. Replaces Bookings `xs_users` as a login. |
| `xs_business_members` | id, account_id, listing_id, role (admin, provider, staff), status (invited, active, removed), invited_by, invite_token_hash, invite_expires, notify_on_appointments, created_at, updated_at | Unique (account_id, listing_id). The owner who turns bookings on becomes `admin`. Replaces `xs_user_roles`. |
| `xs_booking_audit_logs` | id, listing_id, account_id, action, target_type, target_id, old_value, new_value, ip_hash, created_at | From `xs_audit_logs`. Hashed IP, retention to be decided (decisions D12). |

On `xs_directory_listings`: `bookings_enabled` (tinyint, default 0) and
`bookings_enabled_at`. They are not in `OWNER_EDITABLE`, and only `BookingSetupService`
writes them.

## Catalogue (Phase 2, built 4 Nov 2026)

Migration `2026-11-13-100000_CreateBookingCatalogue`.

| Table | Columns | Mirrors in Bookings | Notes |
|---|---|---|---|
| `xs_booking_categories` | id, listing_id, name, color, sort_order | `xs_categories` | deleting one leaves its services without a category (FK SET NULL) |
| `xs_booking_services` | id, listing_id, slug, name, description, duration_min, price_cents, currency, deposit_pct, buffer_before, buffer_after, category_id, delivery_modes (JSON), profile_service_name, sort_order, archived_at | `xs_services` + payment columns | Unique (listing_id, slug). Archived, never deleted. `profile_service_name` is unused: D3 was settled as one list each (T14). |
| `xs_booking_providers` | id, listing_id, team_member_id (nullable, SET NULL), member_id (nullable, SET NULL), slug, name, title, bio, color, bookable, sort_order, archived_at | `xs_users` with the provider role | A new business gets one provider named after itself, on the profile's opening hours, linked to the owner. A provider made from a profile team card **copies** its name, title and bio once (changed from "reads while linked": simpler, and the card can be a lapsed paid feature). |
| `xs_booking_provider_services` | listing_id, provider_id, service_id, created_at | `xs_providers_services` | PK (provider_id, service_id). Carries `listing_id` so it goes through BookingScopedModel like every other table. |
| `xs_booking_provider_hours` | id, listing_id, provider_id, weekday (1 to 7), start_time, end_time, breaks (JSON), location_id (nullable, SET NULL) | `xs_business_hours` | Unique (provider_id, weekday). Local time. One break per day in the form; the JSON allows more later. |
| `xs_booking_staff_assignments` | id, listing_id, provider_id, member_id, assigned_by | `xs_provider_staff_assignments` | Unique (provider_id, member_id). Only active reception members can be assigned. |
| `xs_booking_blocked_times` | id, listing_id, provider_id (nullable = whole business), start_at, end_at (UTC), reason, created_by | `xs_blocked_times` | Gets a UI (Bookings had none). |

`xs_booking_location_days` is **not built**: a branch is open on the days someone works
there (`xs_booking_provider_hours.location_id`), which is the same fact stored once.
`xs_business_members` gained `invite_token_hash` and `invite_expires`.

**Locations.** Bookings' `xs_locations` is not recreated. `location_id` everywhere is
either null (the profile's own address) or an `xs_directory_practice_locations.id`. Branch
ids are stable (`PracticeLocationService` updates in place), and appointments snapshot the
name and address. A branch hidden by a lapsed badge stops taking new bookings and keeps its
existing ones.

**Hours.** The business window is the profile's `trading_hours` (per weekday, from
`hours_decode()`), not Bookings' single `work_start/work_end`. Closures are the profile's
holiday answers (`hours_holiday_rule()`) plus `xs_booking_blocked_times` rows with no
provider. Provider hours are clipped to the business window for the day.

## Appointments and customers (appointments built in Phase 3, customers Phase 4)

| Table | Columns | Notes |
|---|---|---|
| `xs_booking_customers` | id, listing_id, hash (64, unique), first_name, last_name, email, phone, address, notes, custom_fields (JSON), created_at, updated_at, purged_at | Unique (listing_id, email). The same person at two businesses is two customers, by design. |
| `xs_booking_appointments` | id, listing_id, reference (`WSB-BOOK-…`, unique), hash (unique), provider_id, service_id, customer_id, location_id (nullable), start_at, end_at (UTC), stored_timezone, status, notes, source (business, public, api), delivery_mode, video_link, service_name, service_duration_min, price_cents, currency, provider_name, location_name, location_address, payment_status, payment_amount_cents, public_token_hash, public_token_expires, reminder_sent, created_by_member_id, created_at, updated_at, cancelled_at | Built 4 Nov 2026 (migration `2026-11-14-100000`), plus `hold_before_min` and `hold_after_min` (the gaps kept around it); `customer_id` gets its foreign key in Phase 4. Snapshot columns keep history readable after edits. Index (provider_id, start_at, end_at) for conflicts, (listing_id, start_at) for the calendar. |
| `xs_booking_appointment_fields` | id, appointment_id, field_key, value | Unique (appointment_id, field_key), cascade on delete. Built 4 Nov 2026 with customers (migration `2026-11-15-100000`); the appointment's customer FK is SET NULL, so deleting a customer keeps past appointments without the person. |

## Notifications (Phase 6)

| Table | From | Notes |
|---|---|---|
| `xs_booking_notification_rules` | `xs_business_notification_rules` | `business_id` → `listing_id` |
| `xs_booking_templates` | `xs_message_templates` + `notification_template.*` settings | one table for customer and internal templates; defaults stay in code |
| `xs_booking_notification_queue` | `xs_notification_queue` | same locking, idempotency and fingerprint columns; `recipient_member_id` instead of `recipient_user_id` |
| `xs_booking_delivery_logs` | `xs_notification_delivery_logs` | recipient stored masked or hashed (decision D12) |
| `xs_booking_opt_outs` | `xs_notification_opt_outs` | per business, channel and recipient; plus a token for the unsubscribe link |
| `xs_booking_inbox` | in app notification centre | per member, read flag |

## Payments, integrations, API (Phases 8 and 9)

| Table | From | Notes |
|---|---|---|
| `xs_booking_payments` | `xs_payment_transactions` | Built 5 Nov 2026 (migration `2026-11-17-100000`): listing_id, appointment_id (SET NULL), hash (the return link), reference (`WSB-BOOK-000123-P1`, one per try, unique), gateway (payfast, paystack, manual), amount_cents, currency, status (started, paid, failed, refunded), test, gateway_id, detail, recorded_by_member_id, paid_at, refunded_at. No raw payload kept. Appointments got `payment_due_by`; `payment_status` is none, due, paid, refunded, expired or refund_due |
| `xs_booking_integrations` | `xs_business_integrations` | encrypted config (CI4 Encrypter, refuses to save without `encryption.key`, as the Partner Program does). Channel `payment` (Phase 8) holds the business's PayFast or Paystack keys; Phase 9 adds `zoom` (the business's Server to Server app), `jitsi` (an optional own server), `google` (OAuth tokens, written only by `GoogleCalendar` through `store()`) and `webhook` (address, our signing secret, events) |
| `xs_booking_api_keys` | `xs_api_keys` | Built 5 Nov 2026 (migration `2026-11-18-100000`): listing_id, member_id (CASCADE), name, prefix (12 hex, unique, the lookup), secret_hash (SHA-256 of the 40 hex secret), scopes (JSON), last_used_at, expires_at, revoked_at, created_by. Scopes enforced together with the member's role |
| `xs_booking_outbox` | none (webhooks never fired, Google never pushed) | Built 5 Nov 2026, replaces the planned `xs_booking_webhook_deliveries`: target (webhook, google, zoom), appointment_id (SET NULL), event, payload (webhook body frozen when it happened), status (queued, done, failed, cancelled), attempts, next_attempt_at, response_code, last_error, done_at. One queued Google or Zoom row per appointment; one webhook row per event. It is the delivery log too; `bookings:prune` deletes finished rows after 30 days |

Appointments got `video_meeting_id` (Zoom) and `google_event_id` in Phase 9; with `video_link`
they are written only by `Integrations\IntegrationSync`, without touching `updated_at`.

## The Bookings plan (Phase 10)

| Table | Notes |
|---|---|
| `xs_booking_plans` | Built 5 Nov 2026 (migration `2026-11-19-100000`): one row per business (unique listing_id), state (trial, active, cancelled), trial_ends_at, paid_until (UTC), amount_cents, payment_reference (`WSL-BKG-M-000123`), payment_attempts, the Paystack mandate (customer, plan, subscription code, email token), reminded_at, cancelled_at, note. Not scoped (the webhook reads across businesses); written only by `Plans\BookingPlanService` |
| `xs_booking_plan_payments` | one row per charge, unique gateway_id (`paystack:<id>`), so the return and the webhook count once |

Customers past the keeping period keep their row (counts) with `purged_at` set and the
personal fields cleared (`Privacy\Retention`); `CustomerService::list()` leaves them out.

## Settings (Phase 1)

| Table | Columns | Notes |
|---|---|---|
| `xs_booking_settings` | id, listing_id, key, value, updated_by, updated_at | Unique (listing_id, key). Read through `BookingSettings::for($listingId)` with code defaults. See [settings.md](settings.md). |

## Scoping rule

Every `xs_booking_*` model extends `BookingScopedModel`. It refuses a find or select
without a `listing_id` condition, and it fills `listing_id` on insert from the scope it was
opened with. Child tables with no `listing_id` (provider_services, appointment_fields) are
only reached through their parent's service. A test opens two businesses with identical
data and asserts that no read, calendar, API or export of one ever returns a row of the
other.

## What is not migrated

Bookings tenants are frozen and their data stays in their own databases. A per tenant
import tool is optional and comes later (Phase 10 or after). It needs each customer's OK,
and it maps users → accounts + members, services → booking services, appointments with
snapshots, and customers per business.
