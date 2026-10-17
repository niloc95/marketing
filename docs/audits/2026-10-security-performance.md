# Audit 3 of 3: Security, Privacy and Application Quality

WebScheduler Local (https://local.webscheduler.co.za), audited 17 October 2026 against `main` at `f3d4a5a`.
Report only: no code, data or configuration was changed, and nothing was tested actively against production.
Production was read only: `SELECT` queries, log reads and file permission listings over SSH, plus public GETs.
Query counts come from the local app. No secrets, tokens, personal data or document contents appear below.

Corrections to the brief: the host is a hand-built Ubuntu 24.04 Lightsail instance (Apache, PHP 8.3-FPM,
MySQL 8.0) behind Cloudflare, not Bitnami. The CSP is already **enforcing**, not mid-rollout.

## 1. Summary

The core security controls are strong: documents are stored outside the web root behind an id-only, logged,
CSP-locked admin route; sign-in tokens are 256-bit, hashed, single use and short lived; admin needs both a
Cloudflare Access JWT verified at the origin and a password; and PayFast notifications are checked four ways
with replay protection. No Critical issue was found. The real gaps are in privacy: ID documents are kept
indefinitely for applications that stall before payment, soft-deleted profiles are never purged, and 262
imported profiles publish business contact details with no record of notice or consent, which the privacy
policy doesn't describe. Operationally, the release script undoes the code's `0700` document permissions, and
33,000 session files have built up since 18 August because nothing collects them. All of these are small fixes.

## 2. Critical and High findings

**No Critical findings.**

### S-01 (High): Imported profiles have no notice or consent record, and the privacy policy doesn't cover them

- **Where:** production data; `app/Views/legal/privacy.php`.
- **Evidence:** 262 live profiles are `source = webscheduler`, added by us rather than submitted. 0 of them have
  `terms_accepted_at`, 146 publish a phone number, and all 262 show an address. The privacy policy describes
  information people submit, and nowhere says we add businesses from other sources. POPIA applies to juristic
  persons too, and s18 requires notifying the subject when information isn't collected from them directly.
- **How it could go wrong:** a business owner finds their shop listed with a phone number they never gave us,
  has no way to claim or remove it (see Audit 1, C-03), and complains to the Information Regulator.
- **Fix:**
  1. Add a "Businesses we add" section to the privacy policy covering the source, the purpose, and how to
     claim, correct or remove a profile.
  2. Record the source and import date on each row.
  3. Send a one-time notice wherever a contact channel exists.
  4. Add a "Remove this profile" request link on profiles that have no owner.

### S-02 (High): ID documents are kept indefinitely for applications that never finish

- **Where:** `app/Models/DirectoryVerificationDocumentModel.php:232-236` and `app/Commands/VerificationSweep.php:92-93`.
- **Evidence:** the end-of-life purge only covers `lapsed` and `rejected` applications. An application left at
  `submitted` (never reviewed) or `approved` (never paid) is never purged. Production has 2 `approved`
  applications whose ID copies have been held for 59 and 18 days. The privacy policy (`privacy.php:336-339`)
  only covers documents kept while a badge is "active or paused", after a lapse or rejection, or after
  replacement, so this state isn't described at all.
- **How it could go wrong:** the most sensitive data the system holds accumulates for applicants who walked
  away, with no deletion date, in breach of POPIA s14 (retention limitation).
- **Fix:** treat `approved` with no payment for 90 days, and `submitted` with no review for 90 days, as ended.
  Notify the owner first (this pairs with the payment reminder in Audit 1, C-04), then purge on the same
  schedule as rejected applications. Add the rule to the privacy policy.

### S-03 (High): Soft-deleted profiles are never purged

- **Where:** `app/Commands/*` (no job exists); production.
- **Evidence:** 2 profiles have `deleted_at` set, the oldest from 30 Sep 2026. No command removes soft-deleted
  rows permanently. The privacy policy says deleted profiles "may initially be soft deleted before being
  permanently removed" (`privacy.php:305-306`), which implies a removal that never happens. Verification
  documents are only "deleted in full when the associated business profile is deleted" (`privacy.php:339`),
  so a soft delete may leave them in place too.
- **How it could go wrong:** an owner who asked for deletion still has their email, phone and address in the
  database, plus possibly their ID documents.
- **Fix:** a nightly job that hard-deletes rows soft-deleted more than N days ago (30 is a common window),
  cascading to photos, documents and team rows and removing the files on disk. State N in the policy.

## 3. Findings table

| ID | Sev | Area | Location | Evidence | Impact | Fix | Effort |
|---|---|---|---|---|---|---|---|
| S-01 | High | POPIA | data; `privacy.php` | See §2 | Notification gap for 262 businesses | Policy section, source field, notice and remove link | M |
| S-02 | High | Documents | `DirectoryVerificationDocumentModel.php:232-236` | See §2 | IDs kept with no end date | Purge stalled states after notice | S |
| S-03 | High | POPIA | no purge job | See §2 | Deleted data kept forever | Nightly hard delete after N days | S |
| S-04 | Medium | Documents | release block in `.claude/skills/deploy-production/SKILL.md:70` and `deploy/LAMP-SETUP.md:264` | `chmod -R 2775 writable` runs on every release, and only the top-level `verification/` is reset (`2770`). Production: all 5 per-listing folders and all 11 document files are `2775`, not the `0700` the code sets (`VerificationDocumentProcessor.php:176-187`). The parent `2770` still blocks other local users (no "other" execute bit), so nothing is exposed today | One permission change on the parent would make every ID world-readable on the box. CVs in `writable/careers` get the same treatment | Exclude `writable/verification` and `writable/careers` from the recursive chmod (`find writable -path '*/verification' -prune -o … -exec chmod`), then reset the existing files to `0600` and folders to `0700` | S |
| S-05 | Medium | Performance / ops | `writable/session`; `Config/Session.php:44` | 33,193 session files (133 MB), the oldest from 18 Aug, about 900 new a day. `session.gc_probability = 0` and Ubuntu's `sessionclean` cron only cleans `/var/lib/php/sessions`. Every anonymous GET starts a session (both cookies are set on `/` and profile pages) | Unbounded growth in file count; slower `writable` operations and backups; session files for every crawler visit | A cron job `find writable/session -type f -mmin +240 -delete`, or `gc_probability = 1` for this pool. Better still, don't start a session for anonymous GETs that don't need one | S |
| S-06 | Medium | POPIA | `/manage`, `privacy.php:342-359` | Rights are listed, and the routes to exercise them are the contact form only. There's no owner "delete my profile" or "download my data" | Each request is manual; deletion and the S-03 purge depend on someone remembering | Owner-side "Delete profile" (soft delete plus the S-03 purge) and a JSON export from the dashboard | M |
| S-07 | Medium | Config | production `.env` | `encryption.key` is absent (already known: memory partner-program). `PartnerService` refuses to save bank details without it, by design | Partner payouts can't be configured; anything added later that uses the Encrypter fails closed | `php spark key:generate` on production. This is safe: nothing is encrypted yet, so there's nothing to re-encrypt | S |
| S-08 | Low | Payments | `app/Controllers/PayFastNotify.php:105-153` | Checks are the signature (with passphrase, set on production), source IP (Cloudflare-aware, `Config/App.php:247+`), PayFast postback, amount and replay (`VerificationService.php:682`). `merchant_id` isn't compared | Defence in depth only: an ITN from another merchant account would still need our passphrase | Reject an ITN whose `merchant_id` isn't ours, before the other checks | S |
| S-09 | Low | Documents | `Admin.php:830-835` | "Admin opened verification document N from IP". The access log has no identity, though the Cloudflare Access JWT carries the admin's email | With more than one admin, nobody can say who opened an ID | Log the JWT `email` claim (or its hash) with the document id | S |
| S-10 | Low | CSP | live headers | `Content-Security-Policy-Report-Only` is sent with an **empty value**. `form-action` on production still lists `sandbox.payfast.co.za` | Header noise, and a sandbox endpoint allowed on live | Don't emit an empty report-only header; drop the sandbox host when `payfastSandbox` is off | S |
| S-11 | Low | CSP / analytics | production logs | GA4's core collect is allowed, but Google Signals and ads beacons are blocked daily (`www.google.co.za` 148, `www.google.com` 120, `stats.g.doubleclick.net` 72, `connect-src www.googletagmanager.com` 24 in 30 days) | Demographics and Signals data is missing; the logs are noisy | Either turn off Google Signals in GA4 (fits the consent posture) or allow those hosts deliberately | S |
| S-12 | Low | Email | `Libraries/Mailer.php`, `ProfileNudgeService.php:187` | The marketing nudge (opt-in only, `:74`) has an unsubscribe link, and `/unsubscribe/{token}` supports RFC 8058 POST, but no `List-Unsubscribe` header is sent | Weaker deliverability, and inconsistent with the CSRF exemption made for one-click | Add `List-Unsubscribe` and `List-Unsubscribe-Post` headers to marketing sends | S |
| S-13 | Low | Timezone | `JobBoardService.php:154, 924, 932, 1221` | The app runs on UTC (`App.php:174`), and vacancy expiry compares `valid_through` with `date('Y-m-d')`. JSON-LD `validThrough` is `23:59:59+00:00`. Opening hours correctly use `Africa/Johannesburg` (`directory_hours_helper.php:96`) | Vacancies close 2 hours late, and Google sees an end time of 01:59 SAST the next day | Use the same Johannesburg clock for the job dates; emit `+02:00` | S |
| S-14 | Low | Forms | `Filters.php:99-110,159`, `BotTrap.php` | The framework honeypot is injected into GET search forms too, and a trip returns bare-text 403 "Forbidden". The signup trap returns a fake success by design (abuse side fine; UX side in Audit 1, C-01) | Bot handling works; the cost lands on real users (Audit 1) | See Audit 1, C-01 and C-15 | S |
| S-15 | Low | Logging | production logs | About 3,700 CSP warnings in 30 days, mostly `eval`, inline style and font loads from browser extensions (an extension font host was seen locally) | Real violations are buried | Drop reports whose `source-file` is a `chrome-extension:` or `moz-extension:` URL, or sample them | S |
| S-16 | Low | Abuse | `Config/Cache.php` (file handler) | Every public form is throttled per IP and per recipient (signup, manage, contact, recommend, reviews, jobs, careers, partners, profile setup), but the throttles live in the file cache | If `writable/cache` stops being writable, every limit silently turns off (`/health` checks this) | Keep the `/health` monitor alerting; consider Redis when traffic grows | S |
| S-17 | Low | POPIA | data | 22 of the 39 public signups predate the consent columns (17 Sep) and have no `terms_accepted_at` | No consent record for the oldest owners | Ask for confirmation at their next sign-in | S |

**Resolved in the window, no action needed:**
- 763 CRITICAL `Undefined array key "args"` in the framework's exception handler, last seen 28 Sep. This was
  `zend.exception_ignore_args`, now handled in `Config\Exceptions`.
- 126 Mapbox HTTP 401s, last seen 10 Oct (the token was rotated that day; memory mapbox-geocoding-prod).
- 175 `img-src` blocks for the old `listing.` host, all on 3 Oct, the hostname move day. No stored path
  references it.

**Checked and found sound**

| Area | What was checked |
|---|---|
| Sign-in | `/manage` tokens are `bin2hex(random_bytes(32))` (`TokenHash.php:18`), stored as SHA-256, 1 hour (`Config/Directory.php:239`), single use (cleared on redeem, `DirectoryListingMutationService.php:596-598`), and the session is regenerated on redeem (`Manage.php:125`). Requests are throttled 5 per 10 minutes per IP and 3 per 10 minutes per address, with a reply that doesn't reveal whether an account exists (`Manage.php:72`) |
| IDOR | Photo delete (`Manage.php:300`) and every job action (`Jobs.php:607, 619`) check the owning listing. Team and branch saves resolve posted ids with `findForListing($id, $listingId)` (`TeamMemberService.php:161`, `PracticeLocationService.php:111`), so a foreign id becomes an insert, not an overwrite. `OWNER_EDITABLE` keeps status, slug, email, featured and verification fields out of owner writes |
| Admin | The `admin` group filter covers every admin route, GET and POST (`Routes.php:209`), with an idle timeout (`AdminFilter.php:32-37`). The password is a hash and throttled 5 per 15 minutes, with a session regenerate on login. The Cloudflare Access JWT is verified at the origin (RS256 pinned, plus `kid`, `aud`, `iss`, `exp` and `nbf`). Production origin `/admin` without a JWT returns **403** |
| Cookies | `ci_session` and `csrf_cookie_name` are `Secure; HttpOnly; SameSite=Lax`, and sign-out removes the session key |
| Payment state | The badge is a render-time date compare on `verified_until` (`listing_is_verified_business()`), written only by `applyPaidUntil()`. Checkout is reachable only from `approved` or `lapsed` (`Manage.php:431-435`). The billed amount is the server-side snapshot (`PayFast.php:96-145`), never client input. On lapse, the team and branch panels hide (render-gated), and live vacancies run to their closing date but can't be renewed |
| CSRF | Global, with five documented exemptions (`Filters.php:135`): the CSP report, the two PayFast ITNs, RFC 8058 unsubscribe, and the side-effect-free score endpoint. The newsletter form posts off site to Mautic, so CSRF doesn't apply there |
| Output escaping | No view echoes owner-supplied text without `esc()`; the unescaped echoes are integers, constants or view-built copy. The description is rendered through an allowlist DOM rebuild (`RichText.php:59, 114, 340-446`) with no attributes beyond a filtered `class` and a scheme-checked `href` |
| CSP | Enforcing: nonces, no `unsafe-inline`, `script-src-attr 'self'`, `object-src 'none'`, `frame-ancestors 'none'`. Also HSTS (2 years, including subdomains), COOP and a tight Permissions-Policy. **Suggested next phase:** add `upgrade-insecure-requests`, move from `report-uri` to `report-to`, and trial `require-trusted-types-for 'script'` in report-only |
| Caching | Every page is `Cache-Control: no-store`, and Cloudflare reports `DYNAMIC`. No personalised page can be served from a shared cache |
| Hard-coded values | The price comes from `DirectorySettings` (no view literal). The photo, team and branch caps are service constants, by design, so the copy reads the same number the code enforces |
| Secrets hygiene | The production `.env` has no `directory.adminPassword` and no `database.tests.*`, and is `640 deploy:www-data` with `CI_ENVIRONMENT=production` |

## 4. Performance

Query counts per page, measured locally with `performance_schema` and a warm cache:

| Page | Queries |
|---|---|
| `/` | 14 |
| `/directory` | 8 |
| category page | 7 |
| province page | 8 |
| profile page | 16 |
| `/jobs` | 5 |
| `/verified` | 0 |
| `/compare` | 0 |

**No N+1 patterns.** The repeated home query is one city lookup per province that has profiles, bounded at 9
(4 on production).

**Indexes (production).** `status`, `category_id`, `province`, `city`, `slug` (unique) and FULLTEXT are all
indexed, and the rank order has the composite `idx_listings_rank`. `EXPLAIN` on category plus province uses
`category_id` with a filesort, which is trivial at this row count and fine at 2,500.

## 5. Verification document lifecycle

1. **Upload**, at signup or from the dashboard. The extension allowlist is PDF, JPEG and PNG. Then a 10 MiB cap,
   finfo MIME matched to the extension, and a magic-byte signature check
   (`VerificationDocumentProcessor.php:48-171`). Files are never re-encoded, and a SHA-256 is recorded.
2. **Store** at `writable/verification/<listing_id>/verif_<timestamp>_<16 hex random>.<ext>`
   (`:258-260`), outside the docroot. The code sets `0700` folders. ⚠ **Gap S-04:** the release script resets
   them to `2775`.
3. **Notify.** The admin email says documents arrived and never attaches or links them
   (`emails/verification-admin.php:21`). No log line includes file names.
4. **Review.** `GET /admin/verification/document/{id}` sits behind Cloudflare Access and the admin login, takes a
   row id (never a path) and checks containment. It's served with a locked CSP, `nosniff`, `no-store`, `inline`
   and a sanitised filename (`Admin.php:812-856`). Access is logged by id and IP. ⚠ **Gap S-09:** the log
   doesn't say who.
5. **Decision.**
   - **Rejected:** a reason is emailed and the owner can resubmit; the old documents become superseded.
   - **Approved:** a payment link is emailed.
6. **Superseded** documents are purged after 90 days (`VerificationSweep.php:58`).
7. **Ended.** Lapsed or rejected evidence is purged 365 days after `paid_until` or `reviewed_at`
   (`:66`, `DirectoryVerificationDocumentModel.php:232-236`).
   - ⚠ **Gap S-02:** `submitted` and `approved` (unpaid) are never purged.
8. **Profile deleted:** all documents are removed (privacy §).
   - ⚠ **Gap S-03:** a soft delete doesn't trigger this, and nothing hard-deletes later.

## 6. Top 5 fixes

1. **S-02: purge documents for stalled applications.** ID copies are the most sensitive data held, and this
   is a single `whereIn` change plus a notice.
2. **S-03: hard-delete soft-deleted profiles on a schedule.** It makes the privacy policy true and closes a
   path where documents can survive deletion.
3. **S-01: notice and a remove route for the imported profiles.** It covers 87% of the directory under
   POPIA s18, and the work overlaps with the claim flow in Audit 1.
4. **S-04: stop the release script loosening document permissions.** A one-line change to a command typed on
   every release.
5. **S-05: collect stale session files.** One cron line, and it stops unbounded growth that tracks crawler
   traffic.

## 7. Out of scope observations

**For the conversion audit**
- The signup honeypot's fake success and the lost form input (C-01, C-02, C-06) are the user-facing side of
  S-14.
- The two approved, unpaid applications in S-02 are also a revenue leak (C-04).
- The missing lapse email (C-12) means owners don't know their documents are on a 12-month clock.

**For the SEO audit**
- Every page is `no-store` and sets cookies, so there's no edge caching for crawlers.
- The vacancy `validThrough` timezone (S-13) matters for Google for Jobs.
- `robots.txt` is dynamic (`Directory::robots()`).
- Crawler visits create session files (S-05).

## Appendix: commands run

```
# code (local)
grep/sed over app/Libraries/{VerificationDocumentProcessor,PayFast,RichText,CloudflareAccess,TokenHash,Mailer}.php,
  app/Controllers/{Admin,Manage,Jobs,PayFastNotify,Listing}.php, app/Controllers/Concerns/*,
  app/Filters/{AdminFilter,CloudflareAccessFilter,BotTrap}.php, app/Config/{Filters,App,Session,Security,Routes}.php,
  app/Services/{VerificationService,DirectoryListingMutationService,TeamMemberService,PracticeLocationService,
  ProfileNudgeService,MarketingConsentService,JobBoardService}.php, app/Models/DirectoryVerificationDocumentModel.php,
  app/Commands/VerificationSweep.php, app/Views/** (unescaped echo scan), app/Views/legal/privacy.php

# local query counts
TRUNCATE performance_schema.events_statements_summary_by_digest; curl localhost:8095/<page>;
SELECT SUM(COUNT_STAR) … WHERE SCHEMA_NAME='webscheduler_directory'

# live, GET/HEAD only
curl -sD- https://local.webscheduler.co.za/{,manage,directory/cpt-dental,directory/province/gauteng,admin}

# production, read only (ssh lightsail; values never printed, only counts and modes)
grep -c on .env keys (accessTeamDomain/accessAud, payfastPassphrase, adminPassword, encryption.key, CI_ENVIRONMENT)
curl -k -H 'Host: local.webscheduler.co.za' https://127.0.0.1/admin        # origin check without JWT
find writable/verification -printf '%m'; find writable/session | wc -l; du -sh
SELECT … source/consent/phone/address counts; soft-deleted count; SHOW INDEX via information_schema; EXPLAIN
log-*.log (last 30 days): grep WARNING/ERROR/CRITICAL, masked (emails, IPs, numbers, quoted values) and grouped
```
