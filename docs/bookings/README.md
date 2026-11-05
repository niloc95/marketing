# Bookings inside WebScheduler Local

Phase 0 specification for rebuilding WebScheduler Bookings inside Local, so any business
profile can take bookings from one codebase, one database and one release.

- **Status:** Phases 0 to 4 merged to `main` on 4 Nov 2026 (`f3bb15b`), dark behind
  `bookings.enabled`, not released. Phase 5 (public booking) built on
  `bookings-p5-public` (off `main`), not merged. Phase 6 (notifications) built on
  `bookings-p6-notifications` (off p5), not merged. Phase 7 (dashboard and analytics)
  built on `bookings-p7-dashboard` (off p6), not merged. Phase 8 (deposits into the
  business's own PayFast or Paystack account) built on `bookings-p8-payments` (off p7),
  not merged. Phase 9 (connections and the API: Zoom, Jitsi, Google Calendar, signed
  webhooks, API keys, `/api/v1`, developer page) built on `bookings-p9-integrations`
  (off p8), not merged.
- **Reference app (frozen):** `/Volumes/Nilo_512GB/projects/xscheduler_ci4` (Bookings
  v2.7.0). Its installs keep running untouched. Nothing in this project changes it.
- **Plan:** phases 0 to 10 below. Each phase gets its own branch (off `main` since the
  merge), its own tests and a dev check. It is merged and released only when the owner says so.

## Documents

| File | What it settles |
|---|---|
| [parity.md](parity.md) | every Bookings feature, and whether it is ported, rebuilt, built new, deferred or skipped, plus its phase |
| [data-model.md](data-model.md) | old table → new table, new columns on Local tables, keys, scoping rules |
| [routes.md](routes.md) | Bookings route → Local route, by area |
| [permissions.md](permissions.md) | roles, the permission matrix, scoping rules, how a request finds its business |
| [settings.md](settings.md) | Bookings settings keys → per business settings, defaults, what moves to profile data |
| [copy.md](copy.md) | words and phrases, rewrites of Bookings copy, email subjects |
| [decisions.md](decisions.md) | decisions taken, and the open ones the owner signs off before Phase 1 |

The working rules for code live in the project skill `.claude/skills/bookings/SKILL.md`.

## Principles

1. **A business is a Local profile.** Every booking row carries `listing_id`. Nothing
   assumes one business per install (Bookings' `business_id = 1`).
2. **Port the logic, rebuild the shell.** Bookings' scheduling engine, calendar view
   models, booking pipeline, notification engine, deposits and their tests move across
   with a fixed set of adaptations. Its users, settings store, views, styling and
   installer do not fit one install that serves many businesses, so they are rebuilt.
3. **Don't copy gaps.** Bookings' docs describe features it never built. These include
   webhook dispatch, Google Calendar push, blocked times UI, unsubscribe, 2FA and per
   service buffers. [parity.md](parity.md) marks each one, so nothing is "ported" that
   never existed.
4. **Local's rules win.** These include its layering (one writer service per table), its
   strict CSP, its copy rules, its two site scoping, `Mailer` as the only way to send mail,
   and Paystack for new payments. It also covers the `FreshDatabase` tests and the one dev
   server and one dev database.
5. **Dark until switched on.** Global flag `bookings.enabled` plus a per profile switch.
   Production shows nothing until the owner turns on a test profile.

## Phases

| # | Phase | Main output |
|---|---|---|
| 0 | Specification | this folder, the `bookings` skill |
| 1 | Foundation | accounts, memberships, RBAC, audit log, `/business` shell, Vite bundle, settings store, flag, "Turn on bookings" |
| 2 | Catalogue | booking services, providers, provider hours and breaks, staff assignments, locations, blocked times, business user management |
| 3 | Scheduling engine | availability, conflicts, timezones, buffers, lead time, horizon, golden tests |
| 4 | Appointments and customers | calendar day/week/month/agenda, drag to reschedule, form, statuses, custom fields, customers |
| 5 | Public booking | flow on the profile, manage, reschedule and cancel by link, customer page, short links |
| 6 | Notifications | queue, dispatcher, rules, reminders, templates, email, then SMS and WhatsApp, delivery logs, opt out |
| 7 | Dashboard and analytics | landing dashboard, analytics tabs, cached metrics |
| 8 | Payments | deposits, transactions, mark paid, refunds if decided |
| 9 | Integrations and API | integration store, Zoom and Jitsi, Google Calendar, webhooks, API keys, REST API, developer page |
| 10 | Hardening and launch | load test, security review, restore drill, POPIA review, pricing switch, first businesses |

## Sizes, for planning

Bookings is about 55k lines of PHP (controllers, services, models, helpers, commands,
filters), 13k of views, 8.4k of migrations, 25.4k of JS and 22.8k of tests. Local is about
85k lines of PHP. The largest areas are notifications (~10k PHP), calendar and appointments
(~8.4k PHP + ~12k JS) and the catalogue and user management (~9.5k PHP).
