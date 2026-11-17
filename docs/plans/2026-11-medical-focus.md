# Plan: Medical first positioning and "practice" wording

**Prepared:** 16 Nov 2026  
**Revised:** 17 Nov 2026 (round 2 review by Claude, owner decisions 6 to 9)  
**Branch:** `plan/medical-focus`  
**Status:** Owner decisions recorded 16 and 17 Nov 2026. Claude implements phase by phase (owner, 17 Nov 2026).

---

## Owner decisions (16 Nov 2026)

1. **CTAs and nav button:** "Add your practice or business" on both sites (the brand
   site follows). Mobile short form: "Add profile" (decision 6; see B6 investigation).

2. **Medical first means real entry points**, not only wording: a home section such as
   "Find a doctor, dentist or vet", "patients" on medical profiles (via
   `listing_audience()`), and medical aid and after hours shown more prominently.

3. **Reviews off by default for Health and Medical and Pets and Animals** until the
   owner has had legal advice. Data is kept, only hidden. Implemented via
   `Config\Reviews::$offForGroups`. Details in Phase 3b and the investigation (B3).

4. **New phase: claim your practice profile.** Run an outreach campaign to get the
   roughly 2,100 imported medical profiles claimed by their owners. Details in Phase 3c
   and the investigation (B5).

5. **Why, from production Search Console data (Oct to mid Nov 2026):** Health and
   Medical has 81,339 of 98,228 impressions (83%) and 1,294 of 1,545 clicks (84%),
   but only 3 of roughly 2,120 medical profiles were signed up by their owners. The
   roughly 300 self signups are shops, handmade items and services. The patient
   discovery side is medical; the paying side is still mostly small businesses.

### Round 2 (16 and 17 Nov 2026, after Claude's review of b4fb718)

6. **Mobile header button: "Add profile".** "Add practice" would turn away the shops
   that make up most self signups. Desktop stays "Add your practice or business".

7. **Uncategorised doctor imports get a new generic category, "Medical Doctor"**
   (slug `medical-doctor`, group Health and Medical, schema `Physician` with no
   `medicalSpecialty`). Never "Specialist Physician", which is a real specialty
   (internal medicine) and would mislabel GPs on the page and in Google. The source
   CSVs on the server confirm the specialty is blank for these rows (investigation B2).

8. **Reviews on the off groups are hidden, not only closed.** New reviews are refused
   and existing ones are not shown anywhere public (panel, card stars, header stars,
   JSON-LD). Data is kept. Visitors see nothing; the owner panel says why.

9. **Claim outreach: email first, in small batches.** After the lawyer agrees, email the
   1,462 imported medical profiles that have an email (all unique, none shared) at
   about 100 to 200 a day. Build the no email path alongside it, not before it.

10. **Claude builds every phase** (owner, 17 Nov 2026). Copilot did the investigation.

---

## 1. One page summary

### Context

Production data (16 Nov 2026): 2,720 imported medical profiles (1,495 import-doctors,
1,225 import-lenmed), making Health and Medical 2,466 of 2,750 published profiles
(90%). Search Console shows 83% of impressions and 84% of clicks go to Health and
Medical. Health and medical is the dominant real use case. The word "business" appears
roughly 447 times in `app/Views/directory/`, 47 times in `app/Views/emails/`, and
roughly 20 times in customer-visible parts of `app/Views/business/`.

### Recommended option: B

**Choose the word per context**: "practice" in general and marketing copy, and per
profile wording on profile pages, the owner area and emails, via `listing_noun()` and
`listing_audience()` which read `Config\Verticals` (already the source of noun, cta and
panel headings per group).

**Why not A (swap everywhere):**
"Create your free practice profile" is odd for a clothing shop in the Oriental Plaza,
a plumber or a hair salon. A blind swap would alienate non-medical owners.

**Why not C (neutral wording everywhere):**
"Practice or business" is wordy and hedging. The platform already has a clear majority
audience. "Practice" in the main marketing voice is accurate and signals the
positioning without excluding general profiles.

**What B means:**

| Surface | Approach |
|---|---|
| General marketing, home hero, /add-profile headline | "practice or business" as the shared CTA; "practice" in leading copy |
| Profile page, owner area, emails with a listing in context | `listing_noun($listing)` reads `Config\Verticals` |
| Audience word ("customers", "patients") with listing in context | `listing_audience($listing)` reads new `audience` key in `Config\Verticals` |
| Code identifiers, routes, CSS classes, config keys | Unchanged |

---

## 2. The `listing_noun()` and `listing_audience()` helpers

`Config\Verticals` already holds `noun` and `nounPlural` per group, with per-slug
overrides for pharmacy, hospital, veterinarian, attorney and others. It is read by
`vertical_profile()` in `app/Helpers/directory_ui_helper.php`, and `show.php` and
`landing.php` already call it. The plan is to add two thin wrappers and a new
`audience` key; no new mapping config is needed.

### `listing_noun(array $listing, bool $plural = false): string`

Reads `noun` or `nounPlural` from `vertical_profile()` using the aliased keys
`category_group` and `category_slug` that `DirectoryService` selects
(`p.group_name AS category_group`, `p.slug AS category_slug`). On a bare model row
where those aliases are absent, the category is looked up once by `category_id`
(cached per request) rather than falling back blindly to "business".

### `listing_audience(array $listing, bool $plural = false): string`

Reads a new `audience` key from `Config\Verticals`. Proposed defaults:

| Group | `audience` (singular / plural) |
|---|---|
| Health and Medical | patient / patients |
| Pets and Animals | client / clients (see open question 1) |
| All other groups | customer / customers |

The key is added to the `$defaults` array in `Config\Verticals` as
`'audience' => 'customer'` and overridden per group in the same style as `noun`.
"Organisation" for Community and Nonprofit already comes from the existing
`noun` / `nounPlural` entries in Verticals, so no special case in code is needed.

### Tests

Extend `tests/unit/VerticalProfileTest.php` to cover the new `audience` key.
Add `tests/unit/ListingNounTest.php` to cover `listing_noun()` and
`listing_audience()` on rows with and without the aliased keys.

---

## 3. Inventory: "business" occurrences customers see

Sorted by surface. Routes are noted where the slug is visible in the URL bar. Code
identifiers, CSS classes and config keys are excluded per the copy rules.

### 3a. Public layout header and footer (`app/Views/layouts/public.php`)

| Current text | Proposed text | Action |
|---|---|---|
| `Add business` (mobile, nav button) | `Add profile` | change (owner decision 6) |
| `Add your business` (desktop, nav button) | `Add your practice or business` | change (owner decision) |
| `Manage your profile` (nav link) | unchanged | keep |
| `Discover local businesses` (footer nav) | `Discover local practices and businesses` | change |
| `Verified businesses` / `Plus businesses` (footer) | `Verified profiles` / `Plus profiles` | change |
| `Recommend a business` (footer) | `Recommend a practice or business` | change |
| footer tagline co.za: `A discovery platform for South African businesses, services, locations, professionals and opportunities. Create your free business profile.` | `A discovery platform for South African practices, businesses, services, professionals and opportunities. Create your free practice profile.` | change |
| footer tagline .app: same without "South African" | Same change, without "South African" | change |

The `.env`-driven `site_copy('footer', …)` defaults cover both sites; the .app copy
already omits "South African" through `Config\Sites::$appCopy` or `site_copy()`.
No new per-site branching needed for the footer.

### 3b. Home page (`app/Views/directory/home.php`)

| Current text | Proposed text | Action |
|---|---|---|
| `title` meta: `Discover local businesses, services and professionals` | `Discover local practices, businesses and professionals` | change |
| `homeLede` default: `The local discovery platform for businesses, services, professionals and locations across South Africa.` | `The local discovery platform for practices, businesses, professionals and locations across South Africa.` | change |
| `discoverTitle`: `Discover businesses across South Africa.` | `Discover practices and businesses across South Africa.` | change |
| hero search `ariaLabel`: `Discover local businesses` | `Discover local practices and businesses` | change |
| hero search `placeholder` fallback: `A business, service, person or place` | `A practice, business, person or place` | change |
| `A business isn't just a name in a list.` (statement strip) | `A practice isn't just a name in a list.` | **depends**; see open question 10 |
| Featured strip: `aria-label="Featured businesses"` (not visible) | `aria-label="Featured practices and businesses"` | change |
| `Discover every business` (link) | `Discover every practice and business` | change |
| CTA: `Your business should be easy to find.` | `Your practice should be easy to find.` | change |
| CTA: `A free business profile covers your business information …` | `A free practice profile covers your practice information …` | change |
| `Add your business` CTA button (goes to /add-profile) | depends on open question 5 | **depends** |
| JSON-LD `schema_item_list('Featured businesses')` (not visible) | `'Featured practices and businesses'` | change |
| Home page JSON-LD `slogan` from `Config\Directory::$slogan` | unchanged. "Discover local. Be discovered." stays | keep |

The home page also shows rotating words including "business" in the hero statement strip
(`['business', 'services', 'locations', 'people', 'opportunities']`). "practice" is
not in the same linguistic category as these rotating words; leave this strip as is or
add "practice" as the first item (see open question 11).

The hero example placeholders (`$examples`) come from live data (`DirectoryService`).
No code change can update this list; it reflects what is actually in the database.
The examples will naturally shift toward medical as the category grows. Any Phase 3
work that "updates the placeholder list" is therefore not applicable; instead, leave
this to the data.

### 3c. Signup form (`app/Views/directory/form.php`)

| Current text | Proposed text | Action |
|---|---|---|
| Eyebrow: `Your business profile` | `Your practice profile` | change |
| Eyebrow on verified page: `Create your FREE business profile` | `Create your FREE practice profile` | change |
| `title` meta: `Create your free business profile | …` | `Create your free practice profile | …` | change |
| `description` meta: `Create a free business profile on … so local customers can discover your business …` | `Create a free practice profile on … so local customers can discover your practice or business …` | change |
| `Your business details` (section heading) | `Your practice details` | change |
| `Attach your two documents … then tell us about your business.` | `… then tell us about your practice.` | change |
| `Tell us about your business. We'll email you a link …` | `Tell us about your practice or business.` | change |
| `your business profile is free either way.` (plan card small print) | `your practice profile is free either way.` | change |
| `manage your profile` (link, not "business") | unchanged | keep |
| `What does your business do?` (form field label, `_form_fields.php`) | `What does your practice or business do?` | change |
| Form field placeholder: `For example solar installation, dental practice, hair salon` | unchanged | keep |
| `Already added? Manage your profile.` | unchanged | keep |

### 3d. Plan cards (`app/Views/directory/_plan_cards.php`)

| Current text | Proposed text | Action |
|---|---|---|
| Free card header: `A free business profile` (computed) | `A free practice profile` | change |
| Free card footer: `A South African business profile is free either way, and stays free` | `A South African practice profile is free either way, and stays free` | change |
| Verified row note: `your business registration document and your ID` | unchanged. This describes a document check, not a type | keep |
| `All your locations: up to N more branches or practices, each … described to Google as a business location in its own right` | `… described to Google as a practice or business location in its own right` | change |
| `More people discover you: … brings up your business too` | `… brings up your practice too` | change |
| `First to hear about new work: when someone … asks for your kind of service, verified businesses are the first we alert` | `… verified practices are the first we alert` | change |
| GA4 event label `data-track-label="free"` on the CTA: `Create Your FREE Business Profile` | `Create Your FREE Practice Profile` | change |
| GA4 event label `data-track-label="verified"`: `Get Your Business Verified` | `Get Your Practice Verified` | change |

### 3e. Verification pitch (`app/Views/directory/_verification_pitch.php`)

| Current text | Proposed text | Action |
|---|---|---|
| `Why a business would want the Verified Business badge.` (comment, not visible) | (none) | keep (comment) |
| `… someone deciding between you and a business without it` | `… someone deciding between you and a practice without it` | change |
| `your business registration document and your ID.` | unchanged (describes a document) | keep |
| `described to Google as a business location in its own right` | `described to Google as a practice or business location in its own right` | change |
| `a customer who was referred to a person, not a business` | `a customer who was referred to a person, not a practice` | change |
| `up your business too` | `up your practice too` | change |
| `verified businesses are the first we alert` | `verified practices are the first we alert` | change |
| `A South African business profile is free either way, and stays free.` | `A South African practice profile is free either way, and stays free.` | change |

### 3f. Pricing page (`app/Views/directory/pricing.php`)

| Current text | Proposed text | Action |
|---|---|---|
| FAQ: `Is a business profile really free?` | `Is a practice profile really free?` | change |
| FAQ answer: `Yes. A business profile is free, with no monthly fee …` | `Yes. A practice profile is free …` | change |
| FAQ: `Does paying move my business up the discovery results?` | `Does paying move my practice up the discovery results?` | change |
| FAQ answer: `a business pays us or not` | `a practice pays us or not` | change |
| `from your dashboard` link (not "business") | unchanged | keep |
| `and your business profile stays published either way.` | `and your practice profile stays published either way.` | change |
| Tagline below table: `Every business gets a free profile.` | `Every practice gets a free profile.` | change |
| Free profile card heading: `A free business profile` | `A free practice profile` | change |
| CTA: `Add your business` button on pricing page | depends on open question 5 | **depends** |
| Profile setup card: `a complete business profile` | `a complete practice profile` | change |
| Footer link: `More answers … and what Verified means on Verified businesses` | `… on Verified profiles` | change |
| .app: `Plus businesses` in footer link | `Plus profiles` | change |

### 3g. Compare page (`app/Views/directory/compare.php`)

| Current text | Proposed text | Action |
|---|---|---|
| `Free on every free business profile` (chip label) | `Free on every free practice profile` | change |
| `qualifications … does not move a business up the results.` | `… does not move a practice up the results.` | change |
| `Put your business where people can find your people` (heading) | `Put your practice where people can find your people` | change |
| `A South African business profile is free.` | `A South African practice profile is free.` | change |

### 3h. Owner area (`app/Views/business/`)

These pages always have the owner's listing in context. Use `listing_noun($listing)`.

| File | Current text | Action |
|---|---|---|
| `sign_in.php` | `Sign in to your business` | change to `Sign in to your practice` via helper |
| `sign_in.php` | `Your business profile, and your bookings if you take them online.` | change |
| `sign_in.php` | `Use the email on your business profile.` | change |
| `sign_in.php` | `Add your business, free.` (link) | depends on open question 5 |
| `overview.php` | `Your business profile is live …` | change via helper |
| `home.php` | `Your business profile` (section heading) | change via helper |
| `home.php` | `Your name, address and opening hours come from your business profile.` | change via helper |
| `settings.php` | `From your business profile` | change via helper |
| `settings.php` | `edit your business profile` | change via helper |
| `settings.php` | `Customers can no longer book … from your business profile.` | change via helper |
| `stats.php` | `Your stats start once your business profile is live.` | change via helper |
| `profile.php` (comment, not visible) |. | keep |
| `analytics.php` | `By the business` chart series label | keep (data label, not copy |

### 3i. Emails (`app/Views/emails/`)

Emails that have a listing in context use `listing_noun($listing)`.  
Some emails use `$business` as a variable for the listing's display name, not the word
"business". these are kept as is (the name, not the noun).

| File | Current text | Action |
|---|---|---|
| `verify.php` | `Thanks for creating your business profile.` | change → `Thanks for creating your practice profile.` |
| `verify.php` | `Your business profile stays free either way.` | change → `Your practice profile stays free either way.` |
| `verify.php` | `verified businesses are the first we alert` | change → `verified practices are the first we alert` |
| `manage.php` | `Use the button below to edit your business profile.` | change via helper (listing available in context) |
| `holiday-hours.php` | `Customers check whether a business is open before they travel` | change → `a practice or business` |
| `holiday-hours.php` | `when people look for businesses open on the holiday.` | change → `practices and businesses open on the holiday.` |
| `holiday-hours.php` | `your business has a profile on` | change via helper |
| `profile-strength.php` | `email signature and business cards.` | keep (refers to business cards, physical item) |
| `profile-strength.php` | `your business has a profile on` | change via helper |
| `verification-owner.php` | `everywhere your business is discovered on the platform.` | change via helper |
| `verification-owner.php` | `your business is approved.` | change via helper |
| `verification-owner.php` | `your business profile itself is unaffected.` | change via helper |
| `verification-owner.php` | `your business has a profile on` | change via helper |
| `preview.php` | `We've set up the business profile for` | change → `the practice profile for` |
| `preview.php` | `Preview my business profile` | change → `Preview my practice profile` |
| `booking-plan.php` | `your calendar and customers stay in your business area` | keep (refers to the /business area URL) |
| `booking-plan.php` | `Every admin of the business gets this email.` | keep ("the business" here means the account) |
| `booking-password-reset.php` | `your WebScheduler Local business account.` | change → `your WebScheduler Local account.` |
| `booking-payment-account.php` | `Every admin of the business gets this email.` | keep (same as above) |

### 3j. Profile page (`app/Views/directory/show.php` and partials)

| File | Current text | Action |
|---|---|---|
| `_profile_head_parts.php` | `the business's registration document and the owner's ID` | keep (describes an actual document check) |
| `_verification_panel.php` | `everywhere your business is discovered on the platform.` | change via `listing_noun($listing)` |
| `_verification_panel.php` | `your business is approved.` | change via helper |
| `_verification_panel.php` | `Your business profile itself is unaffected.` | change via helper |
| `_plus_panel.php` | `your business.` | change via helper |

### 3k. JSON-LD and meta (`app/Helpers/schema_helper.php`, SEO)

The JSON-LD schema types are already correctly set per category: `Physician`, `Dentist`,
`VeterinaryCare`, `MedicalClinic`, etc. No schema type says "business" in a way that
needs changing. The Organization node uses `LocalBusiness` as a type for most profiles,
which is correct at the schema.org level regardless of our copy word.

### 3l. Legal pages (`app/Views/legal/terms.php`, `legal/app/terms.php`)

| Current text | Proposed text | Action |
|---|---|---|
| co.za: `a local business discovery and visibility platform for South African businesses, services and professionals` | `a local discovery and visibility platform for practices and businesses in South Africa` | change |
| co.za: `before engaging it.` after `any business` | change to `any practice or business` | change |
| .app: same description without "South African" | `a local discovery and visibility platform for practices and businesses` | change |
| Both: `We are not a party to any dealing between you and a business on the site.` | `We are not a party to any dealing between you and a practice or business on the site.` | change |

The terms must be updated under `Legal::LAST_UPDATED` with their current date.

### 3m. Discovery results page (`app/Views/directory/index.php` and partials)

The discovery page renders result cards via `_card.php`. Cards show the listing name
and category, not the word "business". The aria labels and headings ("Discover
businesses across South Africa") change via the same strings as the home page.

The URL `/directory` stays as is. The page title changes from
`Discover local businesses, services and professionals` to
`Discover local practices, businesses and professionals`.

### 3n. Config and platform description (`app/Config/Directory.php`)

| Current text | Proposed text | Action |
|---|---|---|
| `$siteDescription`: `A local business discovery and visibility platform built for South Africa. Discover businesses, services, locations, professionals and opportunities near you, or create a free business profile …` | Replace "business discovery and visibility platform" with "practice and business discovery platform", replace "businesses" → "practices and businesses", "business profile" → "practice profile" | change |
| `$slogan`: `Discover local. Be discovered.` | unchanged | keep |

### 3o. The `/business` URL in the browser bar

The route `/business` is the owner area entry point. It is visible in the URL bar.
The recommendation is to **leave it unchanged**: the route is a code identifier, the
owner has just finished consolidating everything there (10 Nov 2026), and renaming
would require a migration or redirect chain, plus a full re-test of the app shell. The
owner area is not a customer-discovery surface; owners type the email address they
used when signing up, not a URL they remember.

### 3p. GA4 and Clarity event labels

The `data-track` and `data-track-label` attributes in the pricing CTAs (`_pricing_compare.php`,
`_plus_compare.php`) send events on click. These are internal analytics, but the label
strings "Create Your FREE Business Profile" and "Get Your Business Verified" should
change to match the new copy (they appear as event labels in GA4 reports). The event
names (`pricing_cta`, `checkout_cycle`) stay unchanged. they are identifiers.

---

## 4. Product and brand names

| Name | Current | Recommended | Rationale |
|---|---|---|---|
| Free tier | "Free Business Profile", "a free business profile" | "Free Practice Profile" | Aligns with medical-first positioning |
| Paid upgrade (co.za) | "Verified Business" / "Verified" | **"Verified"** only | "Verified Business" is already renamed to "Verified" in customer copy. Remaining occurrences are admin text and code comments; these stay. No customer-visible work needed here. |
| Paid upgrade (.app) | "Plus" | unchanged | Copy rules already say "Plus"; keep |
| Profile setup service | "We set up your business profile for you" | "We set up your practice profile for you" | change |
| "Add your business" button | "Add your business" | "Add your practice or business" (desktop); "Add profile" (mobile) | Owner decision 16 Nov 2026. Update CLAUDE.md at the same time. |
| Platform description | "a local business discovery and visibility platform" | "a local discovery and visibility platform for practices and businesses" | Removes "business discovery" framing; accurate for all profile types |
| Tagline | "Discover local. Be discovered." | unchanged | Clean and category-neutral; leave it |
| "Verified Business badge" (formal name in billing) | "Verified Business badge" | "Verified badge" | Drop "Business". Payments run through Paystack only. Renaming the item name on future charges is harmless (Paystack does not match renewal on item name). Existing rows keep the old name on their statements until they re-subscribe. Owner to confirm before Claude changes it. |

**Note on Paystack:** All new payments and renewals go through Paystack. The PayFast
integration handles existing subscriptions that were activated before the switch only.
No PayFast code or config needs to change as part of this plan.

---

## 5. Medical first: beyond the words

### 5a. Home page

**Hero lede:** Currently `The local discovery platform for businesses, services,
professionals and locations across South Africa.` Proposed: `The local discovery
platform for practices, businesses, professionals and locations across South Africa.`

**Medical aid and after hours:** `medical_aid_accepted` already exists in
`Config\ListingAttributes` for the Health and Medical group. After hours is handled by
the existing public holiday hours feature. What the owner wants is these shown more
prominently on medical profiles, for example near the contact panel or in a dedicated
"For patients" section. This is a Phase 3 layout change, not a data change.

**Category tiles:** `header_quick_categories()` shows the eight most-listed categories.
Health and Medical is already the largest group by profile count. If it does not appear
first, the tile query can be explicitly ordered to put it first without changing
discovery results (tiles are editorial, not part of `browse()`).

**Hero example placeholders:** Come from live data (`DirectoryService`). No code change
can update this list. The examples shift toward medical as the category grows.

**Featured strip:** The `is_featured` flag is admin-only and independent of payment.
Admin can prioritise medical profiles in the featured strip without any code change.

**Pricing section:** Moving from "business" to "practice" framing is the Phase 1 work.
The pricing section shares `_pricing_compare.php` and `_plus_compare.php` with
`/pricing`, so changes there cover both.

### 5b. Discover dropdown (search suggest)

`/directory/suggest` returns categories via the discovery model. Category labels (for
example "General Practitioner", "Dentist") are already correct and show in the dropdown.

The discovery model learns from actual searches over time. No code change is needed to
make it suggest more medical categories; the data will weight them naturally once
medical profiles and searches dominate.

The `Config\Search::$categorySets` already has a `doctors` set covering every
Physician. A `vets` set could be added the same way, mapping to `VeterinaryCare` (one
category currently: "Veterinarian").

### 5c. Category ordering (`DirectoryCategoriesSeeder`, `Config\ListingFacets`)

The seeder enumerates all groups. The first group in the array is "Health and Medical".
This controls sort order in the category picker and in `/directory/categories`. It is
already first. No change needed unless the owner wants a different group second.

### 5d. Province and country pages

Category and province landing pages already exist (`app/Views/directory/landing.php`).
They show practices in a region using the same `browse()` query and `_card.php`
partial. These pages show
the same `_card.php` partial. The word "business" in page titles and headings will
change via the same layout strings as the home and results page.

### 5e. Profile page sections for health professionals

The profile page already renders:
- Professional registration number and regulator link (HPCSA, SAPC, SANC, AHPCSA,
  SACSSP) for every category in `Health & Medical`.
- Team members with their own registration numbers (migration
  `2026-11-10-100000_AddProfessionalRegistrationToTeam.php`).
- Schema.org `Physician`, `Dentist`, `Optician`, `Physiotherapy`, `MedicalClinic`,
  etc. per category.

Nothing here is broken. The profile page already works correctly for health
professionals.

### 5f. Signup form profile type

The form has a "Business / practice" type selector that includes `practice` as a type.
The placeholder example for "What does your practice or business do?" already says
"dental practice". No structural change to the form is needed for medical-first
positioning; the copy changes in Phase 1 are enough.

### 5g. Description templates (`Config\DescriptionTemplates`)

The description template families are:
- `business` (used by `person`, `practice`, `facility`). generic but workable for health
- `place` (venues)
- `cause` (NGO types)

There is **no medical-specific description template family** for doctors or dentists.
The generic "business" family produces sentences like `{name} is a General Practitioner
in {place}. We offer {list}.` which is acceptable but not tailored.

A `health` or `medical` template family could be added in Phase 3 with more natural
phrasing for health professionals: `Dr {name} is a General Practitioner in {place}
and sees patients by appointment.` This would need the owner's input on the exact phrasing.

### 5h. Schema types (`Config\SchemaTypes`)

Already comprehensive for doctors. Every specialist slug maps to `Physician`.
`MedicalClinic`, `Dentist`, `Optician`, `Physiotherapy`, and `VeterinaryCare` are all
mapped. The `$medicalSpecialty` table adds specialties to the right schema types.

No change is needed in Phase 1. Phase 3 (vet SAVC, any new vet categories) may add
entries here.

---

## 6. Veterinary coverage

### What exists

| Feature | Status |
|---|---|
| Category: "Veterinarian" under "Pets & Animals" | ✓ exists |
| Schema type: `VeterinaryCare` for `veterinarian` slug | ✓ exists |
| Search synonyms: `vet` / `vets` / `veterinary` → `veterinarian` | ✓ exists |
| Attributes: home\_visits, emergency\_care, boarding, grooming, exotic\_animals | ✓ exists |
| Description template family specific to vets | ✗ missing |
| SAVC (South African Veterinary Council) in `Config\Registrations` | ✗ **gap** |
| SAVC registration field on the profile form | ✗ **gap** (follows from above) |
| SAVC registration link on public profile page | ✗ **gap** |
| Vet specialties as separate categories (equine vet, wildlife vet) | ✗ missing |

### The SAVC gap

`Config\Registrations` has HPCSA, SANC, SAPC, AHPCSA, SACSSP, LPC, SACAP, ECSA.
Veterinarians registered under SAVC are entirely absent. The form shows no registration
box for a vet, and the profile shows no registration link.

**SAVC is co.za only.** `Config\Registrations` and `ProfessionalRegistration::regulatorFor()`
do not currently accept a site parameter. The SAVC field must only show on co.za; on
.app a veterinarian profile gets no registration field until a multi-jurisdiction
approach is designed. `regulatorFor()` needs a site parameter (or a check inside the
function) so it returns null for the `veterinarian` slug when the site is .app.

Adding SAVC on co.za requires:
1. Add `savc` to `Config\Registrations::$regulators` with its pattern, example, register
   URL (`https://www.savc.org.za/`) and research flag.
2. Add `'veterinarian' => 'savc'` to `$bySlug`.

This is a data-only change in one config file, no migration. It is small enough to
include in Phase 1. The form will render the SAVC field automatically for vets through
`ProfessionalRegistration::regulatorFor()`.

**Open question 1:** Is "SAVC" (South African Veterinary Council) the right regulator
label? On .app the same field would show for vets in other countries (RCVS in the UK,
AVBC in Australia, SAAVMA is not statutory). The field could show "Veterinary Council
registration number" generically, or only on co.za. See open question 1 below.

---

## 7. Health practitioner advertising rules

**This section lists risks for the owner's attention. It is not legal advice.**

### HPCSA Ethical Rules (South Africa, co.za)

The Health Professions Act 56 of 1974 and the HPCSA's Ethical Rules of Conduct
(the rules on advertising, canvassing and touting; exact rule numbers to confirm from the booklet) restrict how registered health practitioners may
advertise. The platform is not the practitioner, but it is the medium.

| Feature | Potential issue | Risk level | Question for the owner |
|---|---|---|---|
| **Customer reviews** | The HPCSA restricts testimonials from patients in advertising material if they are used to attract clients. Third-party reviews on a platform are a grey area: the platform does not solicit them for the practitioner, but displaying them on a profile page could be construed as the practitioner using them. | **High** | Should medical practitioner profiles carry a note that reviews reflect individual patient experiences and do not constitute an endorsement of clinical quality? Should practitioners be able to opt their profiles out of reviews? |
| **"Verified" badge** | The HPCSA registers practitioners; a "Verified" badge that checks business registration documents and ID could be confused with HPCSA registration or accreditation. The badge tooltip already says "Documents checked by WebScheduler Local" but this may not be prominent enough for a patient who assumes it means clinical verification. | Medium | Should medical profiles carry a disclaimer that "Verified" refers to business registration, not HPCSA registration, and that HPCSA registration is a separate matter? |
| **"Featured" placement** | A featured label or strip implies a practitioner is highlighted. If a patient interprets "featured" as "the best GP", this may conflict with the rule against claiming superiority. The admin controls featured placement and it is currently independent of payment. | Low | Is a "featured" label appropriate on health practitioner profiles, or should it be replaced with a neutral "sponsored" label for medical profiles? |
| **Compare page (`/compare`)** | The compare page compares WebScheduler Local against other platforms (Google, LinkedIn), not practitioners against each other. No practitioner-to-practitioner comparison exists. | Low | No action required unless a future "compare profiles" feature is considered. |
| **Profile copy about specialties** | Schema.org `medicalSpecialty` is emitted for each doctor's category (e.g., `Neurology`, `Cardiology`). This is a factual statement about the category, not a clinical claim. | Low | No action required. |
| **"First to hear about new work" (Jobs alerts)** | Lead alerts for service requests are sent to businesses in the same category and province. Sending "new patient request" alerts to GPs resembles solicitation referral services that some professional bodies restrict. | Low-Medium | Is the Jobs board appropriate for health practitioners? The Jobs board is for service requests, not patient referrals. Should medical categories be excluded from service request alerts, or is there a distinction that keeps it clean (e.g., "equipment" or "admin" requests, not "patient" requests)? |

### SAVC (Veterinary Council, South Africa, co.za)

The South African Veterinary Council's Code of Conduct contains similar advertising
restrictions to HPCSA. The same review, verification badge and featured placement
questions apply.

### For `.app` (outside South Africa)

Each country has its own professional advertising rules. The UK, Australia, US and
others all have medical advertising codes through their professional bodies (GMC, AHPRA,
AMA). The risks are:

| Issue | Question for the owner |
|---|---|
| "Verified" and clinical verification | Same risk as co.za: the badge might imply GMC or AHPRA registration in the UK or Australia. Worth a disclaimer on medical profiles globally? |
| Country-specific review rules (e.g., NHS guidance in the UK) | Should patient reviews for practitioners in regulated jurisdictions carry a standard disclaimer? |
| No HPCSA equivalent enforcement outside SA | The risk is reputational, not statutory for the platform. Worth noting in the terms that the platform does not vet qualifications in any jurisdiction. The terms already say this ("We do not vet qualifications"). |

---

## 8. Both sites: per-site strings that change

The two sites share all the views above. Changes to `site_copy()` default arguments
automatically cover both sites. The .app copy already omits "South African" through
`$appCopy` entries. The following strings need explicit per-site attention:

| String | co.za | .app |
|---|---|---|
| `homeLede` | `The local discovery platform for practices, businesses, services, professionals and locations across South Africa.` | .app `$appCopy` already overrides this to drop "South Africa"; update the .app override too |
| `discoverTitle` | `Discover practices and businesses across South Africa.` | .app override: `Discover practices and businesses near you.` |
| Footer tagline | "Create your free practice profile." | .app: same, no "South African" in front |
| `homeDescription` default (meta) | Add "practices" before "businesses" | Same on .app |
| Verified pitch | "A South African practice profile is free" | .app does not show the Verified pitch; Plus pitch uses different copy already |
| "No rand" rule | No rand figures in .app copy | No new rand figures introduced by this plan |

Before releasing Phase 1, render the home page on both `localhost:8095` (co.za) and
`app.localhost:8095` (.app) and confirm:
- .app does not say "South Africa", "South African" or "rand".
- co.za says "built for South Africa" or "South African" where it currently does.

---

## 9. SEO risk and mitigations

### What could lose rankings

| Area | Current state | Change | Risk |
|---|---|---|---|
| URLs | All URLs are unchanged | No change | None |
| Page titles | `Discover local businesses …` | `Discover local practices and businesses …` | Low: "businesses" stays in the title; practices is an addition |
| H1s | Usually match titles | Will change similarly | Low |
| Meta descriptions | Mention "business profile" | Will say "practice profile" | Low-medium: some existing query traffic for "free business profile" may be affected |
| JSON-LD schema | Physician, Dentist, etc. already set | No change | None |
| Sitemap `lastmod` for profiles | Auto-generated from listing `updated_at` | No change to mechanism | None |
| Sitemap `lastmod` for fixed pages (home, /pricing, /compare, etc.) | Read from `view-lastmod.json` (each file's last commit date, built at deploy time) | These dates will update automatically when their view files change in Phase 1 | None |

### Key queries at risk

Queries that currently drive traffic to co.za for "business profile" or "free business
profile south africa" could be affected. The recommendation is:

1. Keep "business" alongside "practice" in meta descriptions during Phase 1 (e.g.,
   "Create a free practice profile. South Africa's local discovery platform for
   businesses and professionals") rather than a hard swap.
2. Do not change any URL or slug.
3. After Phase 1 release, monitor Search Console for 7 to 14 days for impressions
   drops on "business profile" queries.
4. If impressions drop more than 20%, add "business profile" back to the description
   as a secondary phrase.

---

## 10. Brand site (`niloc95/webscheduler-site`) and docs

### Brand site changes (not in this repo. list only)

The brand site at `webscheduler.co.za` reads `/pricing.json` from the Local app. The
JSON payload does not contain the word "business"; it contains prices and display
strings. The `/pricing.json` endpoint itself needs no changes.

The brand site source (`site/pages/local.html`) contains these "business" strings that
would need changing in that repo when Phase 1 is released:

| Location | Current | Proposed |
|---|---|---|
| `<title>` meta | `The local business discovery and visibility platform` | `The local practice and business discovery platform` |
| `description` meta | `gives every business a free business profile` | `gives every practice a free practice profile` |
| JSON-LD page description | same as meta description | same change |
| Hero heading | `Where customers discover local businesses` | `Where customers discover local practices and businesses` |
| Body copy | `Local is a business discovery and visibility platform. Customers discover businesses … Businesses get a free business profile` | Update throughout |
| CTA button | `Add your business` | depends on open question 5 |
| Bookings caption | `Booking on Fernwood Dental Studio, our demo practice.` | already says "practice" ✓ |

This is a list only. Changes to `niloc95/webscheduler-site` are out of scope for this plan.

### Internal docs to update (all in this repo)

| Document | What changes |
|---|---|
| `CLAUDE.md` copy rules | "Prefer 'business profile' over 'listing'" → "Prefer 'practice profile' or 'practice or business profile'"; update "header button reads 'Add your business'" if open question 5 resolves to change it |
| `.claude/skills/listing-service/SKILL.md` | Update "Copy keywords" section to add "practice" |
| `.claude/skills/two-sites/SKILL.md` | Update copy rules section |
| `app/Config/Handbook.php` and `$tables` | No table changes in this plan |
| `app/Views/admin/handbook/changelog.php` | One dated line per phase |
| `docs/bookings/copy.md` | Add "practice profile" and "practice" as the medical-first framing |
| PDFs in `Config\Documents` | Playbook, proposal, profile guide, partner guide. all use "business profile". Update when PDFs are rebuilt in Phase 4. |

---

## 11. Tests that assert current copy (will break)

These tests check the exact strings being changed. Each must be updated in the same
commit as the view or config it tests.

| Test file | Assertion | Phase |
|---|---|---|
| `PricingPageTest` | `assertContains('Free Business Profile', …)` | 1 |
| `PricingPageTest` | `assertContains('A free business profile', …)` | 1 |
| `SignupModesTest` | `assertContains('Create your FREE business profile', …)` | 1 |
| `SignupModesTest` | `assertContains('Add your business', …)` | 1 (or keep if open question 5 is keep) |
| `ReferralFlowTest` | `assertContains('Your business profile stays free either way.', …)` | 1 |
| `ProfileSetupTest` | `assertContains('We set up your business profile for you', …)` | 1 |
| `CategoryMatchTest` | `assertContains('What does your business do?', …)` | 1 |
| `FormHealthServiceTest` | `assertContains('Add your business', …)` | 1 (or keep) |
| `ProfilePreviewTest` | `assertContains('private preview of your business profile', …)` | 2 |
| `ProfilePreviewTest` | `assertContains('Preview my business profile', …)` | 2 |
| `PagerRenderTest` | `assertContains('business profiles', …)` | 1 |
| `SiteScopeTest` | `assertContains('Verified businesses', …)` | 1 |
| `VerifiedBadgeExplainerTest` | asserts "Verified Business" label | 1 (if badge name changes) |
| `VerificationFlowTest` | "Verified Business" references | 1 (same) |
| `YearlyBillingTest` | `assertStartsWith('Yearly Verified Business badge for', …)` | 1 |
| `BookingPublicTest` | `assertSame('This is a demo business profile …', …)` | 2 |
| `BookingsInPremiumCopyTest` | "Verified Business badge" | 1 |

### New tests needed

| Test | Phase |
|---|---|
| `PricingPageTest`: main public pages say "practice profile", not "business profile" | 1 |
| `ListingNounTest`: a row with `category_group` 'Health & Medical' gives 'practice' and audience 'patients' | 2 |
| `ListingNounTest`: a bare row with only `category_id` (medical) still gives 'practice' | 2 |
| `ListingNounTest`: `category_slug` 'pharmacy' gives 'pharmacy'; no category gives 'business' | 2 |
| `VerticalProfileTest`: every seeded group returns an `audience` | 2 |
| `SiteScopeTest`: no raw "business profile" on the home page of either site (only in medical context) | 1 |
| `ProfessionalRegistrationTest`: `regulatorFor('veterinarian', 'Pets & Animals')` returns SAVC | 1 |
| `DescriptionDraftServiceTest`: medical template family produces different text from generic business | 3 |

---

## 12. Phased plan

### Phase 1: Wording and main public pages

**Branch:** `feat/practice-wording-p1`  
**Estimated size:** Medium (2 to 3 days)  
**What changes:** New helpers, SAVC for co.za, copy in public views and emails.

Changes:
- Add `listing_noun()` and `listing_audience()` to `app/Helpers/directory_ui_helper.php`.
- Add `audience` key to `Config\Verticals` defaults and group overrides.
- Add SAVC to `app/Config/Registrations.php` (small, safe, no migration, co.za only).
  Add a site parameter check to `regulatorFor()` to suppress the field on .app.
- Update `app/Views/layouts/public.php`: header nav (mobile "Add profile", desktop
  "Add your practice or business"), footer copy, Verified profiles, Plus profiles.
- Update `app/Views/directory/home.php`: lede, title, aria labels.
- Update `app/Views/directory/form.php` and `_form_fields.php`: signup headings, labels.
- Update `app/Views/directory/_plan_cards.php` and `_pricing_compare.php`:
  plan card copy, GA4 event labels.
- Update `app/Views/directory/_verification_pitch.php` and `_plus_compare.php`.
- Update `app/Views/directory/pricing.php`.
- Update `app/Views/directory/compare.php`.
- Update `app/Config/Directory.php`: `$siteDescription`.
- Update CLAUDE.md copy rules (button string and "business profile" preference).
- Update all impacted tests. Add `ListingNounTest`. Extend `VerticalProfileTest`.
- Handbook changelog line.

**Check both sites before releasing.** The .app `$appCopy` overrides for `homeLede`,
`discoverTitle`, `footer` and `homeDescription` must be updated alongside the defaults.

**Risk:** Very low. No routes change. No migration. Rollback is reverting the view files.

---

### Phase 2: Owner area, emails, Bookings plus listing helpers

**Branch:** `feat/practice-wording-p2`  
**Estimated size:** Small to medium (1 to 2 days)  
**What changes:** Per-profile views use `listing_noun()` and `listing_audience()`.

Changes:
- Update `app/Views/business/sign_in.php`, `overview.php`, `home.php`, `settings.php`,
  `stats.php`.
- Update `app/Views/emails/verify.php`, `manage.php`, `holiday-hours.php`,
  `profile-strength.php`, `verification-owner.php`, `preview.php`,
  `booking-password-reset.php`.
- Update Bookings email templates that say "business" where the practice word applies,
  using `listing_noun()` and `listing_audience()` with the listing in context.
- Update `docs/bookings/copy.md` to reflect audience word.
- Add the generic category "Medical Doctor" (`medical-doctor`, Health and Medical,
  schema `Physician` without `medicalSpecialty`) in `DirectoryCategoriesSeeder`,
  `Config\SchemaTypes` and anything a category test needs (decision 7).
- A one off command, `directory:recategorise-imports`, with `--dry-run` and a CSV
  report: "Dr" persons with no category go to `medical-doctor`; Lenmed allied
  disciplines go to an existing category where one fits, else admin review; units
  ("Dialysis Unit" and similar) go to admin review. Writes `category_id` only, and
  runs on production only with the owner's OK.
- Update profile page partials: `_verification_panel.php`, `_plus_panel.php`.
- Update impacted tests.
- Handbook changelog line.

**Risk:** Low. Owner area pages always have the listing in context. If `category_group`
is absent, the helper looks up the category by id before falling back to "business".

---

### Phase 3: Medical first home and Discover

**Branch:** `feat/medical-first-home`  
**Estimated size:** Medium (2 to 3 days)  
**What changes:** Structural and visual changes on the home page and discovery.

Changes:
- Add a "Find a doctor, dentist or vet" section or equivalent to the home page.
- Surface medical aid acceptance and after hours more prominently on medical profiles
  (a "For patients" subsection near the contact panel).
- Explicitly order the category tiles to put Health and Medical first in
  `header_quick_categories()`.
- Add a `vets` category set to `Config\Search::$categorySets` mapping to `VeterinaryCare`.
- Review and update `Config\DescriptionTemplates` to add a `health` template family
  with more natural phrasing for practitioners (owner to approve the phrasing).
- Update impacted tests.
- Handbook changelog line.

**Ask before ranking:** Any change that moves medical profiles higher in discovery
results (not just editorial tile order) needs the owner's explicit sign off. Category
tile ordering is editorial UI, not ranking. Payment never affects ranking.

**Risk:** Low for tile reordering. Medium for description templates.

---

### Phase 3b: Reviews off for Health and Medical and Pets and Animals

**Branch:** `feat/medical-reviews-off`  
**Estimated size:** Small (less than 1 day)  
**What changes:** New `$offForGroups` in `Config\Reviews`, checked in `acceptsReviews()`.

Changes:
- Add `Config\Reviews::$offForGroups = ['Health & Medical', 'Pets & Animals']`.
- Update `ReviewService::acceptsReviews()` to check the group first: an off group
  refuses new reviews whatever `reviews_enabled` says. Turning a group back on is a
  config change after legal advice, not a per practice switch.
- **Hide, not only close** (decision 8): for an off group the profile panel, result
  card stars, header stars and JSON-LD (`aggregateRating`, `review`) show nothing,
  even if published reviews exist. Data stays in the database. One helper decides
  this (for example `reviews_visible($listing)`) so no surface is missed.
- Visitors see no reviews section and no "not available" line. The owner panel shows
  one line: reviews are off for medical profiles for now, and has no switch.
- Update impacted tests.
- Handbook changelog line.

**Production data:** 2466 Health and Medical profiles published. 1 has reviews\_enabled = 0
(already off). 0 have any published reviews. Only 1 published review exists in the
whole DB, on a Beauty and Wellness profile. Impact is minimal.

**Risk:** Very low. No data is deleted. The flag can be removed if legal advice says
reviews are fine.

---

### Phase 3c: Claim your practice profile

**Branch:** `feat/claim-practice-profile`  
**Estimated size:** Medium to large (3 to 5 days, includes outreach tooling)  
**What changes:** Outreach email, improved no-email sign-in flow for imports.

See the investigation (`docs/plans/2026-11-medical-focus-investigation.md`, B5) for the
full analysis. High level:
- Build an admin-triggered bulk outreach for the 1,461 imported medical profiles that
  have an email address.
- The outreach email body includes: how many times their profile appeared in Google
  results this month (impressions from `xs_directory_listing_search`; never "patients
  found you"), where the details came from (public practice and hospital listings,
  such as lenmed.co.za for the Lenmed group), a link to claim it (the existing manage
  link flow), and an unsubscribe link.
- **Email first** (decision 9): send in batches of 100 to 200 a day from the `mail.`
  subdomain, whatever the SES quota allows. The owner confirms the quota
  (`aws ses get-send-quota`) before the first batch.
- A send log so no one is emailed twice: a migration (a column or a small log table),
  handbook update and changelog line.
- For the 1,259 profiles with no email: a separate admin flow (phone OTP or manual HPCSA
  verification) scoped to co.za only, built alongside the email batches.
- **Any bulk send needs the owner's explicit OK** including the POPIA basis review, the
  SES limit check, and the sending domain sign-off.
- Handbook changelog line and a dated POPIA basis note in `legal/privacy.php`.

**Risk:** Medium. Bulk email to imported profiles who did not opt in. Needs POPIA review
before sending. Rollback: do not send the next batch.

---

### Phase 4: Legal pages, PDFs, brand site notes and handbook

**Branch:** `feat/practice-wording-p4`  
**Estimated size:** Small (1 day)  
**What changes:** Legal and documentation alignment.

Changes:
- Update `app/Views/legal/terms.php` and `legal/app/terms.php` (bump `LAST_UPDATED`
  dates; use "a local discovery and visibility platform for practices and businesses").
- Update `app/Views/legal/privacy.php` if any "business profile" references exist.
- Rebuild and republish PDFs that use "business" in customer-facing copy (playbook,
  profile guide, proposal). The rebuild commands are in `Config\Documents::$published`
  entries under `builder`.
- Update the skill files.
- Update `app/Views/admin/handbook/changelog.php`.
- Write the changes needed in `niloc95/webscheduler-site` as a note for the owner
  (not implementing here).
- Owner to confirm the Paystack item name decision (Section 4).

**Risk:** Low for legal copy (descriptive changes, no structural change to rules).
Medium for PDFs (require manual rebuild and file replacement).

---

## 13. Risks and rollback

| Risk | Phase | Mitigation | Rollback |
|---|---|---|---|
| "practice" reads wrong for a hardware shop | 1 | `listing_noun()` returns "business" for non-medical groups; general copy says "practice or business" | Revert view files; no migration, no data change |
| SEO drop on "business profile" queries | 1 | Keep "business" alongside "practice" in meta descriptions; monitor Search Console for 2 weeks | Add "business profile" back to meta description copy |
| .app shows "South African" copy by accident | 1 | Test on `app.localhost` before release; diff `site_copy()` calls | Revert; fix the `$appCopy` override |
| "Verified" rename breaks existing payment item references | 1 | Leave PayFast/Paystack item name unchanged until owner decides | Do not change payment item names in Phase 1 |
| Legal update bumps `LAST_UPDATED` date and requires a terms re-accept flow | 4 | The terms changes are descriptive only (adding "practices and"), not substantive changes to users' rights; the owner should confirm whether a re-accept is required | Revert terms change; ship legal update separately |
| HPCSA compliance issue surfaces after launch | Any | The risks in Section 7 are flagged for the owner's decision before Phase 1 ships | Individual features (reviews, compare, featured) can be gated per category group |

---

## 14. Open questions for the owner

These need an answer before the corresponding phase can start. They are numbered for
easy reference. Questions 1 to 4 from the first draft (vets noun, pharmacies, legal,
hospitals) are answered: `Config\Verticals` already has per-slug overrides for
pharmacy (noun: "pharmacy"), hospital (noun: "hospital"), veterinarian, and attorney.
The plan reads those; no new mapping is needed.

1. **Vets: "patients" or "clients"?** `listing_audience()` proposes "clients" for Pets
   and Animals. Some vets say "patients" for the animals. Which audience word do you
   want for veterinarian profiles?

2. **Reviews off group list: Pets and Animals only?** The plan turns reviews off for
   both Health and Medical and Pets and Animals pending legal advice. Should Pets and
   Animals be on the initial list, or treated separately?

3. **Reviews: when does the hold end?** Once legal advice arrives, reviews may be turned
   back on. Should this be a per-profile owner switch (the existing `reviews_enabled`
   column already supports this) or a group-level switch that an admin can flip?

4. **"Verified" badge clarity for patients.** Should medical profiles carry additional
   copy clarifying that "Verified" refers to business registration, not HPCSA
   registration? A line such as "Verified means we checked their business registration
   documents, not their HPCSA registration" on the profile page or tooltip.

5. **Jobs board service request alerts for medical profiles.** The alerts send lead
   notifications to practitioners when a service request matches their category and
   province. Is this appropriate for health practitioners, or should the Health and
   Medical group be excluded from service request alerts?

6. **Home hero rotating words.** The strip cycles through "business", "services",
   "locations", "people", "opportunities". Should "practice" replace "business", be
   added before it, or should the strip stay as is?

7. **"A business isn't just a name in a list."** The home page statement. Should it
   become "A practice isn't just a name in a list."?

8. **Description template family for health professionals.** Phase 3 proposes a new
   `health` template family in `Config\DescriptionTemplates` with more natural phrasing
   for practitioners. Should Claude draft strings for review, or will the owner provide
   them?

9. **Category tiles: data-driven or pinned?** Currently the most-listed categories
   appear first. Should Health and Medical tiles be explicitly pinned first, or is the
   data-driven order acceptable now that it already leads?

10. **Claim outreach: POPIA basis.** The outreach plan sends email to 1,461 imported
    profiles. Have you confirmed the POPIA legitimate interest basis for this email
    (imported from public practice and hospital listings, purpose is to notify
    practitioners about their existing public profile)? This is required before any bulk send.

11. **Claim outreach: profiles with no email.** 1,259 of the 2,720 imported profiles
    have no email address. What is the preferred path for these: (a) phone OTP to the
    practice number on the profile, (b) manual HPCSA number plus ID check by admin, (c)
    leave unclaimed until a practitioner finds their profile naturally, (d) a combination?
    The email batches go first either way (decision 9).

12. **Paystack item name for the Verified badge.** The formal payment item name is
    currently "Verified Business badge". Should it become "Verified badge" to match the
    renamed product? Existing subscribers will keep the old name on their statements
    until they re-subscribe. Confirm before Claude changes it.

13. **Lenmed units.** 8 uncategorised Lenmed rows are units such as "Dialysis Unit",
    not practitioners. Should they stay on the platform, become a facility category,
    or be unpublished? Admin decides.

---

*End of plan. Owner decisions recorded 16 Nov 2026. Claude implements phase by phase
on the owner's go ahead for each phase.*
