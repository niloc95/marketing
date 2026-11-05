# Security review (Phase 10, 5 Nov 2026)

A review of online bookings (Phases 1 to 10) before launch: what was checked, what is
guarded by a test, what is left as a known risk. Re-run the checklist when a phase adds
routes.

## Guarded by tests

| Area | Guard |
|---|---|
| Every `/business*` route behind `businessauth`, every `/api/v1/*` route behind `bookingapi:<scope>`, every `/admin/*` route behind `admin` | `BookingLaunchTest::testEveryBookingsRouteIsBehindItsGate` (reads the route table) |
| One business never sees another's rows | `BookingScopedModel` throws on unscoped reads; scope tests in every phase; `BookingIntegrationsTest::testAKeyOnlyEverSeesItsOwnBusiness` (a `listing_id` parameter cannot widen a key) |
| Every POST is a known form or a listed exception | `FormsRegistryTest`, `FormHealthServiceTest` |
| The OpenAPI file matches the routes and their scopes | `BookingIntegrationsTest::testTheOpenApiFileDescribesEveryRouteAndNothingElse` |
| Customer copy rules | `BookingFoundationTest::testBusinessViewsFollowTheCopyRules` |

## Checked by reading

| Area | Finding |
|---|---|
| Sign in | password hashing, lockout per IP and per account, session id regenerated on sign in (`BusinessSession`), idle timeout, membership checked against the database on every request |
| Links customers hold | change or cancel token 64 hex, stored hashed, rotated on every change; customer page key 64 hex; a reference never opens a booking; `no-store` and `no-referrer` on keyed pages |
| CSRF exemptions | only `book/unsubscribe/*` (one click, can only stop emails), `book/payfast/notify` (PayFast's four checks under the business's passphrase), `api/v1/*` (Bearer key, no cookie, no CORS) |
| Webhooks in | PayFast deposits: signature, source IP, POST back, amount; Paystack: verified with Paystack on return and by the sweep; the Bookings plan reuses the platform's Paystack webhook (signature, IP, verify, amount) |
| Calls out | webhook endpoints and Jitsi servers through `SafeUrl` (https, port 443, public addresses only), no redirects followed, 10 s timeout; nothing called inside a customer's request |
| Secrets | integration accounts encrypted (CI4 Encrypter), write only in the UI; API keys stored as SHA-256 of a 160 bit secret, shown once; webhook payloads signed with a timestamp |
| Raw queries across businesses | only where a random hash, token, reference or key prefix picks the row, or in cron sweeps; every other read goes through `forBusiness()` |
| Output | views escape with `esc()`; no inline script, style or handler (CSP); positions set through CSSOM |
| Exports | sent messages CSV strips leading `= + - @`; customer data export is admin only and audited |
| Platform admin | counts and setup only; appointment list only through the support view, with a reason written to the business's audit log (D12) |

## Known risks, accepted for launch

| Risk | Why accepted | Later |
|---|---|---|
| DNS rebinding between `SafeUrl`'s check and the request | the check runs right before each call; CI4's client cannot pin the resolved address | pin the address with a raw cURL `CURLOPT_RESOLVE` if webhooks see real use |
| A new API key sits in the session's flash data until the next page | server side session, `no-store` page, removed on the next request | none planned |
| ScrapeGuard counts booking page JSON with profile pages (120 a minute per address) | a customer stays far below it; many people behind one office address could meet it | exempt `book/days` and `book/slots` if 429s show in the logs |
| The webhook secret is shown to admins on the Connections tab | admins own the endpoint; a new secret can be made at once | none planned |
| Two factor sign in | decision T16, later | Phase after launch |

## Not reviewed here

The production server itself (Cloudflare, Apache, PHP settings) is covered by the October
2026 audit (memory: security audit Oct 2026). Re-check the Cloudflare scanner rule and AI
crawl rules do not block `/api/v1/*` callers at release.
