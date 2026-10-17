# Audit 1 of 3: Conversion and Frontend

WebScheduler Local (https://local.webscheduler.co.za), audited 17 October 2026 against `main` at `f3d4a5a`.
Report only: no code, data or configuration was changed. The production figures come from read-only `SELECT`s
run over SSH on 17 Oct 2026. Live pages were fetched with GET only, and no form was submitted on production.

## 1. Summary

The single largest problem is that **262 of the 301 live profiles (87%) were imported by us with no email
address, and their owners have no way to claim them**. "Is this your business?" leads to `/manage`, which can
only sign in an address the profile already holds. Organic signups are 13 in August, 22 in September and 4 so
far in October, against a goal of about 2,200 more profiles by December. The free form adds friction: about 17
compulsory items, three of them checked only after submit, and several failure paths that throw away
everything typed, some of them behind a fake "check your email" success. On the paid side, the funnel is small
but leaking at the last step: 2 of 6 applications were approved 59 and 18 days ago and never paid, and nothing
reminds them. The biggest opportunities are a claim flow for imported profiles, a payment reminder for approved
applications, and a shorter free form that saves a draft.

## 2. Known issues

| # | Issue | Verdict | Evidence |
|---|---|---|---|
| 1 | Honeypot fields | **Confirmed, cause differs from the brief** | "Fill This Field" is not in any view. CodeIgniter's Honeypot filter injects it into **every** rendered form, GET search forms included: `app/Config/Honeypot.php:17,22,27` (label, name `honeypot`, template), applied site-wide by `app/Config/Filters.php:159`. Live `/` carries `<div id="hpc">` three times (header search, hero search, footer newsletter), so the id is duplicated. The input has no `aria-hidden`, no `tabindex="-1"` and no `autocomplete="off"`. It is hidden by a nonced `#hpc { display:none }`, which does keep it out of the accessibility tree and out of autofill. A filled field is answered with a bare-text **403 "Forbidden"** (`app/Filters/BotTrap.php:37-43`), POST only (`vendor/.../Honeypot.php:67`), so search is unaffected. "Leave this field empty" is the Mautic footer trap (`app/Views/layouts/public.php:316-322`): `class="hidden"`, `aria-hidden`, `tabindex="-1"` and `autocomplete="off"`, so it is correct. **The bigger risk is the signup form's second trap**, `company_website_hp` (`app/Views/directory/form.php:91`). It is hidden by being moved off screen at 0×0 (`resources/directory.css:3294`, `.hp`), not by `display:none`, and it is labelled "Company website", which is exactly the kind of field browser and password manager autofill targets. If it is ever filled, `Listing::store()` returns `fakeSuccess()` (`app/Controllers/Listing.php:197-199, 367-371`): the person is told "Almost done. Check your email…" and nothing is saved or sent. See C-01. |
| 2 | Verified CTA without plan parameter | **Ruled out (works as designed)** | `/add-profile` with no `plan` renders both cards with Verified first and preselected, and `?plan=free` picks Free (`app/Controllers/Listing.php:53-62`; pinned by `tests/database/SignupModesTest.php` and `HomePricingTest.php`). Caveat: a visitor carrying a campaign source (`?via=` or an invite) gets the Verified-only form on **both** URLs, so "Create Your FREE Business Profile" then shows no Free card. That is intended, but it surprises anyone who arrived from a campaign and then clicks the Free button. |
| 3 | Featured profiles with generic images | **Confirmed** | Featured is an admin flag only: `DirectoryService::featured()` selects `status = published AND is_featured = 1` ordered by `published_at`, with no photo requirement (`app/Services/DirectoryService.php:1354-1386`). The card prefers the category's **stock photo over the business's own logo** when there is no gallery photo (`app/Views/directory/_featured_strip_card.php:28-31`). Live: 7 featured profiles, of which `skullair-tattoos` and `peak-movement-physiotherapy-biokinetics-medical-suites-2` have 0 gallery photos, though both have a logo. |
| 4 | Duplicate profiles | **Ruled out as a live duplicate; slug artefact confirmed** | The only suffixed slug on production is `peak-movement-physiotherapy-biokinetics-medical-suites-2`, and **no row with the base slug exists**, deleted or not. The original was hard deleted after the copy was made, leaving the `-2`. Signup dedupes by email only (`DirectoryListingMutationService::submitPublic()`, `findActiveByEmail`). No two live profiles share a name. 8 phone numbers are shared by more than one profile; 7 of those groups are imported mall shops (plausibly one owner, several shops), and one group is two public signups. |
| 5 | Sole traders and verification | **Confirmed gap** | The form hint says "Your CIPC registration certificate, or equivalent" (`app/Views/directory/_verification_fields.php:24`), and "equivalent" is never defined. The FAQ (`app/Views/directory/faq.php:60, 70, 111`) and `/verified` both say only "company registration document". No page tells a sole proprietor without CIPC registration whether they can be verified, or with what. |

## 3. Findings

Severity follows the brief. **No finding is rated Critical.** C-01 and C-02 lose what a person typed, and C-01
tells them it succeeded, which comes close. They are rated High because C-01 is plausible but not reproduced
(it needs a real browser with saved autofill data) and C-02 needs the form open for more than 2 hours.

| ID | Sev | Area | Location | Evidence | Impact | Fix | Effort |
|---|---|---|---|---|---|---|---|
| C-01 | High | Signup | `form.php:91`, `directory.css:3294`, `Listing.php:197-199` | Second honeypot is hidden off screen at 0×0, not `display:none`, labelled "Company website"; a filled value returns a fake success | Any autofill or password manager that fills it silently discards a real signup and tells the person to check an inbox that never gets mail | Hide with `display:none` (the `hidden` attribute), rename to something no autofill heuristic matches, and log every trip so false positives become visible. Test with Chrome autofill and 1Password before and after | S |
| C-02 | High | Signup | `Security.php:69,78,89`, `Session.php:44`, `Listing.php:204-207`; signup form has no `data-draft` (`directory.js:4090`) | CSRF token and session both last 7200 s. On production a CSRF failure redirects back with only "The action you requested is not allowed." and **no input** (`system/Filters/CSRF.php:55`). A missing session stamp returns the fake success | A form left open over 2 hours (photos, hours, description) loses everything, with either a generic error or a false success | Give the signup form the same `data-draft` local copy the owner edit form already has. Turn a missing session stamp into a visible "please try again" rather than fake success. Restore input on CSRF failure for this route | M |
| C-03 | High | Claim | `show.php:322-324`, `Manage.php:72,94-104`, `manage_request.php` | 262 of 301 live profiles are `source = webscheduler` imports with no email. "Is this your business?" goes to `/verified`, then `/manage`, which only emails an address on file. Reply: "If that email has a profile, we have sent it a link…", and nothing arrives | The owners of 87% of the directory cannot take over, complete or upgrade their profile. They are also the warmest Verified prospects | A "Claim this profile" flow: the owner asks, admin approves (optionally with the same documents as Verified), the email is attached. At minimum, add a line on `/manage` and the profile CTA: "Added by us? Contact us to claim it" | M |
| C-04 | High | Verified | `VerificationSweep.php:73-78`, `VerificationService.php:395` | The approval email is sent once. The sweep reminds renewals only. Production: 2 applications `approved` 59 and 18 days ago, unpaid | One third of all applications stall at the step that earns money | A reminder from the sweep at 2, 7 and 21 days after approval, reusing the approval email's magic checkout link | S |
| C-05 | High | Signup | `DirectoryListingMutationService.php:931-1276`; live form | 110 named controls. 11 carry HTML `required`, and the server also requires category, consent, a 50 character description, complete opening hours or "by appointment", at least one service, and a logo or photo. Those are checked **only after submit**, and a failed submit drops every chosen file (`Listing.php:241-252` discards the logo; the gallery and documents are not kept) | Every rejected submit costs re-uploading photos and documents, and title, position and postal code add questions with no public value | Required at signup: name, category, email, town, description. Move hours, services and photo to a post-confirmation "finish your profile" step, which the quality nudge already supports. Add client-side checks for the server-only rules. Drop title and position from signup | M |
| C-06 | Medium | Signup | `Listing.php:219-223` | The throttle answers `withInput()`, but the form restores only the `old` flashdata (`Listing.php:105`), so it comes back **empty**. The limit is 3 per hour per IP | Shared IPs (office NAT, mobile carrier NAT) hit it with real people, and they lose the whole form | Return `->with('old', $post)` like the validation path. Consider 10 per hour per IP with the per email limit kept | S |
| C-07 | Medium | Verified | `_verification_fields.php:24`, `faq.php:111`, `verified.php` | "or equivalent" is undefined, with no mention of sole traders | Many target trades (hair, beauty, home services) are sole proprietors and cannot tell whether they qualify | Decide the accepted alternatives (for example a SARS tax registration letter, a municipal trading licence, or a bank letter in the trading name) and state them on all three surfaces | S (copy) after the policy decision |
| C-08 | Medium | Home | `_featured_strip_card.php:28-31`, `DirectoryService.php:1354` | A featured card falls back to a category stock photo before the business's own logo; nothing stops a profile with no photos being featured | The home showcase looks generic and undersells featured placement | Prefer the logo card over the stock photo, and warn in admin when featuring a profile with 0 photos | S |
| C-09 | Medium | Accessibility | `.btn-accent`, `.text-brand-orange`, `.home-link-accent` (`resources/directory.css:437, 894`) | Computed contrast is **2.63:1** for white on #F77F00 and for #F77F00 on white, on every primary CTA ("Add business", "Post a job", "Subscribe", "Get Your Business Verified", "Create your FREE Business Profile") | Fails WCAG AA (4.5:1) site-wide on the buttons that matter most; harder to read outdoors on phones | Keep #F77F00 for fills behind dark text, or use `accent-700` (#c2410c, about 5.2:1) for white text and orange link text. This is a brand decision | S |
| C-10 | Medium | Accessibility | `app/Views/directory/_form_fields.php:168-169` (pattern throughout) | `<label>` sits beside the input with no `for`/`id`. The live probe found 40 controls with no accessible name on `/add-profile`, including `display_name`, `email`, `address_line`, `city`, `postal_code` and all 21 hours inputs | Screen readers announce unnamed fields, and tapping a label does not focus its field, which hurts on mobile | Add `id` and `for` pairs, or wrap the input in its label. The partial is shared with owner edit and admin, so one change fixes three forms | S |
| C-11 | Medium | Signup | `Directory.php:720-721`, `Config/Directory.php:232` | An expired or used confirm link (48 h) lands on `/` with "That verification link is invalid or has expired." and no next step. Resubmitting the form with the same email does resend it (`DirectoryListingMutationService.php:78-92`), but nothing says so. Production has 1 profile still `pending` | People who confirm late, or on another device, stop here | A "Send me a new link" form on that error, using the existing duplicate pending path | S |
| C-12 | Medium | Verified | `VerificationService.php:1127-1132` | Owner emails exist for approved, rejected, activated and renewing. **None go out for lapsed (payment failed) or cancelled.** Production: 1 lapsed | A failed card silently pauses the badge, and the owner only finds out if they visit the dashboard | A lapse email with the reactivate link the dashboard already offers, and a cancellation receipt | S |
| C-13 | Medium | Measurement | `public/assets/directory.js:279`; see section 5 | The only custom GA4 event is `pricing_cta_click` (live since 17 Oct). No funnel step after a click is measured as an event | There is no way to see where the 2,500 target is being lost | Server-side events at each step (section 5), or at least GA4 events on form start, submit result and confirm | M |
| C-14 | Low | Home | `home.php:166-172` | The live stats line reads "299 profiles · 34 categories · 4 provinces". Production: Gauteng 287, Western Cape 8, KwaZulu-Natal 1, Limpopo 1, empty 2 (Johannesburg 273) | "4 provinces" implies national coverage that a visitor outside Gauteng will not find | Remove the province count, or say "Johannesburg and growing" until a second province has a meaningful number | S |
| C-15 | Low | Frontend | `Config/Honeypot.php`, `Filters.php:159`, `BotTrap.php:41-43` | `id="hpc"` appears 3 times per page (invalid HTML); the field is injected into GET search forms where it does nothing; a trip returns bare text "Forbidden" | Validator noise and a dead end page in the rare real-user trip | Exempt GET forms; give the injected input `autocomplete="off"`, `tabindex="-1"` and `aria-hidden`; render the branded error page with a "try again" link | S |
| C-16 | Low | `/compare` | `app/Config/Comparison.php:21-48`, live `/compare` | The page says "Checked on 3 October 2026, from each company's own help pages", but **links no sources**. They exist only in a code comment, two of them are third-party blogs (`juicydesigns.co.za`, `sherrlinn.co.za`), and one row's note is dated 13 Oct while the page says 3 Oct | No claim about Google, LinkedIn or SA directories can be verified from the page itself | Link each competitor cell to its source, replace the blog sources with first-party pages, and keep one checked date | S |
| C-17 | Low | Offers | `home.php`, footer, `/profile-setup` | The home page presents Free and Verified. The R250 setup service appears only as a footer link ("We set up your profile") | Owners who don't have time to fill the form never meet the paid option made for them | A one-line "Rather have us do it? R250, once off" under the Free card | S |
| C-18 | Low | Data | production slugs | One orphan `-2` slug (issue 4); dedupe is by email only | The URL looks like a duplicate and is shared in featured | Admin slug rename with a 301 from the old slug | S |
| C-19 | Low | Accessibility | `_form_fields.php` (`.err` divs) | Field errors are not linked by `aria-describedby` and set no `aria-invalid` (the summary is fine: `_error_summary.php:21`, `role="alert"`) | A screen reader user hears the summary but not which field it refers to | Add `aria-invalid` and `aria-describedby` on server-rendered errors, as the careers script already does | S |
| C-20 | Low | Mobile | live probe | Targets under 24 px tall at 375 px: footer social icons 20×20, the "With Verified" tags 112×20, and the breadcrumb and category chips around 20 px | Mis-taps on phones | Minimum 24 px (WCAG 2.5.8), 44 px for the icons | S |

## 4. Journey maps

Friction points are marked ⚠, and points where data can be lost are marked ⛔.

### Free signup: home to live profile

1. **Home** → "Create your FREE Business Profile" (hero, pricing card and closing CTA all go to `/add-profile?plan=free`). ⚠ After a campaign link, this shows the Verified-only form (issue 2).
2. **`/add-profile?plan=free`**: one long page with 110 controls in about 8 sections (business details, a ChatGPT description helper, where customers meet you, private contact details, category facets, services, hours, photos). A live strength score updates as the form is filled.
   - ⚠ About 17 compulsory items. Title, position and postal code have no public value (C-05).
   - ⚠ Labels aren't tied to their fields, and CTA contrast is 2.63:1 (C-09, C-10).
   - ⛔ No browser draft is kept (C-02).
3. **Submit**, with these possible outcomes:
   - **Validation error:** back to the form with the input kept but **all files dropped** ⛔ (C-05).
   - **Throttle (3 per hour per IP):** "Too many submissions…" with an **empty form** ⛔ (C-06).
   - **CSRF expiry after 2 hours:** "The action you requested is not allowed." with an **empty form** ⛔ (C-02).
   - **Honeypot autofilled, or session stamp missing:** **fake success**, nothing saved ⛔ (C-01, C-02).
   - **Success:** "Almost done. Check your email to verify and publish your profile."
4. **Email confirmation** (48 hour link). Clicking it publishes the profile and signs the owner in, landing on `manage/edit#get-verified` (Verified pitch) when there is no application. ⚠ An expired link is a dead end with no resend (C-11).
5. **Profile completion:** happens in the owner dashboard (`/manage/edit`, which does keep a draft). The quality nudge emails once under 65 (memory: profile-quality-bars). Production: 199 of 299 published profiles score under 40.
6. **Live in the directory:** listed in search and category pages, ordered by `is_featured`, then `quality_score`, then `published_at`.

### Imported profile owner (87% of profiles)

1. Finds their shop's profile → "Is this your business? Get the Verified Business badge" → `/verified`.
2. `/verified` → "apply from manage your profile" → `/manage` → types their email.
3. ⛔ "If that email has a profile, we have sent it a link…". No email exists on the profile, so nothing arrives and there is no next step (C-03).

### Verified upgrade: new business

1. `/add-profile` (Verified preselected) → the same form plus two document uploads (registration document and owner ID, PDF, JPEG or PNG, 10 MB). ⚠ No guidance for sole traders (C-07).
2. Submit → the profile is saved first. If documents are missing: "Your profile is saved, but we did not receive both documents…" (`Listing.php:313-319`).
3. Confirm the email (as above).
4. **Submitted:** the dashboard says "Your documents are with us. We usually review within two working days and will email you either way. You have not been charged anything."
5. **Admin review** at `/admin/verifications`.
   - **Rejected:** an email with the reason; the dashboard shows the reason and a "Send new documents" form. Good.
   - **Approved:** an email with a live sign-in link straight to checkout; the dashboard shows "Activate my badge, R29.99 a month". ⚠ If it isn't clicked, **nothing ever follows up** (C-04).
6. **Checkout**, only from `approved` or `lapsed` (`Manage.php:431-435`). PayFast monthly subscription at the amount snapshotted when the application was made (`VerificationService.php:312, 323`; `PayFast.php:96-145`).
7. **Payment return:** "Thanks, we have your payment. PayFast is confirming it now…"; the ITN activates it, and the "activated" email goes out.
8. **Active:** the badge goes live. A renewal reminder is sent 3 days before each renewal (`VerificationSweep.php:43`).
9. **Cancel:** the dashboard "Cancel my badge" (with a confirm) calls PayFast's cancel API. On success the screen says "Cancelled. You will not be charged again…"; on failure the admin is alerted and the owner is told honestly. ⚠ No email receipt (C-12).
10. **Lapsed:** the dashboard says "Your badge is paused…" and offers Reactivate. ⚠ No email (C-12).

### Verified upgrade: existing free profile

1. Home "Already have a free profile? Upgrade from your dashboard" → `/manage` → enter email → "If that email has a profile…" message → 1 hour single-use link by email.
2. Dashboard → the "Get verified" panel (`#get-verified`) → upload both documents → Submit for review.
3. Steps 4 to 10 as above.

**Checks the brief asked for**
- The price is read from settings: `DirectorySettings::badgePrice()`. No view hard-codes it; the only literal 29.99 is an input hint in `admin/settings.php:69`.
- The billed amount is the application's snapshot, which matches the displayed price at the time of application.
- No payment before approval: confirmed.
- "Cancel any time": confirmed, through the PayFast API with an honest fallback.
- Each waiting stage tells the owner what happens next: confirmed for every dashboard state; the gaps are the emails in C-04 and C-12.
- A rejected application explains why and how to resubmit: confirmed.

## 5. Measurement gaps

GA4 (with Consent Mode v2) is live and records page views. The only custom event is `pricing_cta_click`, labelled
`free`, `verified` or `upgrade_existing`, on the home pricing section. For the rest, the database holds timestamps
that could feed reports, but no analytics event fires.

| Step | Status | What exists today |
|---|---|---|
| Free signup started | **Partial** | `pricing_cta_click` (`cta=free`) from the home section only. No form start event; other entry points (header "Add business", footer, category pages) are not tagged |
| Free signup submitted | **Missing** (event) | Row in `xs_directory_listings` (`created_at`, `signup_source`). Failed submits (validation, throttle, CSRF, honeypot) leave no trace at all |
| Email confirmed | **Missing** (event) | `is_verified = 1` and `published_at` on the row |
| Profile completed | **Missing** (event) | `quality_score` (recalculated nightly); `quality_nudge_sent_at` |
| Verified application submitted | **Missing** (event) | `xs_directory_verifications.submitted_at` |
| Documents approved | **Missing** (event) | `reviewed_at` with `state = approved` |
| First payment | **Missing** (event) | `activated_at`, verification events, ITN records |
| Cancellation | **Missing** (event) | `cancelled_at`; admin notification email |

## 6. Top 5 fixes

1. **A claim flow for imported profiles (C-03).** 262 owners can't act on 87% of the directory, and they are
   the most direct route to both growth and Verified.
2. **A payment reminder for approved applications (C-04).** It's a few lines in the sweep, and it recovers a
   third of the paid funnel.
3. **Stop silently losing signups (C-01, C-02, C-06).** Hide the honeypot properly, never answer a real failure
   with a fake success, and keep a draft of the signup form.
4. **A shorter free signup (C-05).** Ask for five things, publish, then finish the profile from the dashboard.
   The quality nudge already exists for that second step.
5. **Funnel events (C-13).** Without them, the effect of fixes 1 to 4 on the 2,500 target can't be measured.

## 7. Out of scope observations

**For the SEO audit**
- 199 of 299 published profiles score under 40. 262 are imports with thin content, so the thin content rule
  matters more than anything else in that audit.
- `robots.txt` is generated by `Directory::robots()`.
- The orphan `-2` slug needs a canonical or a 301.
- The duplicated `id="hpc"` makes the HTML invalid.
- "Fill This Field" appears in the page text.

**For the security audit**
- There are two honeypots with deliberately different responses (a 403 versus a fake success).
- CSRF is `cookie` mode with `regenerate = true`. `add-profile/score` is CSRF exempt by design.
- Live responses carry both an enforcing CSP and a report-only CSP header.
- Manage links last 1 hour and are single use. Request throttles are 5 per 10 minutes per IP and 3 per 10
  minutes per address.

## Appendix: commands run

All production access was read only.

```
# code
grep/sed over app/Config/{Filters,Honeypot,Security,Session}.php, app/Filters/BotTrap.php,
  app/Controllers/{Listing,Manage,Directory}.php, app/Services/{DirectoryService,DirectoryListingMutationService,
  VerificationService}.php, app/Libraries/PayFast.php, app/Commands/VerificationSweep.php,
  app/Views/directory/{form,_form_fields,_verification_fields,_verification_panel,_featured_strip_card,show,
  faq,verified,_error_summary}.php, public/assets/directory.js, resources/directory.css,
  vendor/codeigniter4/framework/system/{Honeypot/Honeypot.php,Filters/CSRF.php}

# live, GET only
curl -s https://local.webscheduler.co.za/{,add-profile?plan=free,compare,profile-setup,directory?q=zzqqxx,
  directory?city=Polokwane&category=home-decor}
node scratchpad/audit/a11y.cjs  (Playwright; /, /add-profile?plan=free, /directory/cpt-dental, /jobs at
  375/768/1280 light, 375/1280 dark; overflow, target size, labels, contrast, duplicate ids, focus, console)

# production, read only (ssh lightsail; credentials read on the server, never printed)
SELECT … FROM xs_migrations GROUP BY batch
SELECT … featured profiles with photo and logo counts
SELECT … slugs matching '-[0-9]+$' joined to their base slug
SELECT … duplicate name and phone groups (slugs only)
SELECT province|city|status|source|month, COUNT(*) … GROUP BY …
SELECT quality_score bands; SELECT verification state, submitted/reviewed dates
```
