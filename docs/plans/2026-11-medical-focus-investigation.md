# Investigation: Medical focus details

**Prepared:** 16 Nov 2026  
**Branch:** `plan/medical-focus`  
**Production data read:** 16 Nov 2026 via `ssh lightsail`, SELECT only.

Feeds the main plan at `docs/plans/2026-11-medical-focus.md`. Each section names the
phase it supports.

---

## B1. "Customer" inventory (Phase 2)

The word "customer" appears in owner area views, emails, profile panels and Bookings.
Most uses fall into one of four categories:

- **Structural Bookings term:** "customer" is the internal name for the person who
  books. `BookingCustomerModel`, routes, PHP keys and JS messages all use it.
  Copy in the booking pages (book/ views, `docs/bookings/copy.md`) uses "customer"
  for the booker, which is correct for general businesses. For medical profiles the
  booker is a "patient", which is what `listing_audience()` will return.
- **General platform voice:** "customers find you on the platform", "profile customers
  can find" in home and marketing copy. These are category-neutral and should stay.
- **Per-profile owner area and emails with a listing in context:** These use
  `listing_audience()` once it exists.
- **Code context (comments, PHP variable names, route names):** Unchanged.

### Owner area (`app/Views/business/`)

| File | Line | Current text | Action |
|---|---|---|---|
| `team.php` | 22 | `The people customers can book, and when they work.` | `listing_audience()` (plural) |
| `_settings_notifications.php` | 23 | `What customers get` | `listing_audience()` (plural) |
| `_settings_notifications.php` | 33 | `customer[...]` (form input name) | keep (code identifier) |
| `overview.php` | 30 | `Your business profile is live...` | `listing_noun()` |
| `home.php` | 118 | `Your business profile` | `listing_noun()` |
| `home.php` | 119 | `...come from your business profile.` | `listing_noun()` |
| `analytics.php` | 87 | `'business'` series key in chart | keep (code identifier / data key) |
| `availability.php` | 83 | `As the business (no notice or horizon rules)` | keep (internal admin toggle, not customer-visible) |

### Emails (`app/Views/emails/`)

| File | Current text | Action |
|---|---|---|
| `booking-team.php` | `Email the customer` | keep. This is the booker, always a customer regardless of practice type. |
| `booking-team.php` | `From WebScheduler Local; Reply goes to the customer.` | keep (comment) |
| `booking-plan.php` | `your calendar and customers stay in your business area` | `listing_audience()` for "customers"; keep "business area" (URL reference) |
| `booking-plan.php` | `so customers can keep booking online` | `listing_audience()` |
| `booking-payment-account.php` | `so customers no longer pay a deposit` | `listing_audience()` |

### Profile panels (`app/Views/directory/`)

| File | Current text | Action |
|---|---|---|
| `_reviews_panel.php` | `Let customers review my business` | `listing_audience()` + `listing_noun()` |
| `_reviews_panel.php` | `Share your profile link with happy customers` | `listing_audience()` |
| `_reviews_panel.php` | `A short, polite reply helps the next customer` | `listing_audience()` (singular) |
| `_reviews_panel.php` | `customers can no longer write new ones` | `listing_audience()` |
| `_contact_panel.php` | `a customer reaches for them` | keep (comment) |
| `_contact_panel.php` | (no customer-visible text; it's a PHP comment) | keep |

### Home page and general marketing (`app/Views/directory/home.php`)

| Text | Action |
|---|---|
| `profile customers can find` | keep. General platform voice. No listing in context. |
| `Let customers book` (feature strip) | keep. Category-neutral, marketing voice. |

### Bookings views (`app/Views/book/`)

| File | Text | Action |
|---|---|---|
| `customer.php` | `Your bookings, {name}` | keep. Addresses the person directly; they are always a "customer" in Bookings. |
| `manage.php` | `All your bookings with {business}` | `listing_noun()` for the business name placeholder, not the word "customer". |
| `unsubscribe.php` | `We will not email you about bookings with {business}` | `listing_noun()` for the referenced business. |

### `docs/bookings/copy.md`

| Current text | Action |
|---|---|
| `"Business profile", never "listing" or "directory"` | Update to `"Practice profile" or "business profile"` |
| `booking (what the customer makes)` | keep. Structural term for the booking entity. |
| Status "We have your request. {Business} will confirm it." | `listing_noun()` for `{Business}` placeholder |

### Surfaces where the audience cannot be known at render time

The following pages render without a listing in context. `listing_audience()` cannot
be called here. Keep "customer" or use a neutral phrase:

- `/directory` (Discover results): no single listing in context. Keep neutral.
- `/directory/categories` hub page: same.
- `/jobs` board public pages: posters are businesses, not patients.
- Marketing copy on `/pricing`, `/compare`, `/verified`, `/plus`, home page hero:
  use the owner-decided general voice ("Add your practice or business", etc.).
- Flash and validation messages in `DirectoryListingMutationService`: no listing
  row available yet during signup validation; keep "business" or make neutral.

---

## B2. The 257 uncategorised doctor imports (Phase 3c)

**Production count (16 Nov 2026):** 256 profiles with `category_id IS NULL` and
`signup_source IN ('import-doctors', 'import-lenmed')`.

```sql
SELECT
  SUM(CASE WHEN email IS NOT NULL AND email!='' THEN 1 ELSE 0 END) has_valid_email,
  SUM(CASE WHEN email IS NULL OR email='' THEN 1 ELSE 0 END) no_email,
  SUM(CASE WHEN is_verified=1 THEN 1 ELSE 0 END) email_confirmed,
  SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) published
FROM xs_directory_listings
WHERE signup_source IN ('import-doctors','import-lenmed') AND deleted_at IS NULL;
-- (from the full import set, not just uncategorised)
```

Result for uncategorised rows:

| Field | Count |
|---|---|
| Total | 256 |
| Title "Dr" | 226 |
| Title Mr / Mrs / Ms / Prof | 18 |
| Title NULL | 11 |
| Has credentials | 1 |
| Has description | 256 |

### What the descriptions say

All 256 descriptions follow the template used by the import command:
`Dr [Name] is a healthcare practitioner consulting at [Hospital] in [Province],
offering professional, patient centred care.`

The word "specialist" appears in all 256 descriptions. The import assigned a generic
"healthcare practitioner" description rather than mapping a specialty, so the
description alone does not distinguish a cardiologist from a GP.

### What could pick a category

| Signal | Available on uncategorised rows | Reliability |
|---|---|---|
| Title "Dr" | 226/256 | Maps to Health and Medical but not to a specific specialty. Suggests Specialist Physician as a safe catch-all. |
| Credentials field | 1/256 | Not useful at scale. |
| Description text (specialty words) | 1/256 contain specialty terms beyond "healthcare practitioner" | Very low. All are "healthcare practitioner". |
| Team registration number | 0 (no team rows on these profiles) | Not available. |
| Original import CSV | 215 from import-doctors, 41 from import-lenmed | May have specialty data; files are not in `/home/deploy` or `/var/www/listing/seed/`. |

### Proposed mapping rule

For a dry-run command (`directory:recategorise-imports --dry-run`):

1. `title = 'Dr'` and `signup_source IN ('import-doctors','import-lenmed')` and
   `category_id IS NULL`: assign `Specialist Physician` (the safest catch-all for a
   doctor whose specialty is unknown). **225 rows** would be categorised.

2. Rows with no title or a non-Dr title (10 Mr/Mrs/Ms/Prof + 11 NULL): leave
   uncategorised; flag for admin review. **31 rows** remain.

**Confidence:** High for the 225 Dr rows (they are all health professionals, Specialist
Physician is a valid category and prevents them rendering with no group). Low for the 31
non-Dr rows; some may be administrative staff or non-medical contacts from the import;
they need a human look.

### Dry-run command design

The command should:
1. Report how many rows would change (`--dry-run` flag, no writes).
2. Write a CSV report with id, display\_name, title, proposed\_category, reason.
3. Accept a `--source` filter (import-doctors, import-lenmed, or both).
4. Write only `category_id` and `updated_at`; nothing else.
5. Never write if the category slug does not exist in the DB.

A one-off admin command is appropriate; the work does not need a migration.

---

## B3. Reviews on medical profiles (Phase 3b)

### Where reviews render or are counted

| Surface | File | Notes |
|---|---|---|
| Profile page panel | `app/Views/directory/_reviews_panel.php` | Shows review form, existing reviews, owner reply, enable/disable switch |
| Profile page rating | `app/Views/directory/_profile_head_parts.php` | Star rating summary in the profile header |
| Result cards | `app/Views/directory/_card.php` line 62 | Star summary from stored totals (`rating_avg`, `review_count`) |
| JSON-LD aggregateRating + review | `app/Helpers/schema_helper.php` lines 268-315 | Emitted only when `$l['reviews']` has items; guard is "count > 0 AND avg >= 1" |
| /compare page | `app/Views/directory/compare.php` | Shows "Free" for reviews in the feature table; no counts |
| Owner dashboard | `app/Views/business/overview.php` and `_reviews_panel.php` | Switch and review list |
| Admin | `/admin/reviews` | Queue, approve, hide, reject |
| Review invite email | `app/Views/emails/review-invite.php` | Sent after appointment (Bookings); has the listing in context |
| Review notify email | `app/Views/emails/review-notify.php` | Sent to owner on new review |
| Review reply email | `app/Views/emails/review-reply.php` | Sent to reviewer on owner reply |
| Sitemap | `DirectoryService::sitemapUrls()` | Profile URLs only; no review-specific sitemap entries |
| Stats | `ListingStatsService` | Does not count reviews; rating totals are on the listing row |
| Search Console queries | `xs_directory_listing_search` and `_listing_search_queries` | Not broken down by review presence |

### Where a review is accepted

| Route | Controller | Throttles |
|---|---|---|
| `GET /reviews/{slug}` | `Reviews::form()` | None on GET |
| `POST /reviews/{slug}` | `Reviews::submit()` | `review-ip-` 3/h, `review-to-` 2/day |
| `GET /reviews/{slug}/confirm/{token}` | `Reviews::confirm()` | None; link is single-use |
| `POST /manage/reviews/{id}/reply` | `Reviews::reply()` | None on reply |
| `POST /manage/reviews/enabled` | `Reviews::toggleEnabled()` | None |
| `POST /admin/reviews/{id}/approve` etc. | AdminReviews | Admin only |

The key guard is `ReviewService::acceptsReviews($listing)` checked in
`Reviews::form()` (line 61) and `Reviews::submit()` (line 226).

### Production counts (16 Nov 2026)

```sql
-- Total reviews
SELECT status, COUNT(*) cnt FROM xs_directory_reviews GROUP BY status;
-- Result: published 1

-- Reviews per group
SELECT c.group_name grp, COUNT(l.id) total,
  SUM(IF(l.reviews_enabled=0,1,0)) rev_off,
  SUM(IF(l.review_count>0,1,0)) has_rev
FROM xs_directory_listings l
JOIN xs_directory_categories c ON l.category_id=c.id
WHERE l.status='published' AND l.deleted_at IS NULL
GROUP BY c.group_name ORDER BY total DESC;
```

| Group | Published profiles | reviews\_enabled = 0 | Has reviews |
|---|---|---|---|
| Health and Medical | 2466 | 1 | 0 |
| Legal and Financial | 102 | 0 | 0 |
| Home Industry and Handmade | 58 | 0 | 0 |
| Retail and Other | 50 | 0 | 0 |
| Beauty and Wellness | 30 | 0 | 1 |
| Professional Services | 11 | 0 | 0 |
| Everyday Services | 9 | 0 | 0 |
| Home and Trades | 9 | 0 | 0 |
| Education and Training | 4 | 0 | 0 |
| (others) | 11 | 0 | 0 |

**Key finding:** There is exactly 1 published review in the entire database, on a
Beauty and Wellness profile. No Health and Medical profile has any reviews. Turning
reviews off for Health and Medical has zero impact on existing public content.

### Design: `Config\Reviews::$offForGroups`

```php
/**
 * Category groups for which new reviews are off by default, pending legal
 * advice. The per-profile reviews_enabled column can still override this
 * on (once clearance is given) but not turn it off (the group rule wins).
 *
 * @var list<string>
 */
public array $offForGroups = ['Health & Medical', 'Pets & Animals'];
```

`ReviewService::acceptsReviews(array $listing)` becomes:

```php
public static function acceptsReviews(array $listing): bool
{
    $group = $listing['category_group'] ?? $listing['group_name'] ?? '';
    if ($group !== '' && in_array($group, config('Reviews')->offForGroups, true)) {
        // Per-profile opt-in is not supported while the group is off.
        return false;
    }
    return (int) ($listing['reviews_enabled'] ?? 1) === 1;
}
```

The existing per-profile `reviews_enabled = 0` row for the one medical practice that
already has reviews off is harmless; the new group rule simply agrees with it.

### What a visitor sees when reviews are off at the group level

Do not reuse the per-profile "reviews off" message (which implies the owner made a
choice). Show a neutral holding line:

```
Reviews are not available for this practice at this time.
```

This line hides the review form, the existing review list (there are none), and the
star rating summary. The owner panel shows the same line and no "turn off" switch
(the group rule is not a per-owner setting).

### What happens to a review form left open

A visitor who opens the review form before reviews are turned off and submits after
will get `ReviewService::acceptsReviews()` returning false in `Reviews::submit()`,
which currently answers the same as "not found" (line 61 of `Reviews.php`). The same
response continues to be appropriate: no error that reveals why, same neutral redirect.

### JSON-LD consistency

`schema_helper.php` builds `aggregateRating` and `review` only from
`$l['reviews']['items']` and `$l['reviews']['count']`. `DirectoryService::getProfile()`
assembles `$l['reviews']` from published reviews. With zero published reviews on medical
profiles, neither key is emitted in JSON-LD today and nothing changes when reviews are
turned off at the group level.

If reviews were previously enabled and then turned off, published reviews stay in the DB
(the owner switch says so explicitly). The group-level off should also keep published
reviews visible (data not deleted) but stop new ones. The proposed implementation above
only gates new submissions; existing published reviews continue to render and appear in
JSON-LD until the owner hides them individually. This is consistent with the existing
per-profile behaviour.

---

## B4. HPCSA and SAVC advertising rules (Phase 3b)

**This section lists risks as questions for the owner and their lawyer. It is not legal
advice and no conclusions are drawn.**

### HPCSA Ethical Rules of Conduct (Government Gazette R717 of 2006, Booklet 2)

Source: HPCSA "Ethical Rules of Conduct for Practitioners Registered Under the Health
Professions Act, 1974", available at
`https://ipasa.co.za/Downloads/Ethics%20and%20Professional%20Practice/Health%20Professions%20Council/booklet_2_generic_ethical_rules_with_anexures.pdf`
and `https://www.saflii.org/za/legis/consol_reg/erocfpruthpa1974803.pdf`

**Section 25: Advertising and canvassing.** Key points from the rules:

- Advertising is allowed when it is honest, factual and not misleading.
- Canvassing is prohibited. Canvassing includes conduct (verbal, printed or electronic)
  that draws attention to a practitioner's personal qualities, superior knowledge,
  quality of service, professional guarantees, or best practice, in order to secure
  business.
- Comparative advertising (asserting superiority over other practitioners) is forbidden.
- Testimonials from clients or patients, where used to attract new clients, are
  generally treated as a form of canvassing and therefore restricted.

**Our features mapped:**

| Feature | HPCSA risk | Risk level | Question for owner and lawyer |
|---|---|---|---|
| **Patient reviews (star ratings, text)** | Publishing patient testimonials that highlight a practitioner's personal qualities or outcomes. The platform does not solicit reviews for the practitioner, but displaying them on the profile could be construed as the practitioner using them in advertising. | **High** | Is a third-party review platform that the practitioner did not set up still bound by Section 25? Should medical profiles carry a disclaimer: "These reviews are patient experiences and are not an assessment of clinical quality"? |
| **"Verified" badge (Documents checked by WebScheduler Local)** | The HPCSA registers practitioners; a badge that checks business registration documents and ID could be confused with HPCSA registration or clinical accreditation. | **Medium** | Should the Verified tooltip on medical profiles carry an additional line: "This is not an HPCSA registration or clinical accreditation check"? |
| **"Featured" placement on the home page** | A "featured" label could imply the practitioner is superior or preferred. The admin controls this independently of payment. | **Low** | Is a "featured" label appropriate on health practitioner profiles? Should it be replaced with a neutral label such as "sponsored" for medical profiles? |
| **`/compare` page (platform comparison)** | The compare page compares WebScheduler Local against other platforms (Google, LinkedIn), not practitioners against each other. No practitioner-to-practitioner comparison exists. | **None** | No action required unless a future "compare profiles" feature is built. |
| **Lead alerts (Jobs board service requests)** | Service request alerts notify practitioners when a patient or customer posts a request in their category. This resembles solicitation referral services that some professional bodies restrict. The platform sends the alert, not the practitioner. | **Low to Medium** | Is the Jobs board appropriate for health practitioners? Is there a distinction between a "service request" (equipment, admin work) and a "patient referral" that keeps the board clean? |
| **"Treatments and fees" prices on profiles** | Displaying fees could be allowed if factual. A concern arises if fees are compared between practitioners. The platform does not compare fees across profiles in any automated way. | **Low** | No action required unless a fees comparison feature is built. |
| **Online bookings (Bookings)** | Enabling online bookings is a standard convenience feature, not advertising. No superiority claim is made. | **None** | No action required. |
| **Claim outreach email (Phase 3c)** | Sending an email to an imported practitioner telling them "patients found you N times" could be seen as canvassing on their behalf, since the platform is showing data about their profile. | **Low to Medium** | Does the outreach email constitute advertising by the platform for the practitioner, or is it an administrative notification? The proposed content (factual impressions data, a claim link, an unsubscribe option) is likely administrative. Confirm with legal counsel before sending. |

### SAVC (Veterinary and Para-Veterinary Professions Act, 1982, Rules)

Source: `https://www.gov.za/sites/default/files/gcis_document/201409/32298868.pdf`
and `https://savc.org.za/public-infomation/`

The SAVC Code of Conduct contains similar advertising restrictions to the HPCSA:

- Advertising is defined broadly to include any electronic communication directing
  attention to a practitioner's services.
- Advertising must not be misleading, deceptive or unfair.
- No unverifiable claims about superiority or outcomes.
- Client testimonials or endorsements that could be misleading or create unjustified
  expectations of outcomes are generally not permitted.
- Breaches can lead to disciplinary proceedings for unprofessional conduct.

The same risk table as above applies to veterinary profiles. The key additional point
for vets is that "patients" means the animals; "clients" means the owners. Any review
displayed for a vet reflects the client's (owner's) experience, not the animal's
clinical outcome.

### For `.app` (outside South Africa)

Each country has its own professional advertising codes:
- United Kingdom: General Medical Council (GMC) guidance on advertising; similar
  restrictions on testimonials and comparative claims.
- Australia: Australian Health Practitioner Regulation Agency (AHPRA) Guidelines for
  advertising of regulated health services; testimonials from patients are explicitly
  prohibited.
- United States: American Medical Association (AMA) Code of Medical Ethics; some
  states have specific rules.

The overarching risk on .app is reputational: if patients in any country see star
ratings and interpret them as a clinical quality endorsement, the platform could face
complaints or media coverage. The "Verified" badge on .app is Plus, which explicitly
does not check the business; the risk of confusion with professional accreditation is
lower than on co.za.

**Questions for the owner and their lawyer (numbered for easy reference):**

A. Is a third-party review platform that the practitioner did not create still subject
   to Section 25 of the HPCSA Ethical Rules when the reviews are displayed on the
   practitioner's profile page?

B. What disclaimer language on a medical profile would adequately distinguish patient
   reviews from clinical quality endorsements?

C. Is the "Verified" badge tooltip ("Documents checked by WebScheduler Local")
   sufficient to prevent confusion with HPCSA registration on co.za?

D. Does sending an outreach email to an imported practitioner with their impression
   count constitute canvassing under Section 25?

E. For .app: does displaying patient or client reviews on a veterinarian's profile in
   Australia violate AHPRA's explicit prohibition on patient testimonials?

---

## B5. Claim your practice profile (Phase 3c)

### How sign-in works today for imported profiles

The standard sign-in flow: a visitor goes to `/manage`, enters the email on their
profile, and receives a magic link (single-use, 1-hour TTL) that signs them into
`/business`. The `DirectoryListingMutationService::requestManageLink()` and
`redeemManageToken()` handle this.

**The imported doctor flow has two obstacles:**

1. **No email (1,259 of 2,720 profiles).** `requestManageLink()` returns a fixed neutral
   message whether or not an email is found. If a practitioner tries `/manage` with
   the wrong email, they receive nothing. There is no alternative path.

2. **Email not confirmed (1,453 of the 1,461 email-bearing profiles).** The imported
   profiles were published by admin intake, not by email verification. `is_verified = 0`
   on these rows. A manage link still works (it only requires that the email matches a
   published profile, not that `is_verified = 1`), so this is not a blocker.

3. **Shared email.** The Oriental Plaza imports share emails in some cases. For doctors,
   the email was not supplied by the import, so sharing is not expected to be a problem
   here.

4. **No account exists.** Production confirms: 0 of 2,720 medical imports have an
   `xs_accounts` row. When a manage link is redeemed, `OwnerAccess::signInFor()` creates
   the account at that point, so this is handled automatically on first sign-in.

### Production counts (16 Nov 2026)

```sql
SELECT
  SUM(CASE WHEN email IS NOT NULL AND email!='' THEN 1 ELSE 0 END) has_email,
  SUM(CASE WHEN email IS NULL OR email='' THEN 1 ELSE 0 END) no_email,
  SUM(CASE WHEN is_verified=1 THEN 1 ELSE 0 END) email_confirmed,
  SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) published,
  SUM(CASE WHEN phone IS NOT NULL AND phone!='' THEN 1 ELSE 0 END) has_phone,
  SUM(CASE WHEN reg_number IS NOT NULL AND reg_number!='' THEN 1 ELSE 0 END) has_reg_number
FROM xs_directory_listings
WHERE signup_source IN ('import-doctors','import-lenmed') AND deleted_at IS NULL;
```

| Metric | Count |
|---|---|
| Total medical imports | 2720 |
| Published | 2718 |
| Has email | 1461 |
| No email | 1259 |
| Email confirmed (is\_verified = 1) | 8 |
| Has phone | 2716 |
| Has HPCSA reg\_number on listing row | 1 |
| Has an xs\_accounts row | 0 |

**Search Console data for imports (all time to 16 Nov 2026):**

| Source | Profiles with impressions | Total impressions | Total clicks |
|---|---|---|---|
| import-doctors | 1444 | 61 492 | 1 082 |
| import-lenmed | 851 | 23 171 | 268 |

This means over 2,000 medical profiles have been seen by patients in search results
but their practitioners have not claimed them.

### Options for proving ownership

| Method | Reuses existing flow | Abuse risk | Coverage |
|---|---|---|---|
| Email magic link (existing `/manage` flow) | Yes, fully | Low. Only the inbox can redeem it. | 1461 profiles |
| Phone OTP to the practice number | No. New flow needed. | Medium. The phone number is on the public profile; a bad actor could try to intercept. | 2716 profiles (phone is on 99.9% of imports) |
| HPCSA number + admin ID check | Partially (uses `ProfessionalRegistration`). Admin manually approves. | Low. Admin verifies before granting access. | Unknown; only 1 import has a reg\_number on the listing row; HPCSA numbers could come from the original import CSV. |
| Find your name and claim it (self-service verification step) | No. New flow. | High. Any person could claim a profile by guessing details. | Not recommended. |

**Recommended for Phase 3c:**

- **Path A (email, 1461 profiles):** outreach email to the profile's email address.
  Include: practice name, number of times patients found them this month
  (from `xs_directory_listing_search`), a one-click "Claim this profile" link (the
  existing manage link), and an unsubscribe link. No new code beyond a spark command
  that generates and queues the emails.

- **Path B (no email, 1259 profiles):** defer to Phase 3c or later. Add a "Is this your
  practice?" panel on the profile page (similar to the existing "Is this your business?"
  line) with a form to request contact from the admin. Admin then verifies the
  practitioner by phone or by cross-referencing the HPCSA online register
  (`https://hpcsaonline.custhelp.com/app/i_reg_form`) and manually sets the email and
  triggers a manage link.

### Outreach email outline

**Subject:** Your practice is on WebScheduler Local. Claim it.

**Body (for a profile with impressions):**
```
Hi Dr [Name],

Your practice, [Display Name], is live on WebScheduler Local. This month, 
patients searched and found it [N] times.

We imported your profile from public health records to make it easier for 
patients to find you. The profile shows your practice name, address and 
contact details.

Claim it to add your hours, services, photos and team.

[Claim your practice profile] (button, the manage link)

If this is not your practice, ignore this email and nothing will change.

Unsubscribe: [link] 
WebScheduler (Pty) Ltd, South Africa.
```

**For a profile with no impressions yet:**
Same structure, omitting the "patients found you N times" line.

### POPIA basis for emailing imported profiles

Under POPIA section 11(1)(f), processing (including sending email) is lawful if it is
necessary for pursuing the legitimate interests of the responsible party or a third
party, unless those interests are overridden by the data subject's rights. The proposed
email:

- Tells a practitioner that a public profile about them exists and gives them control
  over it.
- Uses the email address published on the HPCSA register (a public source).
- Provides an unsubscribe mechanism.
- Does not market a service to the practitioner; it notifies them about their own
  existing public data.

This is likely a legitimate interest basis. **However, the owner should confirm this
interpretation with legal counsel before sending.** POPIA also requires a PAIA manual
update if a new processing activity is introduced.

### SES daily limits and sending domain

Production sends through the `mail.` subdomain, never the apex. SES daily sending
limits for a new account start at 200/day; a production account in good standing
typically has 50 000/day. With 1461 emails to send, a 50 000/day limit poses no
problem in one batch. If the limit is lower, a spark command with a `--limit` flag
allows batching over multiple days.

The command should:
1. Accept `--dry-run`, `--limit N`, `--source import-doctors|import-lenmed|both`.
2. Skip profiles that already have an account or confirmed email.
3. Record each send in a new column `claim_email_sent_at` on the listing row (or a
   separate log table) so duplicates are avoided on re-runs.
4. Respect the standard `Mailer` path and not bypass it.
5. **Never run without the owner's explicit "go" in the same session.**

### Success measure

A new column in `AdminStats` showing:
- Medical imports with an `xs_accounts` row (claimed, have signed in at least once).
- Medical imports that have been edited (at least one `updated_at` after the import date).
- These split by source (import-doctors, import-lenmed).

The `/admin/stats` page already has a "Profile stats" section. A new row or small table
here, scoped to the import sources, is the right place.

---

## B6. Mobile header button (Phase 1)

### Current header button

In `app/Views/layouts/public.php` lines 221 to 223:

```php
<a href="..." class="btn btn-accent nav-cta">
    <strong class="sm:hidden">Add business</strong>
    <strong class="hidden sm:inline">Add your business</strong>
</a>
```

CSS (from `resources/directory.css` line 168):
```css
.nav-cta { @apply gap-1.5 px-3 py-2 text-xs sm:gap-2 sm:px-5 sm:py-3 sm:text-sm; }
```

The comment in the view (lines 202 to 218) explains the mobile limit: at 360px wide,
"Add business" is the longest string that fits beside the search bar and the menu
toggle. "Add free" was tried before and rendered as "Add free" with a double gap.

### Measurement

At 360px (`sm` breakpoint is 640px):
- Available width: approximately 360px.
- Header has: logo (about 110px), search bar (expands to fill most of the remaining
  width, compressed when focused), nav CTA button, and menu toggle (40px).
- The search bar is `flex-1` in the header flex container. The CTA button uses
  `px-3 py-2 text-xs` on mobile. At `text-xs` (12px), "Add practice" (12 chars) is
  approximately 68px wide, the same as "Add business" (12 chars). Both fit.
- At 390px the same logic applies.

At 640px and above (`sm:` prefix):
- "Add your practice or business" (29 chars) at `text-sm` (14px) is approximately
  200px wide. The existing "Add your business" (17 chars) is about 120px wide. The
  additional 80px takes the button into the search bar's flex space. At desktop
  widths (1024px+) this is comfortable. Between 640px and 768px the header may be
  tighter; this needs a visual check in a browser at 640px before release.

### Recommended strings

| Context | Current | Proposed |
|---|---|---|
| Mobile (below 640px, `sm:hidden`) | `Add business` | `Add practice` |
| Desktop (640px and above, `hidden sm:inline`) | `Add your business` | `Add your practice or business` |

The mobile form "Add practice" (12 chars) is the same character count as "Add business"
and fits at 360px without layout changes. "Add yours" (9 chars) is shorter and also
fits but is less informative. "Add profile" (11 chars) is neutral but loses the
"practice" framing the owner wants.

**Visual check required:** At the 640px breakpoint the desktop string "Add your practice
or business" needs a browser check on both sites before Phase 1 ships. If it wraps or
pushes the layout, options are: shorter text at this breakpoint only via a new
`md:hidden` class, or capping the button at `640–768px` to the mobile string with
`hidden sm:inline md:hidden` and a third `hidden md:inline` for wider screens.

### CLAUDE.md update

The current CLAUDE.md says:
> The header button reads "Add your business".

This line must be updated in Phase 1 to:
> The header button reads "Add your practice or business" (desktop) and "Add practice"
> (mobile, below 640px).

---

## Open questions from the investigation

1. (B1) Is "patients" (medical) or "clients" (vet, Pets and Animals) the right audience
   word for `listing_audience()`, or should vets say "patients" too? See plan Q1.

2. (B2) Can the original import CSV files for the 256 uncategorised rows be located?
   They would contain the specialty that the import command had but did not map. If they
   are on the Lightsail server, the dry-run command could use the specialty column
   directly instead of the title-based fallback.

3. (B3) Are reviews on Beauty and Wellness profiles affected by any aesthetic
   association restrictions (not HPCSA, but possibly cosmetic industry codes)?
   The plan only addresses Health and Medical and Pets and Animals; the one existing
   published review is on a Beauty and Wellness profile and is not in scope.

4. (B4) Legal question A: Is a third-party review platform subject to Section 25 of
   the HPCSA Ethical Rules when it displays reviews on a practitioner's profile page
   without the practitioner's solicitation? This is the most consequential question
   before reviews can be turned back on for Health and Medical.

5. (B5) Should the outreach email be sent before or after claim improvements (Phase 3c)
   are released? Sending before the sign-in flow is improved means practitioners with
   no email address cannot respond. The recommended order is: improve the no-email path
   first, then send.

6. (B5) Has the SES sending limit been confirmed for the production account? The batch
   of 1461 emails is well within a 50 000/day limit but requires confirmation from
   AWS SES console before scheduling.

7. (B6) The 640px visual check: at 640px wide with "Add your practice or business", does
   the header layout break? This needs a browser check before Phase 1 is released. It
   is a blocking check, not an optional one.

---

*End of investigation. No code has been changed.*
