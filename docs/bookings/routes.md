# Route map

Bookings route → Local route. All Local routes are on both hosts unless a decision limits
them (D2: co.za first). Literal segments are declared before Local's catch alls
(`directory/(:segment)/menu` is the model). `/directory/{a}/{b}` (`Directory::place`)
would otherwise swallow `/directory/{slug}/book`.

New top level segments: `business`, `book`, `api`. Check `listing_reserved_slugs()` and
`Config\Forms` (every new POST route needs an entry, or `FormHealthServiceTest` fails).

## Business area (signed in, session, `businessauth`)

| Bookings | Local | Phase |
|---|---|---|
| `GET/POST /login`, `/auth/*`, `/auth/logout` | `GET/POST /business/sign-in`, `POST /business/sign-out` | 1 |
| `/auth/forgot-password`, `reset-password/{token}` | `/business/password`, `/business/password/{token}` | 1 |
| `GET /auth/ping` | `GET /business/ping` | 1 |
| none | `GET /manage/bookings` (turn on, from the owner magic link session) | 1 |
| none | `GET /business/invite/{token}` (accept an invitation, set a password) | 2 |
| `/profile/*` | `/business/account` | 1 |
| `/dashboard` | `GET /business` | 7 (shell in 1) |
| `/appointments` (+ `?open={hash}`) | `/business/calendar` (+ `?open={hash}`) | 4 |
| `/appointments/create`, `edit/{hash}` | modal inside the calendar, plus `/business/appointments/new` without JS | 4 |
| `/customer-management/*` | `/business/customers`, `/business/customers/{hash}` | 4 |
| `/services/*`, `/services/categories` | `/business/services`, `/business/services/categories` | 2 |
| `/user-management/*`, `/providers/{id}/schedule`, `/provider-staff/*`, `/staff-providers/*` | `/business/team`, `/business/team/{id}`, `/business/team/{id}/hours` | 2 |
| none (blocked times had no UI) | `/business/time-off` | 2 |
| `/notifications` | `/business/notifications` | 6 |
| `/analytics`, `/analytics/revenue`, `/analytics/customers` | `/business/analytics` (tabs by `?tab=`) | 7 |
| `/settings` (tabs) | `/business/settings/{tab}` | 1 to 9 |
| none | `/business/audit` | 1 |
| `/search`, `/dashboard/search` | `/business/search` | 4 |
| `/admin/updater`, `/setup/*`, `/help/*` | none | skip |

## Business JSON (session, CSRF on writes)

The dashboard bundle calls these. They share code with the public API (Phase 9) but sit
under `/business/api/` so session and CSRF rules stay simple.

| Bookings | Local | Phase |
|---|---|---|
| `GET /api/calendar/day|week|month` | `GET /business/api/calendar/{view}` | 4 |
| `GET/POST /api/appointments`, `PATCH /{id}`, `/{id}/status`, `/{id}/notes`, `/{id}/payment-status`, `POST /{id}/notify` | `/business/api/appointments`: `GET /{id}`, `GET /find?q=`, `POST /`, `POST /{id}/reschedule`, `/{id}/status`, `/{id}/notes` (POST, not PATCH; built in Phase 4); payment and notify in 6 and 8 | 4, 6, 8 |
| `GET /api/availability/*` | `GET /business/api/availability/*` | 3 |
| `GET /api/customers/*` | `/business/api/customers/*` | 4 |
| `GET /dashboard/api/*`, `/api/dashboard/provider-slots` | `/business/api/dashboard/*` | 7 |
| `GET/POST integrations/*` | `/business/api/integrations/*` | 9 |

## Public booking (no account; session starts only at the details step)

| Bookings | Local | Phase |
|---|---|---|
| `GET /booking`, `/booking/{service}/{provider}` | `GET /directory/{slug}/book`, `?service=&provider=` | 5 |
| `GET /booking/slots`, `/booking/calendar` | `GET /directory/{slug}/book/slots`, `/book/days` (JSON, throttled) | 5 |
| `POST /booking` | `POST /directory/{slug}/book` (CSRF, honeypot, BotTrap, throttle) | 5 |
| `GET/PATCH /booking/{token}`, `PATCH /booking/{token}/cancel` | `GET /book/{token}`, `POST /book/{token}/reschedule`, `POST /book/{token}/cancel` | 5 |
| `/my-appointments/{hash}` | `GET /book/customer/{hash}` | 5 |
| `/r/{ref}`, `/booking/r/{ref}` | `GET /book/r/{reference}` | 5 |
| `GET /booking/search?email|phone` | `POST /book/find` (emails the customer their link; same answer whether found or not) | 5 |
| `/booking/legal` | `GET /directory/{slug}/book/terms` | 5 |
| `/booking/p/*`, `/booking/s/*`, `/booking/discover`, booking sitemap | none (profile, category and discovery pages already cover it) | skip |
| `/booking/payment/return|cancel` | `GET /book/{token}/paid`, `/book/{token}/unpaid` | 8 |
| none | `GET/POST /book/unsubscribe/{token}` (CSRF exempt POST, RFC 8058) | 6 |

## Webhooks in (CSRF, honeypot and BotTrap exempt, like `payfast/notify`)

| Bookings | Local | Phase |
|---|---|---|
| `POST /public/payments/payfast/notify` | depends on D6 (gateway); Paystack: the existing `POST paystack/webhook` routed by reference prefix `WSB-BOOK` | 8 |
| `POST /public/payments/stripe/webhook` | `POST /book/stripe/webhook` if D6 says Stripe | later |
| `/oauth/google/authorize|callback` | `/business/integrations/google`, `/business/integrations/google/callback` | 9 |

## Public API (Phase 9, Bearer key, JSON, `{data, meta}` / `{error}`)

`/api/v1/*` with Bookings' resource groups (appointments, availability, customers,
services, categories, providers, locations, settings reads), scoped to the key's business.
The unversioned `/api` alias is not carried. CORS stays same origin unless the business
lists origins.

## Cron

| Bookings | Local | Phase |
|---|---|---|
| `* * * * * notifications:dispatch-queue` | `* * * * * bookings:dispatch` (all businesses, lock file, time box) | 6 |
| `notifications:purge-delivery-logs`, `audit:purge-logs` | `bookings:prune` daily, joins Local's 03:xx block | 6 |
| none | `bookings:sweep` (expire unpaid holds, retention) | 8, 10 |

Each new command goes into `deploy/cron/webscheduler` and the live `/etc/cron.d/webscheduler`.
