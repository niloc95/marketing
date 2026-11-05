# Decisions

## Taken

| # | Decision | By, when |
|---|---|---|
| T1 | Rebuild all of Bookings inside Local (not a link, not a separate multi tenant app) | owner, 2 Nov 2026 |
| T2 | The existing Bookings installs are frozen and keep running; no changes to them | owner, 2 Nov 2026 |
| T3 | No AWS move for this; Lightsail stays | owner, 2 Nov 2026 |
| T4 | Branch `bookings`; each phase on a branch off it; merge and release only on the owner's go ahead | owner, 2 Nov 2026 |
| T5 | A business is a Local profile; every booking row carries `listing_id` | Phase 0 |
| T6 | Bookable services and providers get their own tables with stable ids (Local's services list is rewritten on every save; team and branches are paid features) | Phase 0, see data-model.md |
| T7 | Appointments snapshot service, price, provider and location at booking time | Phase 0 |
| T8 | Customer mail only through Local's `Mailer` (SES); no SMTP per business | Phase 0 |
| T9 | No booking SEO pages of their own; the profile is the page | Phase 0 |
| T10 | Bookings' unbuilt features are listed as New or Later, never as ported | Phase 0 |
| T11 | Dashboard at `/business` on the same host (was D4) | owner, 4 Nov 2026 |
| T12 | Email + password accounts with lockout for business people; magic link sign in also offered; owners keep the magic link for `/manage` (was D5) | owner, 4 Nov 2026 |
| T13 | Vite in Local for the dashboard bundle only; public pages keep Tailwind CLI and `directory.js` (was D7) | owner, 4 Nov 2026 |
| T14 | One services list (was D3): the profile keeps its own "Services and prices"; bookings add the Book online button and the booking page, nothing is hidden | owner, 5 Nov 2026 |
| T15 | Deposits (was D6): each business connects its **own** PayFast or Paystack account and the money goes straight to it. We are not part of a business's billing to its customers: no subaccounts, no fee, never the merchant of record. Separate from the business's own subscription to us | owner, 5 Nov 2026 |
| T16 | Refunds and 2FA (was D9): later. Refunds are done in the business's own gateway and marked refunded in /business | owner, 5 Nov 2026 |

| T18 | Bookings plan (was D1): a paid monthly plan with a 30 day free trial, through Paystack, behind `bookings.planRequired`; price set by the owner (`bookings.planMonthly`) | owner, 5 Nov 2026 |
| T19 | Retention and admin access (was D12): customers' personal details removed 24 months after their last appointment; platform admin sees counts and setup, plus a support view that needs a reason and is logged in the business's audit log | owner, 5 Nov 2026 |
| T20 | SMS removed completely (was D8): no SMS channel, no Clickatell; messages are email, and WhatsApp on the business's own account (link, Twilio or Meta), which it pays itself. Migration `2026-11-20-100000_RemoveBookingSms` clears SMS rows | owner, 5 Nov 2026 |
| T21 | `encryption.key` on production (was D14): still needed after T20, for the business's WhatsApp, PayFast/Paystack, Zoom, Google and webhook accounts. Set at the release, with the owner's OK at that step; never rotated after | owner, 5 Nov 2026 |
| T22 | Google Calendar (was D15): one platform OAuth client; the owner creates it in Google Cloud (redirect `https://local.webscheduler.co.za/business/connections/google/callback`, scope `calendar.events`) and applies for Google's verification; the button shows once `bookings.googleClientId` and `bookings.googleClientSecret` are set | owner, 5 Nov 2026 |
| T23 | Bookings plan prices (was D16): by bookable people, Solo (1) R160, Team (up to 5) R420, Business (6 or more) R890 a month; `Config\Bookings::$planTiers`, prices overridable with `bookings.planPrices`. Growing or shrinking past a tier offers a move, which charges the new price, adds a month and stops the old subscription | owner, 5 Nov 2026 |
| T24 | Sites (was D2): online bookings on both sites. A business uses its own site's host for the booking page, `/business` and the API (the wrong host redirects, or 404s for a form or an API call); prices in its country's currency (`BusinessLocale`), its own clock; no deposits abroad (rand only, the business's own PayFast or Paystack in South Africa; Stripe later); the plan abroad shows US dollars (US$8.99 / US$23.99 / US$49.99, `planTiers` `usd`) and is charged in rand at the Plus rate (`usd_zar_rate@app`), with no rand figure on .app | owner, 5 Nov 2026 |
| T17 | Zoom: each business brings its own Zoom app (Server to Server OAuth), as WhatsApp and payments (D8, T15). Jitsi (meet.jit.si) needs no account | Phase 9 build, 5 Nov 2026 |

## Open: owner to answer before Phase 1

Each has a recommendation and is needed by the phase shown. D4, D5 and D7 were settled on
4 Nov 2026 (T11 to T13), which unblocks Phase 1.

| # | Question | Recommendation | Needed by |
|---|---|---|---|
| D10 | **Languages:** English only? | English only. | Phase 1 |
| D11 | **Name in copy:** what owners and customers see. "Bookings", "WebScheduler Bookings", or "Online bookings on WebScheduler Local"? | "Online bookings" as the feature, "WebScheduler Local" as the platform. | Phase 1 |
| D13 | **Import from frozen Bookings installs:** offer it later? | Yes, per customer and with their OK, after launch. | after Phase 10 |
