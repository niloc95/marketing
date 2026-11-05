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

## Open: owner to answer before Phase 1

Each has a recommendation and is needed by the phase shown. D4, D5 and D7 were settled on
4 Nov 2026 (T11 to T13), which unblocks Phase 1.

| # | Question | Recommendation | Needed by |
|---|---|---|---|
| D1 | **Who can turn bookings on?** (a) every free profile, (b) Verified / Plus only, (c) a new paid Bookings plan (like R160 / R420 / R890 a month) | (c) a paid plan with a free trial: booking costs us SMS, WhatsApp and mail, and support. Free profiles see the offer. Ranking never changes. | Phase 10 (built with a switch, so the code does not wait) |
| D2 | **Sites:** co.za only first, or .app too? | co.za first. .app later with Stripe or Paystack USD deposits and no rand figures. | Phase 5 |
| D6 | **Deposit gateway:** money must reach the business, not us. Paystack subaccounts (split payments to the business's bank account, our fee optional), or each business's own PayFast merchant details as in Bookings? | Paystack subaccounts: Local already runs Paystack, a business only gives bank details, and one webhook serves everyone. PayFast per business stays possible later. | Phase 8 |
| D8 | **SMS and WhatsApp cost:** the business brings its own Twilio / Clickatell / Meta account (as in Bookings), or we resell? | Business brings its own at launch; resold credits later. Phase 6 was built this way (5 Nov 2026); owner to confirm. | Phase 6 |
| D9 | **Refunds and 2FA:** build now or later? | Later, both. Refunds are done in the gateway dashboard at first. | Phase 8 |
| D10 | **Languages:** English only? | English only. | Phase 1 |
| D11 | **Name in copy:** what owners and customers see. "Bookings", "WebScheduler Bookings", or "Online bookings on WebScheduler Local"? | "Online bookings" as the feature, "WebScheduler Local" as the platform. | Phase 1 |
| D12 | **Customer data retention and platform admin access** (POPIA): how long to keep customers and appointments after the last booking, whether platform admin may open appointment contents | Keep 24 months after the last appointment, then delete the personal fields. Platform admin sees counts and setup only, plus a logged "open for support" action. | Phase 10 (privacy policy update before launch) |
| D13 | **Import from frozen Bookings installs:** offer it later? | Yes, per customer and with their OK, after launch. | after Phase 10 |
| D14 | **`encryption.key` on production** (still missing, noted in the Partner Program): required for integration credentials | Generate and set it before Phase 6 is released (owner OK, production change). | Phase 6 release |
