# Settings map

Bookings keeps one global key value store (`xs_settings`). Local keeps booking settings per
business in `xs_booking_settings` (listing_id, key, value), read through
`BookingSettings::for($listingId)`. Defaults live in `Config\Bookings`. Anything the profile
already holds is read from the profile, never copied, so the two can't drift.

## Read from the profile (not stored as booking settings)

| Bookings key | Local source |
|---|---|
| `general.company_name` | `xs_directory_listings.display_name` |
| `general.company_email` | listing `email` (Reply-To on customer emails) |
| `general.telephone_number`, `mobile_number` | listing `phone`, `phone_alt`, `whatsapp` |
| `general.business_address` | the profile address through `listing_public_view()` (hidden addresses stay hidden) |
| `general.company_logo`, `company_icon` | listing `logo_path` |
| `general.company_link` | the profile URL (`listing_url()`) |
| `localization.timezone` | listing `timezone` (`hours_context()`) |
| `localization.currency` | the site (`ZAR` on co.za; .app per decision D2) |
| `business.work_start`, `work_end`, `break_start`, `break_end` | listing `trading_hours` per weekday (breaks are per provider) |
| `business.blocked_periods` | holiday hours plus `xs_booking_blocked_times` with no provider |

## Stored per business (key kept unless noted)

| Key | Default | Phase | Note |
|---|---|---|---|
| `localization.time_format` | `24h` | 1 | |
| `localization.first_day` | `monday` | 1 | |
| `booking.default_appointment_status` | `pending` | 5 | `pending` or `confirmed` for public bookings; built 4 Nov 2026 (Booking rules tab) |
| `booking.{first_names,surname,email,phone,address,notes}_{display,required}` | name, email, phone shown and required; address and notes off | 5 | email or phone must stay required (we need a way to reach the customer); built 4 Nov 2026 |
| `booking.custom_field_{1..6}_{enabled,title,type,required,sensitive,options}` | off | 4 | built; type text, textarea, select or checkbox; `options` one choice per line (up to 20); a list with no choices is not asked; required is checked when booking, not on the customer record; `sensitive` = never put in an email (Phase 6 honours it) |
| `booking.slot_step` (was `booking.time_resolution`) | `0` = service length | 3 | built; 0 to 240 minutes |
| `booking.lead_minutes` | `0` | 3 | built; minimum notice, customers only; entered in hours |
| `business.buffer_time` | `0` | 3 | built; minutes after each appointment, added to the service's own gap |
| `business.future_limit` | `60` | 3 | built; days ahead customers can book (1 to 730) |
| `business.reschedule` | `24` | 5 | hours before; `0` = not allowed online; built 4 Nov 2026 |
| `business.cancel` | `24` | 5 | hours before; `0` = not allowed online; built 4 Nov 2026 |
| `business.statuses` | all five | 4 | which statuses the business uses |
| `calendar.default_view` | `week` | 4 | |
| `calendar.day_start`, `day_end` (were `booking.day_start/day_end`) | from trading hours | 4 | display window only |
| `calendar.show_weekends` | `true` | 4 | |
| `calendar.slot_duration` | `30` | 4 | grid lines |
| `legal.terms`, `legal.cancellation_policy`, `legal.rescheduling_policy` | empty | 5 | plain text, shown on the booking terms page; built 4 Nov 2026 |
| `legal.terms_url`, `legal.privacy_url` | empty | 5 | checked with `safe_external_url()`; built 4 Nov 2026 |
| `notifications.reminder_primary`, `reminder_secondary` | `1440`, `0` (off) | 6 | minutes before, 0 to 20160; one setting for every channel (Bookings kept offsets per channel on the rules); built 5 Nov 2026 |
| `notifications.from_name` | business name | 6 | sender name only, shown as "Name via WebScheduler Local"; the address is always ours; built 5 Nov 2026 |
| `notifications.reply_to` | the profile's email | 6 | Reply-To on customer emails; built 5 Nov 2026 |
| `payments.hold_minutes` | `30` | 8 | how long an unpaid deposit holds the time, 15 to 120; built 5 Nov 2026. The gateway is the business's account in `xs_booking_integrations` (channel `payment`), not a setting |

## Not carried

| Bookings key | Why |
|---|---|
| `booking.indexable` | profiles are already indexed by Local's quality rules |
| `localization.language` | English only for now (decision D10) |
| `integrations.analytics*` | no per business analytics tags |
| `database.allow_backup`, `system.installed_version` | one hosted install |
| `legal.privacy`, `legal.cookie_notice` | Local's privacy and cookie policy cover the platform; a business can link its own |
| `security.*`, `branding.*` | unused in Bookings code |

Integration credentials (SMTP, SMS, WhatsApp, Zoom, Google, payment) are not settings.
They live encrypted in `xs_booking_integrations`. SMTP is not offered, because mail goes
through `Mailer`.
