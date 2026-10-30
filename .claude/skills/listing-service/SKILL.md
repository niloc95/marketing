---
name: listing-service
description: Work on WebScheduler Local, the CodeIgniter 4 app in app/ that serves local.webscheduler.co.za and local.webscheduler.app. Code map (controllers, services, libraries, helpers, filters, config, commands, cron), routes, data model, signup and verify, owner manage flow, admin, the paid Verified Business badge and its gates, Jobs board, careers and screening, reviews, referrals, partners, profile setup service, public holiday hours, profile page parts, geocoding, typeahead, consent and Mautic, CSP rules, local env, tests and verify steps. For anything that differs between co.za and .app (countries, Plus, per site copy, prices, links in emails) also load the two-sites skill.
---

# WebScheduler Local (`app/`)

A local business discovery and visibility platform: free business profiles, discovery
(browse, smart search, maps, near me), public profile pages, signup gated by email
verification, owner self service through a passwordless magic link, a Jobs board, reviews,
and an admin backend. One install serves **two sites**: `local.webscheduler.co.za` (South
Africa) and `local.webscheduler.app` (every other country, live since 25 Oct 2026). How the
two are kept apart is the **`two-sites`** skill; read it before touching anything a page
shows, where a link points, or a price.

Repo root `README.md` has the setup runbook (env vars, Mailpit); this skill is the
condensed working reference for making and verifying code changes.

**Deploying is a separate skill: `deploy-production`.** Use it for releases, migrations
against production, and diagnosing a deploy that went green without changing anything.
`deploy/README.md` and `deploy/LAMP-SETUP.md` are the first-time server setup; `DEPLOY.md`
is the archived Hostinger record.

## Copy keywords: discover, discovery, platform (since 21 Oct 2026)

Customer facing copy (public views, owner pages, emails, JS messages, `Config\Comparison`)
uses **discover**, **discovery** and **platform** as its key words. WebScheduler Local is
"a local business discovery and visibility platform", never a directory.

- Our own feature is never "Search": buttons, placeholders and aria labels say
  **Discover** ("Discover local businesses", "Discover in Gauteng"); results are
  **discovery results**; a business is **discovered on the platform**.
- Keep "search" only where it means someone else's search: search engines, Google's job
  search, paid search ads, Mapbox address search. Legal pages and admin screens were
  left as they are.
- Code identifiers stay: `_search_composer`, `/directory/suggest`, `Config\Search`, CSS
  classes, `name="q"`.
- No dashes or hyphenated words in this copy either (see the no dashes rule).
- Tagline (footer, /company, `Config\Directory::$slogan` in the home JSON-LD):
  **Discover local. Be discovered.** Never write "More than a business listing."
- On .app: never "built for South Africa", and no rand figures (see `two-sites`).

## Code map

| Where | What lives there |
|---|---|
| `app/Controllers/` | `Directory` (home, discovery, categories, map, suggest, province/country/region/venue pages, profile `show`, menu, verify, sitemap, robots), `Listing` (signup at `/add-profile`, live score, cross site hand over), `Manage` (owner magic link, dashboard, badge and Plus checkout), `Jobs`, `Reviews`, `Referral`, `Partners`, `Careers`, `ProfileSetup` + `ProfileSetupNotify`, `PayFastNotify`, `Contact` (contact, faq, company, verified, plus, compare, resources, media), `Legal`, `Unsubscribe`, `AddressSuggest`, `DescriptionHelper`, `Health`, `Csp`, `Errors`, `Admin` + `AdminCareers` + `AdminProfileSetup` |
| `app/Controllers/Concerns/` | `HandlesListingUploads`, `HandlesVerificationUploads`, `LocksDownDocumentCsp`, `RedirectsToOwningSite` |
| `app/Services/` | Read side `DirectoryService`; write side `DirectoryListingMutationService` (public + owner) and `DirectoryAdminService` (admin); one service per feature: `VerificationService`, `JobBoardService`, `ReviewService`, `ReferralService`, `PartnerService`, `CareersService`, `ProfileSetupService`, `HolidayHoursService`, `MarketingConsentService`, `TeamMemberService`, `PracticeLocationService`, `ServiceMenuService`, `ListingMenuService`, `ListingFacetService`, `ListingBrandService`, `ListingVideoService`, `ListingQualityService`, `ListingStatsService`, `ProfileNudgeService`, `ApplicationOnceService`, `ComparisonService`, `HeroImageService`, `FunnelService`, `SystemStatusService`, `DirectorySettings`, `SiteContext`; `Search/` (smart search interpreter), `Description/` (description helper and hints) |
| `app/Libraries/` | `Mailer` (the only mail path), `MauticClient`, `PayFast`, `Geocoding/*` + `ListingGeocoder`, `PublicHolidays` / `YasumiHolidays` / `NoPublicHolidays`, `ListingText`, `RichText`, `ServiceArea`, `PostingSections`, `Screening` + `ScreeningBank`, `ListingImageProcessor`, `VerificationDocumentProcessor`, `CloudflareAccess`, `DomainChecker`, `TokenHash`, `SpatialSupport`, `SystemHealth`, `MailHealth`, `LogReader`, `Input` |
| `app/Helpers/` (autoloaded: url, seo, schema, slug, map, directory_ui, money, site) | `directory_ui_helper` (badges, pills, gates like `listing_is_verified_business()` / `listing_has_premium()`, `listing_public_view()`, `signup_cta()`, `listing_form_steps()`…), `directory_hours_helper` (hours, holidays, per profile clock), `schema_helper` (JSON-LD), `seo_helper` (`seo_meta()`), `site_helper`, `money_helper`, `map_helper`, `slug_helper`, `video_helper` |
| `app/Filters/` (order in `Config\Filters`) | Before: `FrontControllerPath` (404s a typed `/index.php/…`), `SiteResolver`, `BotTrap`, `csrf` (exempt: `csp-report`, both PayFast ITNs, `unsubscribe/*`, `add-profile/score`), `SignupChannel` (`?via=`). After: `honeypot`, `PartnerRef` (`?ref=`), `SecureHeaders` (CSP and friends). Admin: `cfaccess` (`CloudflareAccessFilter`, JWT) on `admin`/`admin/*`, then the `admin` group filter (`AdminFilter`, password session). A new webhook needs the same exemptions as `payfast/notify` |
| `app/Config/` (own) | `Directory` (prices, quality bars, keys), `Sites`, `Countries`, `Search`, `JobBoard`, `Reviews`, `Careers`, `Screening`, `Partners`, `Comparison`, `ListingAttributes`, `ListingFacets`, `Verticals`, `DescriptionTemplates`, `DocTypes`, `AdminDocuments`, `Hostnames`, `ContentSecurityPolicy` |
| `app/Models/` | One per table, `xs_` prefix. `DirectoryListingModel` is the hub; `Concerns/DerivesCountryCode` |
| `app/Views/` | `layouts/public.php` (one layout for every page, header, footer, analytics), `directory/` (public and owner pages, `_partials`), `admin/`, `jobs/`, `careers/`, `emails/` (`_layout.php` + one per message), `legal/` (+ `legal/app/` for .app), `errors/`, `partials/` |
| `app/Commands/` | spark commands, see Commands below |
| `resources/directory.css` → `public/assets/directory.css` | Tailwind (`tailwind.directory.cjs`, tokens shared with the marketing site in `tailwind.tokens.cjs`) |
| `public/assets/directory.js` | every client behaviour, one file: typeahead, address lookup and maps, form steps, repeaters, photo band, lightbox, consent |
| `tests/database/`, `tests/unit/` | PHPUnit. `tests/_support/` holds `ValidListingInput`, `WithEnv` and the bootstrap |

**Conventions worth copying:** a feature's rules live in one service that is the only
writer of its tables; controllers stay thin; anything an owner must not set stays out of
`OWNER_EDITABLE`; customer emails render through `emails/_layout.php` with
`saveData => false`; long docblocks explain *why* and point at the test that pins the rule.

## Routes (`app/Config/Routes.php`)

| Route | Controller/action |
|---|---|
| `GET /` | `Directory::home`: featured, categories, examples, pricing cards |
| `GET /directory` | `Directory::index`: discovery (`q`, `category`, `province`, `city`, near me, facets) |
| `GET /directory/categories`, `/directory/map`, `/directory/suggest` | hub, map JSON, typeahead JSON |
| `GET /directory/province/{slug}` | `Directory::province` (co.za) |
| `GET /directory/at/{venue}` | `Directory::venue`: every business in one complex, mall or building |
| `GET /directory/{slug}/menu` | `Directory::menu`: uploaded menu file |
| `GET /directory/{a}/{b}` | `Directory::place`: category in a province (co.za) or country (.app), or country + region |
| `GET /directory/{category}/{country}/{region}` | `Directory::categoryInRegion` (.app) |
| `GET /directory/{slug}` | `Directory::segment`: category landing, country page (.app) **or** the profile (`show`). Category wins, so a profile can never claim a category's slug |
| `GET /directory/verify/{token}` | `Directory::verify`: confirms the email, publishes, signs in, lands on the upgrade panel |
| `GET/POST /add-profile`, `GET /add-profile/verified`, `POST /add-profile/score` | `Listing::*`: signup (one form, three modes), live quality score. `/add-listing*` 301s here; `POST /add-listing` still works |
| `GET /verified`, `GET /plus` | what the badge (co.za) and Plus (.app) mean |
| `GET /compare`, `/resources`, `/media`, `/company`, `/faq` | `Contact::*` |
| `GET/POST /profile-setup`, `/profile-setup/checkout`, `/profile-setup/thanks` | the paid setup service |
| `GET/POST /manage`, `GET /manage/{token}`, `GET/POST /manage/edit`, `/manage/signout` | owner magic link and dashboard |
| `POST /manage/verification`, `GET /manage/verification/checkout[?plan=plus]`, `POST …/cancel`, `GET …/done` | badge or Plus: apply, pay, cancel, PayFast return |
| `/manage/jobs/*`, `/manage/reviews/*`, `POST /manage/photo-delete/{id}`, `/manage/brand-delete/{id}` | owner tools |
| `/jobs/*` | Jobs board, see that section |
| `/reviews/*` | customer reviews |
| `/recommend`, `/partners`, `/p/{code}`, `/careers/*` | referrals, Partner Program, our own hiring (co.za only) |
| `GET /privacy`, `/terms`, `/cookie-policy` | `Legal::*` (per site views) |
| `GET /address-suggest`, `/address-locate`, `/address-reverse` | address lookup |
| `GET /description/insights`, `/description/prompt` | the description helper |
| `POST /payfast/notify`, `POST /payfast/setup-notify` | PayFast ITNs (subscriptions, setup orders) |
| `GET /sitemap.xml`, `/robots.txt`, `/health`, `POST /csp-report` | per site sitemap and robots, health, CSP reports |
| `/admin/*` | profiles, categories, venues, verifications (`?plan=plus`, `/admin/plus`), jobs, reviews, referrals, partners, careers, profile setup, hero, settings, comparison, documents, status, funnel |

**404s go through `Errors::notFound`**, the `Config\Routing::$override404`. That covers
unknown routes and any `PageNotFoundException` a controller throws. It renders the branded
`errors/not_found` view with a real 404 status, `noindex`, the security headers, and a
fixed canonical, so the requested URL is never reflected. In feature tests a 404 is
therefore a **response**: use `->assertStatus(404)`, not `expectException`.
`errors/html/error_404.php` is only the fallback if that page itself fails. Production
also has `zend.exception_ignore_args = On`, which is why `Config\Exceptions` switches trace
masking off there (see its constructor).

**Route-ordering gotcha** (called out in comments in `Routes.php`): literal segments
(`manage/edit`, `directory/categories`, `directory/verify/...`) must be declared *before*
the `{segment}`/`{token}` catch-alls, or the catch-all swallows them first.

## Data model

`DirectoryListingModel` — table `xs_directory_listings`, soft-deletes, FULLTEXT search
over `display_name`/`description`/`credentials`.

- `OWNER_EDITABLE` allowlist (what `/manage/edit` may write): business fields only —
  `type, display_name, contact_person, title, position, category_id, credentials, description,
  phone, phone_alt, whatsapp, website, social_facebook, social_instagram,
  social_linkedin, social_tiktok, address_line, address_line_2, suburb, city, province,
  region, postal_code, logo_path, trading_hours, by_appointment, accepts_card_payments, offers_delivery,
  offers_online_booking, booking_url, customer_location, show_address, service_areas`. Deliberately **excludes** `email`, `slug`,
  `status`, `is_featured`, `is_verified`, `country` (it decides whether a listing is
  free) and `venue_id` — a crafted owner POST can't publish/feature a listing, hijack the
  address, dodge the International Listing paywall, or break the SEO'd URL. Read the
  constant rather than trusting this copy of it, since it is a security boundary and
  this doc has drifted from it before.
- **Links and the WhatsApp number are checked on every write path *and* at render.**
  `website`/`booking_url` go through `normaliseUrl()` + `safe_external_url()`. The four
  `social_*` fields go through `social_profile_url()` (in `directory_ui_helper.php`),
  which also requires the host to match the network (so the Instagram icon cannot link
  anywhere else) and turns an `@handle` into a link for Instagram/TikTok. `whatsapp` is
  stored as international digits via `whatsapp_digits()` (leading `0` → `27`), and
  `whatsapp_chat_url()` builds the profile's "Chat on WhatsApp" wa.me button. Signup,
  owner edit and `DirectoryAdminService::upsert()` all use these helpers. A new
  link field needs the same treatment on all three.
- **`is_verified` and `verified_until` are unrelated.** `is_verified` (bool) means the
  signup email was confirmed — `verify()` sets it alongside `status = published`.
  `verified_until` (date) is the paid Verified Business badge. Neither implies the other.
- Other tables: `directory_categories` (seeded taxonomy, 226 categories / 19 groups, ordered by `directory_category_groups`),
  `directory_practice_locations` (extra locations per listing), `directory_tags` +
  `directory_listing_tags` (filterable areas of focus), `directory_listing_team` (team
  members), `directory_listing_services` + `directory_listing_attributes` ("Services &
  prices" and "Features & amenities" — free for every listing, written only through
  `ServiceMenuService`; feature keys and labels live in `Config\ListingAttributes`, keyed
  by category `group_name`, and saves drop keys the chosen category's group doesn't offer.
  Both sections carry a `*_present` marker input: absent = leave alone, present-but-empty =
  clear), `directory_settings` (admin-editable badge price/enabled), and the verification
  set — `directory_verifications`, `_verification_documents`, `_verification_events`,
  `_verification_submissions`, `_verification_itn_rejections` (the verifications table
  holds both plans: `plan = badge` or `international`, which is Plus on .app).
- Every table is `xs_` prefixed. The full set, by feature: profiles
  (`directory_listings`, `_practice_locations`, `_listing_photos`, `_listing_brands`,
  `_listing_menu_files`, `_listing_facets`, `_listing_team`, `_listing_services`,
  `_listing_attributes`, `_tags`, `_venues`, `_categories`, `_category_groups`); money
  (`_verifications` + its four child tables, `_profile_setup_orders`); Jobs
  (`_job_posts`, `_job_responses`, `_job_reports`, `_job_alerts`); reviews (`_reviews`,
  `_review_reports`); growth (`_referrals`, `_invite_suppressions`, `_partners`,
  `_partner_clicks`, `_partner_commissions`, `_partner_payouts`); careers
  (`_career_roles`, `_career_applications`); `_application_keys` (apply once); home
  (`_hero_images`, `_hero_videos`); `_settings` (`name`, `value`).
- Country columns (since 23 Oct 2026): `country_code` on listings, locations, venues, job
  posts, careers and referrals (derived, see `two-sites`); `timezone` on listings and
  locations; `currency` on the money tables.

## Signup quality rules (since 30 Sep 2026)

Added after a spam profile ("Polokwane ➸ [+②⑦⑦…] A TRADITIONAL HEALER…") went live.
Enforced in `DirectoryListingMutationService::validate()` on **signup and owner edit**;
admin intake (`DirectoryAdminService::upsert()`) is exempt.

- **Name / description** rules live in `App\Libraries\ListingText`. Spam shapes (phone
  numbers, emoji, ②-style digits, `[]`, >3 commas, URLs) are **rejected**; ALL-CAPS is
  **fixed quietly** (`tidyName()`, `tidyDescriptionHtml()` via `RichText::mapText()`).
  Description is compulsory, 50+ plain chars. A stored value that is unchanged is never
  re-judged (the `$stored` argument to `validate()`), so legacy rows can still save.
- **Website**: `normaliseUrl()` upgrades `http://` → `https://`, refuses IPs/localhost/
  userinfo/no-TLD, and a new or changed link must resolve in DNS through
  `Services::domainChecker()` (a lookup, never a request to the site). Under
  `ENVIRONMENT === 'testing'` the checker says yes to everything; inject a fake to test a no.
- **Title / Position** are dropdowns from `DirectoryListingModel::TITLES` / `POSITIONS`;
  a legacy free-text title stays valid while unchanged.
- **Compulsory sections**: complete hours *or* `by_appointment`, one named service, and a
  picture. The picture flag is `$post['_has_photo']`, always written by the controller
  (`HandlesListingUploads::hasPhoto()`) and only enforced when that key is present, so
  direct service calls in tests skip it. Tests build valid input with
  `Tests\Support\ValidListingInput::withRequiredSections()`.
- **Form flow**: filling `[data-standout-trigger]` (contact person) opens every
  `details.disclosure` in `[data-standout]` except `[data-standout-skip]` (branches).
- `php spark directory:audit-text [--all]` reports stored listings that break the rules
  (report only).

## Services split

- **`DirectoryService`** — read-side: `browse()`, `getProfile($slug)` (assembles the
  detail-page data: listing + category + locations + tags), `relatedListings()`,
  `categories()`/`categoriesGrouped()`, `provinces()`, sitemap URL helpers.
- **`DirectoryListingMutationService`** — write-side: `submitPublic()` (dedupes by email —
  a second signup with an existing email gets a manage link, not a second row, with an
  identical on-screen message either way), `verify()` (publishes), `requestManageLink()` /
  `redeemManageToken()` (single-use, 1h TTL), `updateOwn()` (applies only
  `OWNER_EDITABLE`).
- **Description cap** is `RichText::MAX_PLAIN_LENGTH` (1000 plain-text chars; the JS counter
  reads it from `data-rich-text-max`). `RichText::exceedsCap()` exempts an *unchanged*
  description, so profiles saved under the old 5000 cap can still save other edits.
- **`App\Libraries\Mailer`** — the **one** outbound-mail path. `send($to, $subject, $body,
  $replyTo = '')` returns whether it went out and records the result in `MailHealth`. It
  logs the recipient *domain* only, never the address or body. Signup/edit callers ignore
  the return value (a broken mailer must not block them, so `writable/logs/` and `/health`
  are the only signal); `Contact::submit()` uses it to tell the visitor the truth. Do not
  add a second send path — the error handling here exists because an earlier version
  checked for an exception that CI4's `Email::send()` never throws, so outbound mail could
  stop entirely and leave no trace.

## Mobile / service-area businesses (`customer_location`, `show_address`, `service_areas`)

"Address required" and "address publicly visible" are separate. The address stays
compulsory and stored for everyone. Only what the public sees changes.

- **Rules live in `App\Libraries\ServiceArea`.** Signup, owner edit and admin intake
  all call it. `columns()` writes nothing unless `service_area_present` is posted, so a
  partial POST cannot un-hide an address. It forces `show_address = 1` unless
  `customer_location = 'travel'`, because "visit" and "both" mean customers come to the
  door. `problem()` refuses links, phone numbers and symbols, and caps areas at 15 × 80
  chars. Areas are stored one per line.
- **The render-side rule is `listing_public_view()`** (`directory_ui_helper.php`). For a
  hidden address it blanks street, line 2, suburb, postal code, coordinates, precision,
  distance and venue. City and province stay. `show.php`, `_card.php` and both schema
  builders run rows through it. `_contact_panel.php` and `_map_panel.php` also check
  `listing_address_public()` themselves, so a caller that forgets still leaks nothing.
  A new public surface must do the same.
- **Location queries skip hidden listings** (`DirectoryService::HIDDEN_ADDRESS_SQL`):
  the map JSON always, and `browse()` whenever a near-me point, map bounds or venue is
  set. A radius asked often enough gives the address away. Search by `service_areas`
  works, and a suburb match is ignored for hidden listings.
- Branches (`directory_practice_locations`) are unaffected; a branch is somewhere
  customers go.
- The profile shows a "Mobile service — we travel to you" contact row and a
  `_service_area_panel.php` ("We travel to customers in …"). JSON-LD `areaServed` lists
  the areas as `Place` nodes.
- Tests: `tests/database/ServiceAreaBusinessTest.php`.

## Venues — one complex, mall or building

`directory_venues` + `xs_directory_listings.venue_id`, added for the Oriental Plaza import
(262 shops sharing `address_line_2` and one coordinate).

- **Not a tag, deliberately.** `syncListingTags()` replaces a listing's whole tag set on
  every save and the owner form posts tags as free text, so a venue kept there would
  vanish the first time an owner edited their profile. The migration docblock says so.
- **Admin-only.** `venue_id` is in `$allowedFields` but **not** `OWNER_EDITABLE`;
  `DirectoryAdminService::upsert()` writes it in the same gated block as `status` and
  `is_featured`. A venue is a claim about a shared building — an owner who could set it
  could move their shop onto any complex's page.
- **`ON DELETE SET NULL`.** Deleting a venue ungroups its businesses and deletes none;
  `deleteVenue()` reports how many were detached. Unlike `deleteCategory()` it does not
  refuse while in use.
- The page is `/directory/at/{slug}` — `at` is a reserved slug, and the prefix keeps venue
  slugs out of the category/listing namespace `Directory::segment()` resolves. Same
  `landingMinListings` indexability rule as the other landing tiers, a fourth sitemap
  emitter, and `?category=`/`?q=` narrow **within** the venue (both make it noindex).
- `browse()`'s venue filter is a plain `where()` after `applySearch()` — an `orWhere()`
  there escapes the OR group and lists the whole country. There is a test for exactly that.
- **The map shows the venue's own single pin**, because every shop in a building geocodes
  to the same point (`ListingGeocoder` leaves `address_line_2` out of the lookup). The
  `/directory` results map still stacks them — not fixed yet.
- Bulk assign with `php spark directory:venue-assign --venue <slug> --address2 "…"
  [--dry-run] [--set-venue-point]`. `--set-venue-point` copies the shops' shared
  coordinate onto the venue and refuses when they disagree. `--limit 20`, never
  `--limit=20` (the CLI quirk `GeocodeListings` documents).

## Profile types and Places (added 10 Oct 2026)

- **`DirectoryListingModel::TYPES` is the one list** (person, practice, facility, ngo,
  community, foundation, project, place). Signup, owner edit, admin intake and the form all
  read it through `normaliseType()`; the model's `in_list` rule spells the keys out and
  `ProfileTypesAndPlacesTest` pins the two together. The column is an ENUM, so a new type
  also needs a migration. `TYPE_SUGGESTIONS` gives every type but the two business ones a
  category shortlist (slugs, or `group:Name` for a whole group). `type_suggestion_ids()` turns it
  into ids on each type option's `data-suggest`, and `directory.js` shows it as a
  "Suggested for {type}" main category made of cloned options, opened when the type changes.
  The full taxonomy stays below it, and the server accepts any category. A category already
  picked survives a type change where it can. The test checks every entry is seeded.
- The last five are not businesses: `listing_type_badge()` shows "Nonprofit", "Place"… on
  the profile and cards (nothing for the three business types), and `schema_business_type()`
  takes the type as a third argument. ngo/community/foundation/project, and anything in the
  **Community & Nonprofit** group, publish as `["LocalBusiness","NGO"]`.
- **A Place is a profile that is also a venue.** `directory_venues.listing_id` (unique,
  `ON DELETE SET NULL`) names the venue's own profile. Only admin links it:
  "Create venue from this place" on the admin edit page (`venueFromPlace()`), or the
  "Place profile slug" field on `/admin/venues`. Either way the place's own `venue_id` is set
  to the venue. The place's profile then renders "Inside {name}" (`insidePlace()`, through
  `browse()` so hidden address listings stay out) in the `venue` panel slot, a business
  inside links to the place, and the venue page links to it. Owners still cannot set
  `venue_id`.
- **Faith & Worship** (Yelp's Religious Organizations) and **Places & Venues** (malls,
  stadiums, halls, markets, museums, parks) are their own main categories. Community &
  Nonprofit is filed by cause (Children & Youth, Feeding Scheme…), as Google advises.
  Worship and the civic places publish their exact schema.org type paired with LocalBusiness
  (checked before the NGO rule); Faith & Worship says "Service times" and carries the
  `worship_language` facet.
- Fitness & Sport has the `sport` facet set (`facilities`, `access`) in `Config\ListingFacets`,
  which is how "padel courts" finds a club.

## Geocoding provider: Mapbox for lookup, Nominatim as fallback

`Services::geocoder()` returns `MapboxGeocoder` when `directory.mapboxToken` is set and
`NominatimGeocoder` otherwise. Only address **lookup** is involved. Maps stay on
Leaflet/CARTO, and "near me" stays a query against `xs_directory_listing_points`.

- **Temporary vs permanent is a licensing line.** Mapbox forbids storing or caching
  temporary results. `MapboxGeocoder::suggest()` is temporary: it is never cached, and its
  rows carry **no lat/lng**. After a pick, the address script calls `/address-locate`
  (`geocodeParts()`, `permanent=true`) for the pin it posts. `geocodeParts()` and `reverse()`
  are permanent and cached for 30 days. Do not add coordinates back to suggestion rows.
  `MapboxGeocoderTest` pins all of this.
- **No token means no typeahead.** The public Nominatim server's policy forbids
  autocomplete, so `NominatimGeocoder::suggest()` returns `[]`, and the views leave out
  `data-suggest-url` (`Config\Directory::addressAutocompleteEnabled()`). With no URL,
  the script never searches. Single lookups on save, the pin picker, reverse lookups and
  `spark directory:geocode` still use Nominatim.
- The dropdown's "Address search by Mapbox" credit line is required by Mapbox's terms.
  The privacy policy names Mapbox as a recipient of entered addresses.
- The token is sent server to server only, never rendered. Use a `pk.` token with **no URL
  restriction**. Mapbox checks those against the browser's `Referer`, which a server request
  doesn't send, so adding one can make every lookup fail.

## Search typeahead (`/directory/suggest`)

Every search box — the header bar and the four hero `.searchbar` forms — renders through
**one partial, `directory/_search_input.php`**, which carries the markup contract
(`[data-search-suggest]`, the combobox input, the listbox `<ul>`). The client module sits
in `public/assets/directory.js` beside the address autocomplete and copies it: 3-character
floor, 450ms debounce, `++requestSeq` stale-response guard, arrow/Enter/Escape, and
`textContent` for every label (business names are owner-supplied).

- **It suggests, it does not filter.** Picking a row navigates; Enter with nothing
  highlighted submits the form exactly as before, so search still works with JS off.
- **`DirectoryService::suggest()` is deliberately not `browse()`** — five small
  lookups (businesses 5, team members 2, categories 3, venues 2, cities 3, capped at 8 total), because `browse()` is
  FULLTEXT + four LIKEs + a correlated EXISTS and must never run per keystroke. Ordering
  puts a prefix match above a mid-word one.
- **Businesses by description (since 29 Oct 2026):** after name and topic rows, free
  business slots are filled from the same FULLTEXT the results page runs, four letters
  up, ranked by match then quality (never `is_featured`), sub line = category. When any
  are shown, the list ends with a `search` row "Discover every match for …".
- **Related categories come from the discovery model** (`Search\DiscoveryModelService`,
  table `xs_directory_discovery_terms`, rebuilt nightly by `discovery:learn`, per site):
  a word links to a category when 2+ of its profiles use it (description, credentials,
  services, tags) and it is 2x+ as common there as platform wide; places and filler are
  never learned. Used for the dropdown's Category rows (falls back to grouping the live
  FULLTEXT matches when the model knows nothing) and the results page's "Related
  categories" links (`_search_related.php`, only when no category is set). It suggests,
  never filters or orders. Switch: `search.discoveryModel = false`. Never add a topic
  word ("cancer") to `categorySynonyms`: a synonym hard filters to one category.
  Tests: `DiscoveryModelTest`, `SearchSuggestTest`.
- **Layers 2 to 4 of the model** (same service, same nightly run; tests
  `DiscoveryLayersTest`):
  - *Related words* (`xs_directory_discovery_related`): words sharing profiles 3x+ more
    than chance, and the related word must be a topic (a service, tag or category name)
    on at most 5% of profiles, or imported template sentences relate everything.
    `Directory::index()` passes them as `expand` to `browse()`: an extra FULLTEXT OR in
    `applySearch()`, 1 point in `relevanceSelect()` (below every direct match). The page
    says "Also including profiles that mention …". Switch `search.discoveryExpand`.
  - *Spelling* (`xs_directory_discovery_vocab`, surface words from profiles, names and
    categories): only when a search finds nothing, words of 5+ letters the platform never
    uses, one edit (two at 8+ letters), same first letter. Results page retries and shows
    "Showing results for … Discover “…” instead" (`exact=1` skips it); the dropdown offers
    "Did you mean". Switch `search.discoverySpelling`.
  - *Visitors* (`DiscoveryEventService`, `xs_directory_discovery_events`): results page
    logs the words and result count; `directory.js` sendBeacons opened results to
    `POST /discovery/opened` (CSRF exempt, 30/min/IP, always 204). No IP, cookie or
    session; crawlers skipped; purged after 180 days by `discovery:learn` (Privacy, both
    sites). 3+ opened results (one per query, profile, day) with 30%+ share link a word to
    a category. Switch `search.discoveryLog`.
  - `/admin/discovery` (`AdminDiscovery`, `?site=app` for .app): searches that found
    nothing, most asked, learned links with Block (`xs_directory_discovery_blocks`, the
    only non cache table: removed now and never relearned).
  - Word columns are `utf8mb4_bin`: the default collation made "maré" and "mare" one key
    and rolled the nightly rebuild back.
- **Its own throttle key** (`directory-suggest-`, 30/min). Sharing `directory-index-`
  (60/min) would have a visitor's typing 429 their own results page. Over the limit
  answers an empty list with 200, as `AddressSuggest` does.
- `'suggest'` is in `listing_reserved_slugs()`, like `map` and `province`.
- **Every caller must pass ALL of the partial's variables**, even empty ones. CI4 renders
  the content view before the layout and shares one data array, so an omitted variable
  inherits the other box's value rather than the default — that is how the header bar,
  which must start empty everywhere, came back pre-filled with the current search.
- **Team-member rows (`type: person`) link to `/directory/{slug}#team-{member slug}`.**
  `team_member_anchor()` is the one definition of that fragment: the card id in
  `_team_panel.php`, the Person `@id` in `schema_local_business()` and the typeahead URL all
  call it. They are gated on a live badge like the team `EXISTS` in `applySearch()`.
- Two CSS rules exist only to stop the dropdown being clipped: `.hero-home` is
  `overflow-x-clip` (not `overflow-hidden`), and `.header-search` becomes
  `max-md:overflow-visible` once `.is-solid`.

## Consent: terms and marketing email (POPIA s69)

- The required signup box (`consent`) confirms authority to publish **and** accepts the
  Terms and Privacy policy. Marketing is a **separate, optional, unticked** box
  (`marketing_opt_in`). Never merge them: consent that is a condition of listing is not
  freely given, so it would not count.
- `MarketingConsentService` is the only writer of `terms_*` / `marketing_*` columns.
  They are deliberately not in `OWNER_EDITABLE`, and `DirectoryAdminService::upsert()`
  never writes them. Every change is stamped `marketing_consent_at` /
  `marketing_withdrawn_at` + `marketing_consent_source`.
- The duplicate-email signup branches do not touch consent, because anyone can type another
  person's address. `/manage/edit` uses the `marketing_present` marker.
- The marketing audience is `MarketingConsentService::audience()`, meaning opted in **and**
  `is_verified`. Get unsubscribe links from `unsubscribeUrl($id)`, which stores a random
  token on first use. `GET /unsubscribe/{token}` only asks; `POST` acts, and is
  CSRF-exempt for RFC 8058 one-click.
- `terms_version` records `Legal::LAST_UPDATED['terms']`. Bump that date whenever the
  terms change.
- **Mautic sync:** `syncToMautic()` runs on `verify()` and on every preference change,
  through `service('mautic')` (`App\Libraries\MauticClient`, which never throws). It adds
  opted-in, verified owners to the `mauticOwnerSegmentId` segment, and on opt-out
  removes them and sets DNC. It never lifts DNC. `php spark mautic:sync` re-sends
  everything, so use it after a Mautic outage and once after the first deploy. Leaving
  `directory.mautic*` unset turns the sync off, which is the local and test default.
  Tests inject a fake with `Services::injectMock('mautic', …)` and must `resetSingle`
  it in `tearDown`, because CIUnitTestCase doesn't clear mocks.

## Footer newsletter (Mautic)

- It posts to the **Local** Mautic form (`newsletterFormUrl` / `newsletterFormId` /
  `newsletterFormName` in `Config\Directory`, default id 2, alias `localnewsletter`). The
  marketing site uses form 1. The two used to share form 1, and that form's redirect
  sent marketing-site signups here.
- `Config\ContentSecurityPolicy` derives the `form-action` host from `newsletterFormUrl`.
  Without that host, Subscribe silently does nothing; this was the original bug.
- Mautic redirects back with `?subscribed=pending`, and `layouts/public.php` shows the
  "check your inbox" notice.

## Jobs board (`/jobs`)

Vacancies (`kind = job`) and "service required" requests (`kind = service`), in
`directory_job_posts`. Rules live in `JobBoardService`; `Jobs` is the controller for all of
`/jobs/*` and the owner routes under `/manage/jobs/*`. Limits are in `Config\JobBoard`.

- **Vacancies and replies are Verified Business features** (since 29 Sep 2026).
  `JobBoardService::canUseJobsFeatures($listing)` is the one rule: published, and either the
  badge is live (`listing_is_verified_business()`) or the badge is switched off (then Jobs
  reopens to every listing). It is checked in `submitForListing()` (kind=job),
  `respond()` (`not_verified`), `submitUnlisted()` (a vacancy whose poster email belongs to a
  listing without the badge is refused, so the public form is no way round it),
  `Jobs::ownerCreate()` / `ownerRenew()` (redirect to `manage/edit#get-verified`), the
  dashboard panel and the request page. **Requesting a service stays open to everyone.** A
  lapsed badge keeps its live posts to their closing date, but cannot post or renew
  vacancies. Terms §4 and §6 say all of this. Change them together.
- **Two posting paths, deliberately unequal.** A listed business posts from its manage
  session and goes live at once. Anyone else posts at `/jobs/post`, clicks an email link,
  then waits in `/admin/jobs` for approval and is emailed a reusable manage link
  (`/jobs/manage/{token}`, valid until a month after the closing date).
- **Editing:** `updatePost()` serves both paths. Listed businesses use
  `/manage/jobs/{id}/edit`; unlisted posters use `/jobs/manage`, which the confirm link
  opens straight away so they can edit while waiting for review. A clean edit to a live post
  stays live; a flagged edit goes back to the queue. A listed post always keeps the
  listing's name.
- **`scamFlags()`** matches `Config\JobBoard::$scamPhrases` ("registration fee", "WhatsApp
  only"…). A match sends even a listed business's post, or an edit, to the queue. It never
  rejects on its own. Three reports from distinct IPs (`directory_job_reports`) also hide a
  post for review. Below that nobody is emailed at the time. `reportDigest()`, run by
  `jobs:expire`, sends the admin one email a night covering every report where
  `digested_at` is NULL, and stamps them only if the send succeeds. The poster is
  never told about a report.
- **Every post closes** (`valid_through`, default 30 days, max 60). The list and post pages
  check the date themselves; a closed or expired page answers **410**. `php spark
  jobs:expire` (daily cron) sends renew reminders, marks posts expired, deletes posts never
  confirmed, and wipes poster contact details 12 months after a post ends.
- **Google for Jobs:** `jobPostingSchema()` emits JobPosting JSON-LD only for a live `job`.
  Service requests have no schema, are `noindex`, and are left out of the sitemap. Keep the
  markup matching the visible page; Google's manual actions remove every job on the site.
- **No CVs are stored.** Applications and replies are relayed by `Mailer` with Reply-To set
  to the sender (`emails/job-relay`). The poster's address is never rendered.
- **Service requests:** only published listings can reply (`respond()`), once each, at most
  `maxResponses` (5).
- **Lead alerts:** when a service request goes live, `alertMatchingBusinesses()` emails up to
  10 listings in the same category and province (published, email confirmed,
  `job_alerts = 1`). Badge holders come first, then `quality_score`. A listing that cannot
  reply gets the same alert as a teaser: the button is "Get verified to reply" (to
  `/verified`), with the request link as text. No listing gets more than
  3 in 24h. `directory_job_alerts` has a unique (post, listing) key, so re-runs and renewals
  never double-send, and the cap of 10 is per request, not per run. Alerts are on by
  default. The email's stop link (`/jobs/alerts/off/{token}`, GET asks, POST acts) and the
  dashboard checkbox (`POST /manage/jobs/alerts`) turn them off. `job_alerts` is not in
  `OWNER_EDITABLE`.
- **Every board email renders with `saveData => false` and defaults for optional keys.**
  CI4 keeps view data between renders, so without that a notice with no button or stop link
  inherits the previous email's.
- **Feature-test POSTs meet the real CSRF filter.** Send `[csrf_token() => csrf_hash()]`
  (see `JobBoardFlowTest::csrf()`).

## Comparison page (`/compare`)

The public table of WebScheduler Local next to Google Business Profile, LinkedIn and
the SA directories (one column, with notes naming which directory does what).

- **Defaults** are in `Config\Comparison` (rows, columns, `checkedOn`, plus the competitor
  source URLs in its docblock). **Edits** come from `/admin/comparison` and are stored whole,
  as JSON, in the `comparison_table` settings row. A saved copy wins over the config; "Reset
  to defaults" writes an empty value, which keeps who and when. So a change to the config
  shows nowhere that someone has already saved the table.
- `ComparisonService` validates both paths the same way: statuses from the enums, plain
  text only (HTML is refused), length caps, at most 30 rows, and a real `Y-m-d` checked-on
  date. A damaged stored copy falls back to the defaults. Every string is escaped in the view.
- Our column shows Free / Verified on every row and must match the gating (see the
  Verified Business table below). `{gallery}`, `{locations}`, `{team}` and `{price}` are
  filled in at render from the service constants, so an edited copy never freezes a cap.
  `ComparisonPageTest` pins the default tiers.
- Keep the honest rows: booking (a link, nothing built in), and
  the `/verified` rule that qualifications are not checked.
- Chip classes in `compare.php` are written out in full. A concatenated class name is
  purged from the Tailwind build.

## Customer reviews (`/reviews`, added 3 Oct 2026)

1–5 stars plus text on any business profile. Rules are in `ReviewService`, the only writer
of `directory_reviews`, `directory_review_reports` and the listing's `review_count` /
`rating_avg`. Limits are in `Config\Reviews`. The controller is `Reviews` (public
`/reviews/*` and owner `/manage/reviews/*`), and the admin queue is `/admin/reviews`.

- **Two gates:** the email confirm link (`unverified → pending`) and then admin approval
  (`→ published`). A review is never public before both.
- **Free for every profile**, including owner replies. Not a Verified Business feature.
- **Ratings never affect ordering.** They are not a key in `browse()` and are not in
  `ListingQualityService`. Same rule as the badge row below.
- **One review per person per business:** unique (`listing_id`, `email_hash`). A duplicate,
  or the owner's own email, gets the **same success message** and stores nothing, as with
  `submitPublic()`. A still-unconfirmed draft can be rewritten.
- **Privacy:** the page shows "Thandi M." only. `forProfile()` passes an `author` key and
  strips the name, email, hashes and token before anything reaches the view. The JSON-LD
  reads the same copy, so `aggregateRating`/`review` always match the page and are absent
  with zero reviews.
- **Spam defence:** links and phone numbers are refused (`textProblem()`). The form carries
  a signed timestamp (`Reviews::formStamp()`), **not** a session stamp, because profiles
  are the most-crawled pages. Honeypot `company_website_hp`. Throttles: `review-ip-` 3/h,
  `review-to-` 2/day, `reviewreport-ip-` 10/h.
- **Reports:** 3 distinct reporters send a published review back to `pending`, which also
  takes it out of the totals. The owner counts as one reporter. In the queue, a review
  that was once live gets **Hide**, never **Reject**.
- **Owner replies** go live at once; `replyFlags` phrases only email the admin, who can
  remove the reply.
- **Totals** are written with the query builder in `recalculate()`. The columns are
  deliberately not in the model's `allowedFields` or `OWNER_EDITABLE`.
- `php spark reviews:prune` (cron 04:00): deletes unconfirmed reviews after 7 days, wipes
  name and email from rejected/hidden ones 30 days after the decision (the hash stays),
  and sends the nightly report digest. Terms §7 and Privacy §9 promise exactly this.
- Tests: `tests/database/ReviewFlowTest.php`.

## Recommend a business (`/recommend`)

A visitor names a business that should be listed. The rules are in `ReferralService`, and
the rows are `directory_referrals`.

- **The form never emails the business.** It stores a row and emails the admin. Only
  **Invite** at `/admin/referrals` contacts the business: one email with
  `/add-profile?invite={token}` (or `/add-profile/verified?invite=` while the badge is on sale) and a "don't contact me again" link. There is one invite
  per address, however many people recommend it (`inviteBlocker()`). Anything that emails a
  business straight from the public form turns our domain into a way to mail any address.
- **The invite pre-fills signup.** `Listing::renderForm()` maps the token through
  `prefillFor()` and carries it as a hidden `invite` input. `store()` calls
  `attachListing()` after the save. `verify()` calls `markListed()`, which also matches
  by email, so a business that listed without clicking the invite still counts. It
  emails the referrer only when `notify_referrer` is set. Both hooks swallow their own
  errors, so a referral can never cost anyone their listing.
- **Suppression is permanent and hashed** (`directory_invite_suppressions`). The stop
  link is GET-asks, POST-acts, and it is **not** CSRF-exempt, because the invite has no
  List-Unsubscribe-Post header.
- **Retention:** `php spark referrals:prune` (daily cron, in `deploy/cron/webscheduler`) wipes
  contact details 12 months after submission, or 30 days after dismissal. The privacy
  policy promises exactly this.
- Emails go through `emails/job-notice` with an `$eyebrow`, and render with `saveData => false`.
- Tests: `tests/database/ReferralFlowTest.php`.

## Partner Program (`/partners`, added 9 Oct 2026)

Affiliates who promote the site with a tracked link and earn a share of what
referred businesses pay. Rules are in `PartnerService`, the only writer of the
`directory_partner*` tables and of `xs_directory_listings.partner_id` /
`partner_attributed_at` (query builder; deliberately not in the model's
`allowedFields` or `OWNER_EDITABLE`). Settings are in `Config\Partners`
(`.env` as `partners.*`); a per partner rate on the row beats the default.

- **Flow:** apply at `/partners` → admin approves at `/admin/partners` (emails
  the link and a sign in link) → `/p/{code}` or `?ref={code}` on any GET
  (`App\Filters\PartnerRef`, after filter) sets the `ws_partner` cookie, last
  click wins → `Listing::store()` calls `attribute()` after the save → each
  COMPLETE payment calls `recordCommission()` after `recordPayment()` commits.
- **Attribution refuses:** an unapproved partner, a profile older than
  `attributionWindowMinutes` (submitPublic() returns an EXISTING profile's id
  for a known email), a profile already credited, and the partner's own email.
- **Commission:** `amount_gross × rate`, only for an approved partner, only for
  payments at or after `partner_attributed_at`, within `commissionMonths` of
  `activated_at`. The unique key on `pf_payment_id` is the replay guard. Held
  `holdDays`, then `available`.
- **Nothing here may cost a profile or a payment.** Both hooks swallow their
  failures; `php spark partners:sweep` (cron 03:50) releases holds, rebuilds
  missed commission from the last 7 days of verification events, and deletes
  declined applications after 12 months (privacy §10 promises it).
- **Clicks** are daily totals only; link preview fetchers (WhatsApp, Facebook…)
  are not counted (`NOT_A_CLICK`).
- **Payouts** are manual: `/admin/partners/payouts` lists partners owed at least
  `minimumPayout`, with the decrypted account; "Mark paid" needs the EFT
  reference, settles every available commission in one transaction and emails a
  statement. A paid commission cannot be voided.
- **Bank details** are encrypted with the CI4 Encrypter. With no
  `encryption.key` (the local default) saving is refused, never stored plain.
- **Dashboard** at `/partners/dashboard`: emailed single use sign in link
  (1h), session key `partner_id`, like `/manage`.
- Terms §19, privacy §10, and the cookie policy describe all of this.
- Tests: `tests/database/PartnerProgramTest.php`.

## Internal documents (`/admin/documents`)

PDFs for staff only (the Partner Program guide, the founder's proposal). Files
in `resources/documents/`, registered in `Config\AdminDocuments`; the build
copies them into `directory-app/` outside the web root and fails on a missing
one. `Admin::document($key)` streams by registry key, never by path, with the
same nosniff/no-store/locked CSP as verification documents. Tests:
`tests/database/AdminDocumentsTest.php`.

## The profile page (`directory/show.php`)

Built from partials so the profile, cards and schema read the same data. Every row goes
through `listing_public_view()` first (hidden addresses).

- **Header:** a Yelp style photo band (name, category, rating and hours over an edge to
  edge photo strip; one swipeable photo on a phone), or the light `.profile-head` when
  there are no photos and no video. Shared pieces in `_profile_head_parts.php` (`pills`,
  `locations`…). Arrows reuse the home showcase controller (`data-showcase-step="page"`).
- **Pills:** Featured, the Verified pill (`verified_badge_pill()`) or Plus
  (`plus_badge_pill()`), a sky "We're hiring" pill when the profile has live vacancies
  (one: "Apply for {title}"; more: "See N open positions" → `#open-positions`).
- **Today's hours line** ("Open now · 08:30–16:30 today · See hours") in the profile's own
  timezone (`hours_today_line()`, `hours_context()`).
- **Video** (badge only, `ListingVideoService` + `video_helper.php`): a YouTube or Vimeo
  link checked by oEmbed before the save; first tile of the band, plays in the lightbox;
  owner options autoplay (silent loop) and start with sound. Our own pause and sound
  buttons drive the players by postMessage. CSP admits `player.vimeo.com`.
- **Photo alt text** is built at render by `listing_image_alt()` ("Name, Category in
  Suburb, City" for the lead). `og:image` falls back to the first photo without a logo.
- **Brands we carry** strip under the description (`ListingBrandService`, free, up to
  `MAX_BRANDS` 20, name plus logo, no links; greyscale loop over 4 logos, pause button,
  still under reduced motion). Discovery matches brand names by prefix. A new vertical's
  `order` must include `brands`.
- **Locations:** right column cards under Contact (`<details class="panel loc"
  id="location-N">`, the primary open and carrying `id="hours"`), header links with open
  or closed dots; `profile_location_label()`.
- **Services and prices, features, facets, menu file, reviews, team, open positions,
  service area, map** each have their own `_*.php` partial in `app/Views/directory/`.
- Public holidays: see below.

## Signup form (`/add-profile`) and the owner form

- `directory/form.php` (signup) and `manage_edit.php` (owner) share `_form_fields.php`.
  Five numbered colour cards, one list: `listing_form_steps()` (blue, teal, violet,
  amber, pink; class names written out in full so Tailwind keeps them). Each card is a
  `<section id="form-step-N" data-form-step>`. The tracker is `_form_steps_nav.php`: in
  the sticky score bar on `/add-profile`, in `.form-steps-dock` with a Save button on
  `/manage/edit`; none on admin edit. JS marks accordions `is-todo` / `is-done`.
  A new section needs an entry in `listing_form_steps()` and a wrapper in
  `_form_fields.php`; `SignupModesTest::testTheFormIsFiveNumberedSectionsWithATracker`
  pins the structure.
- **Live score bar:** `POST /add-profile/score` (read only, CSRF exempt) returns the
  strength panel for what is typed so far.
- **Description helper:** `/description/insights` and `/description/prompt`
  (`Services/Description/*`, hints rebuilt nightly by `directory:insights:build`) and a
  "write it with ChatGPT" link. There is no AI call on our side.
- **Repeaters** (`data-repeat`, branches, holiday rows, services) are nesting safe
  (`repeatOwn`/`repeatItems`, `data-repeat-placeholder`).
- The profile setup offer line (`_profile_setup_offer.php`) shows on both forms.

## Profile quality bars (`ListingQualityService`, `Config\Directory`)

The score never gates publishing (required fields alone give about 25). Bars, each
overridable in `.env` as `directory.*`:

- `indexMinQuality` 40 **and** `indexMinDescription` 50 characters: below either, the
  profile is `noindex, follow` and out of the sitemap (`listing_is_indexable()`). Set
  `indexMinDescription = 0` to switch that half off (production does, since 25 Oct 2026).
- `recentMinQuality` 55: the home "Recently added" strip.
- `qualityTarget` 65: shown on the strength panel; `directory:quality:nudge` (07:00)
  emails opted in owners once, 3 to 17 days after going live, if still under it. Admin
  sees the score, a checklist, and an "Email suggestions" button
  (`ProfileNudgeService::sendNow()`).
- `directory:quality:recalculate --stale` (03:15) rescores. Holiday answers count inside
  the "Features and details" cap so no score drops under a bar.

## Addresses that geocode badly (`ListingGeocoder`)

- Lat/lng are nullable, a failed lookup still saves, the owner can drag the pin, and plain
  discovery never drops a profile without a pin. Postal code is optional (checked by the
  country's `postalPattern` when given).
- `geocode_precision` suburb or city draws a shaded circle (`map_area_radius()`, 1500 m
  or 6000 m) instead of a pin, a hollow dot on the results map, and no `geo` in JSON-LD.
- A failed lookup after an edit keeps the old pin as `geocoding_status = 'stale'` only when
  suburb, city, province, region and country are unchanged; a coarser result never replaces
  a street pin in the same area. Near me sorts profiles without a distance last.
- Weekly retry: `directory:geocode --status failed,stale --limit 100` (Sunday 04:10).
  Even `--dry-run` makes real, billed Mapbox lookups.

## Public holiday hours

- Owners answer once for all public holidays plus exceptions per holiday; stored in the
  `trading_hours` JSON under a `holidays` key (`hours_decode()` still returns seven days).
  Model callbacks derive `holiday_hours` and `holiday_open_dates` for SQL.
- With no answer a profile says "Public holiday: hours may differ" and never claims Open
  or Closed. Google gets special opening hours for answered holidays in the next 60 days.
- Calendars per country (see `two-sites`). South Africa's is coded in `PublicHolidays`
  (Sunday to Monday rule); declared one off days come from `/admin/settings`
  (`extra_public_holidays`, `Admin::saveHolidays`).
- `directory:holidays:remind` (07:05) rolls open dates forward and emails opted in,
  unanswered owners in the 10 days before a holiday, at most once per 90 days
  (`--refresh-only` to skip the emails). Discovery has an "Open on public holidays" chip.

## Careers (`/careers`) and screening questions

- Our own hiring, separate from the Jobs board on purpose, co.za only. `CareersService`;
  tables `directory_career_roles` / `directory_career_applications`; admin at
  `/admin/careers` (`AdminCareers`). CVs (PDF or Word) are stored privately in
  `writable/careers/` (2770) and streamed to the admin; `careers:purge` (03:55) deletes
  them and applicant details after the retention period (privacy §11).
- The applicant gets a receipt and an email per status change (`statusEmail()`); receipts
  echo nothing typed except a letters only first name. JobPosting schema per role
  (`schemaTitle()` strips the city from the title).
- **Screening** (`Libraries/Screening`, `ScreeningBank`, `Config\Screening`): a role or a
  vacancy picks a level (Basic, Intermediate, Advanced); each applicant gets one reading,
  one language and one numbers question with fresh numbers, a soft timer per kind
  (late answers are flagged, not dropped), page leaves counted, copy blocked. Attempts
  live in the session. Nobody is blocked; the reviewer sees score, time and leaves. The
  applicant receipt never shows the score. Never reuse a question id. Jobs board vacancies
  can use it too (premium profiles; answers relayed, never stored).
- **Apply once** (`ApplicationOnceService`, `directory_application_keys`): the same
  vacancy or role refuses a second application by email **or** phone (HMAC keys, claimed
  before sending, released if the send fails). Forms carry `data-submit-once`. CI4
  gotcha: inside `transBegin()` a duplicate key insert returns -1 instead of throwing.

## Profile setup service (`/profile-setup`)

We build the profile for a once off fee: R250 on co.za (`Config\Directory::$profileSetupAmount`,
3 days), `profile_setup_usd` on .app (charged in rand). `ProfileSetupService`, orders in
`xs_directory_profile_setup_orders` (reference `ps-…`), PayFast once off checkout, its own
ITN at `POST /payfast/setup-notify` (`ProfileSetupNotify`, the same four checks as the
subscription ITN), queue at `/admin/profile-setup`. If a paid order never shows as paid,
look at the ITN rejection table and `Profile setup ITN:` log lines first.

## Content pages and analytics

- `/resources`: the list is hardcoded in `Contact::resources()`; PDFs in
  `public/assets/documents/` and `public/assets/playbook/`, linked with `?v=<filemtime>`.
  Two PDFs have no builder in the repo: replace the file by hand.
- `/media`: logo downloads (`public/assets/brand/`), embed snippet, usage rules. The
  Verified badge is shown but never offered as a file; Terms §20 covers misuse.
- `/compare`, `/faq`, `/company`, `/verified`, `/plus`: see their sections.
- **Analytics:** GA4 (`Config\Directory::$analyticsId`, per site through `Config\Sites`)
  under Consent Mode, and Microsoft Clarity (`clarityId`), which is **not loaded at all
  until the cookie banner's Accept** and never on `/admin`. Links with
  `data-track="name"` send a GA4 `{name}_click` event with `data-track-label` as its `cta`
  parameter, e.g. the pricing cards' `pricing_cta_click`.

## Profile statistics (`ListingStatsService`, since 30 Oct 2026, unreleased)

Daily totals per profile in `xs_directory_listing_stats` (primary key listing_id + day,
UTC days): `views`, `appearances`, `taps_call|whatsapp|website|directions|booking|email`.
The service is the only writer; every write is one `INSERT … ON DUPLICATE KEY UPDATE`
and never throws. Switch: `directory.profileStats`.

- **Views:** `Directory::show()`, GET only, once per IP per profile per 30 minutes (a
  throttler bucket on `md5(IP|id)`, cache only, never stored). Previews never call
  `show()`.
- **Appearances:** `Directory::countShown($result)` before each listing page's view
  (results, landing, province, country, venue). The home carousel and the map JSON do
  not count.
- **Taps:** `[data-stat]` links in `_contact_panel.php` inside `show.php`'s
  `[data-stat-slug]` root (absent on previews); `directory.js` sendBeacons
  `POST /stats/tap` (`Stats::tap`, CSRF exempt, 30/min/IP, always 204, published
  profiles on this site only). Email is counted server side in `Directory::email()`.
- **Not counted:** crawlers (`DiscoveryEventService::isBot()`), the admin, and the
  profile's own owner. The session is read only when one already exists, so counting
  never starts a session or sets a cookie.
- Retention 25 months (`directory:stats`, Privacy "Profile statistics" on both sites).
- Tests: `tests/database/ProfileStatsTest.php`. The owner dashboard, Google data and
  admin stats are later phases (plan in memory `profile-stats-plan`).

## Verified Business — the one paid feature

A monthly badge on an otherwise free listing — R29.99 at the time of writing, but the live
figure comes from `DirectorySettings::badgePrice()` (see precedence below), so read it there
rather than trusting this number. Everything else on the site is free and must stay free;
the FAQ, T&Cs and signup copy all promise that in writing.

**Documents are what apply, not any plan field.** `Listing::store()` starts an application
when `resolveVerificationDocuments()` returns files — it never reads the form's `plan`
input, which only decides whether the "we didn't receive both documents" notice shows. So a
form that says "Free" while carrying attached files still submits an application. Anything
that switches a person back to free must clear the file inputs, or the UI is lying about
what is about to happen.

**The listing is saved before the documents are touched**, deliberately, and the comment in
`store()` says why: *a rejected document must never cost someone their listing.* Keep any
new validation out of that path — tell the person, don't block the save.

What the badge actually gates. On .app the same premium rows are unlocked by **Plus**
instead (`listing_has_premium()` = badge or Plus, see `two-sites`); the green badge and
the profile video stay badge only:

| | Free | Verified |
|---|---|---|
| Publish, search, profile, gallery (8), self-service editing | yes | yes |
| Green badge on profile + every search result | — | yes |
| Team members (`TeamMemberService::MAX_MEMBERS`, 12) | — | yes |
| Extra branches (`PracticeLocationService::MAX_LOCATIONS`, 6) | — | yes |
| Matching a search for a team member's name/role/qualification/specialisation, and the typeahead's "Person" rows | — | yes |
| Profile video (YouTube or Vimeo, `ListingVideoService`) | — | yes |
| Posting job vacancies (and renewing them) on the Jobs board | — | yes |
| Replying to service requests | — | yes |
| Lead alerts for service requests | teaser ("Get verified to reply") | yes, alerted first |
| Requesting a service (Jobs board) | yes | yes |
| Customer reviews, and replying to them | yes | yes |
| **A higher position in results** | **no** | **no** |

That last row is load-bearing. Discovery results are ordered by `is_featured` (admin
only), then `quality_score` (profile completeness, `ListingQualityService`), then newest;
near me puts distance first. **Nothing behind the badge or Plus may score**
(`ListingQualityTest::testPayingForTheBadgeDoesNotMoveTheScore`), and ratings are not a
key either. Nothing in any view may hint that paying moves a business up — it would be
false today and a promise we'd then have to keep. (The plan cards used to
carry a ✗/✗ "A higher position in the search results" row saying so; the owner removed it
on 29 Sep 2026. The rule stands without it. The cards now carry a "Post jobs" row, ✓ on
**both** cards, because every listing gets the Jobs board.)

- **`VerificationService`** — `isEnabled()` (just `DirectorySettings::badgeEnabled()`; when
  false the offer must not render at all), `canTakePayment()` (adds "PayFast is
  configured" — an approved application without it waits for manual/EFT activation),
  `monthlyAmount()`, and `applyPaidUntil()`, the **only** writer of
  `xs_directory_listings.verified_until`.
- The public badge is a render-time date compare on `verified_until` —
  `listing_is_verified_business()` in `app/Helpers/directory_ui_helper.php`. There is no
  boolean to keep in sync, and in particular **`is_verified` is not it** (that one means the
  signup email was confirmed — see Data model).
- **Every pill renders through `verified_badge_pill()`** (search cards, job cards, job page). It
  links to `/verified` and explains itself on hover/focus with `VERIFIED_BADGE_EXPLAINER`
  ("Documents checked by WebScheduler Local"), which the profile seal, plan cards and pitch
  also quote. Pure CSS (`.verified-tip`); the tooltip is `display:none` while hidden because an
  invisible box would still give a phone page a sideways scroll.
- **The price is snapshotted** onto `directory_verifications.amount` at application time, so
  changing the price never reprices existing subscribers.
- **Benefit copy lives in two partials that must change together**:
  `_verification_pitch.php` (prose — `/verified` and the owner dashboard) and
  `_plan_cards.php` (the ✓/✗ matrix on the signup form). Deliberately not merged; each
  docblock points at the other. Caps come from the service constants in both, never typed.

### Settings precedence: DB (`name@app` on .app) → `.env` → config default

`DirectorySettings::badgePrice()` / `badgeEnabled()` read `xs_directory_settings` first
(on .app a `{name}@app` row wins over `{name}`), then `directory.verifiedMonthlyAmount` /
`directory.verifiedBadgeEnabled` in `.env`, then the committed default in
`app/Config/Directory.php`. The table's columns are `name` and `value`. **A DB row silently wins**, so editing
`.env` does nothing once `/admin/settings` has ever been saved. `/admin/settings` renders
`priceSource()` specifically to answer "I changed it and nothing happened".

### Signup form: one form, three modes: Verified leads

Verified Business is the offer we lead with (on co.za). `/add-profile` and
`/add-profile/verified` are the **same view, the same fields and the same POST target**
(`/add-listing*` 301s to them).
On .app the badge is off, so the form is the plain free form plus the Plus comparison
(`_plus_compare.php`). `Listing::renderForm($plan,
$verifiedOnly)` serves every mode:

| Visit | Mode |
|---|---|
| `/add-profile/verified`, or any `/add-profile*` with a campaign source in the session | **verified-only**: the Verified card alone, documents open, no Free card, no "Remove" link, no `data-plan-cards` (so the picker JS is off) |
| `/add-profile` with no source (typed, or any of the site's own buttons) | both cards, **Verified first and preselected** |
| `/add-profile?plan=free` (direct only) | both cards, Free picked |

- **The Free Listing must stay available to anyone who came straight to the site.** The
  site's own buttons (header "Get verified", footer, home/landing/province/venue/categories/
  404/contact/jobs) point at **`/add-profile`**, which decides the
  mode itself. A direct visitor gets both cards, Verified first. A visitor with a campaign
  source gets verified-only. They all use `signup_cta()` in `directory_ui_helper.php`,
  which also falls back to the "free" wording when the badge is off. Do not point them at
  `/add-profile/verified`: that would hide Free from direct visitors, which the owner
  explicitly does not want. The header button reads "Add your business" on both sites.
- **Only our outbound links go straight to verified-only**: referral invites
  (`add-profile/verified?invite=`) and the marketing-site footer (`?via=marketing-site`).
- **Text that talks about listing *for free* keeps `/add-profile`**: the FAQ answers,
  Terms, privacy policy and the "is it free?" line on `/verified`. Pointing those at a page
  with no Free card would contradict the sentence around the link.
- **The verified-only page must keep the card's small print** ("A South African listing is
  free either way, and stays free"). The Terms and FAQ promise it, and `store()` still saves
  the listing when no documents are attached. That line is what keeps the mode honest
  (Consumer Protection Act, misleading representations). Do not remove it.
- **Campaign source:** `App\Filters\SignupChannel` (global, GET) stores `?via=<a-z0-9->`
  or `invite` in the session (`signup_via`). The first one wins for the visit. `store()`
  writes it, or `site` / `direct` from the posted `form_mode`, to
  `xs_directory_listings.signup_source`. It is always overwritten server-side and never
  taken from the form. It is admin-visible (edit page) and never owner-editable.
- **After publishing**, `Directory::verify()` lands a listing with no application on
  `manage/edit#get-verified` (the id on `_verification_panel.php`) while the badge is on
  sale, and a profile abroad without Plus on `manage/edit#get-plus` (`_plus_panel.php`).
  The confirm email's badge section says so; it has deliberately no second button.
- An unverified profile shows "Is this your business? Get the Verified Business badge",
  linking to `/verified`, while the badge is on sale. It is worded as an offer to the owner,
  never as a warning that the business is unchecked.
- Tests: `tests/database/SignupModesTest.php`.

Three rules from the original two-state design still hold:

1. **Never render both variants.** `_verification_fields.php` would appear twice, giving the
   form two inputs named `verify_doc_registration` and two elements sharing an id.
2. **The card buttons are real links** carrying `data-plan-pick`: `/add-profile`
   (Verified) and `/add-profile?plan=free`. The picker in `public/assets/directory.js`
   intercepts them, swaps in place, and on `popstate` reads the `plan` query parameter
   (default Verified).
3. **`applyPlan()` writes the hidden `plan` input before setting `details.open`.** The
   `toggle` listener compares against that input to tell a person opening the panel from
   `applyPlan` opening it. Swap the order and the two handlers drive each other in circles.

Markup contract: `data-plan-cards`, `data-plan-card="free|verified"`, `data-plan-pick`,
`data-plan-input`, `data-plan-cleared`, `data-verify-offer`. The "Remove" control is just
another `data-plan-pick="free"` link, so it cannot drift from the Free card.

After a failed submission `redirect()->back()` uses the **Referer**, which a `pushState`
plan switch does not change. So `renderForm()` lets a posted `plan` outrank the route
(except in verified-only mode, which has nothing to pick).

## Local environment

- `.env` needs `CI_ENVIRONMENT=development` — CodeIgniter defaults to `production`, which
  turns on `Config\Cookie::$secure` and throws `SecurityException::forInsecureCookie` over
  plain HTTP.
- `app.baseURL` must match whatever port you serve on, or assets 404.
- `app.devHostnames` is a comma-separated list of other hosts this machine answers on —
  LAN IPs and the Bonjour `.local` name — for opening the dev server from a phone or
  another PC. `Config\App::__construct()` reads it into `$allowedHostnames` outside
  production, which makes `base_url()` follow the host the browser used. Without it every
  asset URL stays absolute on `localhost`, and since CSP is enforcing in development with
  `defaultSrc 'self'`, the browser blocks them as cross-origin and the page renders
  unstyled. Set it per machine; `.env` is gitignored, and the IPs are DHCP leases.
- First-time setup: `php spark migrate && php spark db:seed DirectoryCategoriesSeeder`.
- `directory.adminEmail` (new-listing notifications), `directory.adminPasswordHash` (admin
  login — generate with `php spark directory:adminhash`, prints the value to set).
- `directory.mapboxToken` — Mapbox token for address lookup and typeahead. Optional; see
  "Geocoding provider" above.
- `directory.mapTileKey` — CARTO basemap key, free from https://carto.com/basemaps/apikey
  (no account, 5M tiles/month, commercial use allowed). `Directory::mapTileUrl()` appends
  it as `?key=`. **Its absence is invisible**: CARTO returns tiles stamped "API KEY
  REQUIRED" as a valid HTTP 200 PNG, so there is no error, no failed request, no CSP
  violation and nothing in the logs — and a wrong key is byte-identical to no key. Check
  a map by eye: neither the status code nor the byte size will tell you, since a real
  tile can be *smaller* than a watermarked one (the watermark is extra pixels over the
  same map — 2627 bytes keyed vs 3965 unkeyed on one sparse rural tile).

### Keys that must NOT survive the trip to a production `.env`

A production env file is usually born as a copy of a working local one, and these four
are the ones that ride along silently. Checked against `.env.production.example`:

| Key | On the server |
|---|---|
| `directory.adminPassword` | **absent** — cleartext, deprecated code path. Setting `adminPasswordHash` does *not* neutralise it; it must be deleted |
| `database.tests.*` | **absent** — test DB credentials |
| `encryption.key` | **present** — `php spark key:generate` |
| `CI_ENVIRONMENT` | `production` (locally it must be `development`, see above) |

Production `.env` also carries `sites.app.enabled = true` and `sites.app.analyticsId`
(the .app switch, see `two-sites`) and `directory.indexMinDescription = 0`.

### One checkout, one server, one database

Since 29 Oct 2026 there is exactly one of each. Sessions take turns.

- **Code:** this checkout. `git switch -c <branch>` for a change, back to `main` when it
  is merged. No git worktrees.
- **Server:** http://localhost:8095, run by launchd (`deploy/dev/`,
  `scripts/dev-service.js`). It runs `npm run list:hot`: `php -S`, Tailwind watch and
  live reload. `npm run dev:install` once per machine, `dev:status` to check,
  `dev:restart` after `composer install` or a `.env` edit, logs in
  `~/Library/Logs/webscheduler-dev.log`. It serves whatever branch is checked out. Never
  start a second server or port.
- **Databases:** dev `webscheduler_directory`, tests `webscheduler_directory_tests`.
  A branch's migration runs against the dev DB with `php spark migrate --all`.
- **.app locally:** set `sites.app.enabled = true` and `sites.app.host = app.localhost`
  in `.env` and `npm run dev:restart`. co.za stays `localhost:8095`, .app is
  `app.localhost:8095`. Put it back when done.

Other ports: 8096 marketing `site:serve`, 8097 `site:preview` (marketing site only,
started on demand).

## Commands

| Command | Does |
|---|---|
| `npm run list:css` / `list:dev` | Tailwind → `public/assets/directory.css` (once / `--watch`) |
| `npm run list:serve` | Serve at http://localhost:8095 — **must** go through `vendor/codeigniter4/framework/system/rewrite.php`, not `public/index.php`, or `/assets/*` 404s and the app renders unstyled. Binds `[::]`, not `localhost`, so the LAN can reach it — `[::]` and not `0.0.0.0` because the Bonjour name resolves to IPv6 and an IPv4-only bind would refuse it |
| `npm run list:hot` | **All three at once** — Tailwind `--watch`, `php -S` :8095, and the file watcher. Ctrl-C stops the lot. The usual way to work |
| `npm run list:watch` | The file watcher alone, to pair with an existing `list:serve` |
| `npm run list:build` | `list:css` + assembles the deploy bundle in `dist/listing/` |
| `npm run mail:dev` | Mailpit (idempotent adopt-or-spawn) — SMTP :1025, inbox http://localhost:8025 |

Spark commands (run from the app root; `--dry-run` where offered):

| Command | Does | Cron (UTC) |
|---|---|---|
| `directory:verifications:sweep` | lapse expired badge and Plus subscriptions, renewal reminders | 03:00 |
| `directory:quality:recalculate [--stale]` | rescore profiles | 03:15 |
| `directory:insights:build` | rebuild the description hints | 03:20 |
| `discovery:learn [--show <word>]` | rebuild the discovery model (categories, related words, vocabulary, clicks; per site) and purge old searches | 03:25 |
| `jobs:expire` | expire posts, renew reminders, retention, report digest | 03:30 |
| `directory:stats` | delete profile statistics older than 25 months | 03:40 |
| `referrals:prune` | referral retention | 03:45 |
| `partners:sweep` | release held commission, rebuild missed ones | 03:50 |
| `careers:purge` | CVs and applicant retention | 03:55 |
| `reviews:prune` | unconfirmed reviews, reviewer retention, digest | 04:00 |
| `directory:quality:nudge` | the one off "improve your profile" email | 07:00 |
| `directory:holidays:remind [--refresh-only]` | roll holiday dates, remind owners | 07:05 |
| `directory:geocode [--status failed,stale] [--limit 100]` | pin profiles (billed lookups) | Sun 04:10 |
| `directory:countries:backfill` | fill `country_code` from `country` | once per release that needs it |
| `directory:audit-text [--all]` | report names and descriptions that break the rules | |
| `directory:venue-assign` | put shops into a venue | |
| `mautic:sync` | resend every owner's marketing state to Mautic | |
| `directory:adminhash` | hash for `directory.adminPasswordHash` | |

The cron template is `deploy/cron/webscheduler`; the live copy is
`/etc/cron.d/webscheduler`, installed by hand. A new scheduled command needs both.

`npm run list:hot` is the one-command version of `list:serve` + `list:dev`, and adds
hot reload: save a view, helper or controller and the browser reloads itself; save
`resources/directory.css` and the compiled stylesheet is swapped in **without
navigating**, so scroll position and the scrolled header state survive.

Two things worth knowing about it:

- The reload client is only served while the watcher is running — it writes
  `public/assets/.dev-reload.json` on startup and deletes it on exit, and
  `layouts/public.php` loads the client only when that file exists. So plain
  `list:serve` behaves exactly as it always did.
- Both `list:serve` and `list:hot` are reachable from other devices on the network;
  `list:hot` prints every URL it can be opened on at startup. That needs the host in
  `app.devHostnames` too — see Local environment above.
- The 500ms poll means Playwright/Puppeteer `waitUntil: 'networkidle'` never
  resolves against the dev server. Use `'load'` or `'domcontentloaded'` in local
  browser scripts. Production is unaffected — the client is development-only and
  is excluded from the deploy bundle.

Pair `list:serve` with `list:dev` in a second terminal if you want the old
two-terminal split without hot reload.

## Tests

- `php vendor/bin/phpunit` runs everything (about 1,600 tests) in about 30 seconds.
  Iterate with `--filter` anyway.
- **DB test classes use `Tests\Support\FreshDatabase`, never `DatabaseTestTrait`
  directly.** `TestSchema` drops every table and migrates once per run (refusing any
  database whose name does not end in `_tests`), then empties every table before each
  test in one transaction, and the throttler service is reset (it otherwise keeps its
  buckets across tests). AUTO_INCREMENT is not reset: use the id an insert returns.
  Until 29 Oct 2026 every test re-ran all migrations and a run took 20 to 40+ minutes.
- The bootstrap (`tests/_support/bootstrap.php`) pins `sites.app.*` and resets the index
  gates (`directory.indexMin*`) to their code defaults, so the dev `.env` cannot change
  test results.
- `tests/database/` needs the `tests` DB group; `tests/unit/` does not.
- 404s are responses (`assertStatus(404)`), feature POSTs meet the real CSRF filter
  (send `csrf_token() => csrf_hash()`), the settings cache must be dropped in setUp
  (`(new DirectorySettings())->forget()`), and mocks injected with `Services::injectMock`
  must be reset in tearDown.
- Admin pages in tests: `withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])`.
- Every test must pass before a release. `--order-by=random` must pass too: it is how
  leaks between tests show up.
- New migrations: omit `'after' => …` (it forces a full table copy).

## Verify workflow

- **Listing detail page**: `list:serve` + `list:dev`, visit
  `http://localhost:8095/directory/{slug}` for a published, seeded listing.
- **Search typeahead**: type two characters into any search box (nothing should happen —
  check the network tab), then a third. Arrow keys, Enter on a highlighted row, Escape,
  and Enter with nothing highlighted (must submit the search). Check the header bar too,
  at phone width and scrolled, and the home hero — those are the two places the dropdown
  has been clipped. `curl 'localhost:8095/directory/suggest?q=plu'` answers the endpoint
  on its own.
- **Signup flow**: submit `/add-profile`, open Mailpit
  (http://localhost:8025), click the verification link, confirm the listing is now live at
  its slug.
- **Owner self-service**: `/manage` → enter email → Mailpit link → `/manage/edit` → save →
  confirm the edit shows on the public page and that `status`/`slug`/`email` are unchanged.
- **Admin**: `php spark directory:adminhash`, set `directory.adminPasswordHash` in `.env`,
  log in at `/admin` (throttled 5 attempts / 15 min by IP).
- **Verified Business**: apply from `/add-profile/verified` or the owner dashboard, approve
  at `/admin/verifications`, then check `verified_until` on the listing and that the badge
  renders on the profile *and* in search results.
- **Both sites**: with `.app` enabled locally (see `two-sites`), check the page on
  `localhost:<port>` **and** `app.localhost:<port>`: no South African profile, town, rand
  figure or badge copy on .app, and co.za unchanged. Plus: sign up abroad on .app, confirm
  in Mailpit, land on `#get-plus`, check out with `?plan=plus`, and see the row at
  `/admin/plus`.
- **Production**: the release checks are in `deploy-production`. Pages that differ per
  site must be checked on both hosts.

### Three things that make a working change look broken

Each of these produced a false failure while testing, and none of them says so:

- **The signup throttle is 3/hour per IP and 2/hour per email.** Over the limit you get
  "Too many submissions. Please wait a little while and try again." — which during repeated
  local testing is indistinguishable from a real bug. Note it takes the `redirect()->back()`
  path *without* `->with('old', …)`, so the form comes back **empty**, which reads as data
  loss on top. `php spark cache:clear` resets it. There is also a 3-second form-timing floor
  (`MIN_FORM_SECONDS`) that silently fakes success, so scripted submits must wait.
- **`DirectorySettings` caches the whole settings table for 24h** (`CACHE_TTL = 86400`).
  Saving through `/admin/settings` drops the entry, but editing the DB row or `.env`
  directly does **not** — so the change appears to do nothing. `php spark cache:clear`.
- **CSP is enforcing, which breaks browser-automation helpers.** Playwright's
  `page.addStyleTag()` is refused by `style-src 'self'` (see the CSP section below). Set
  properties through CSSOM (`el.style.setProperty(...)`) instead, which is not covered by
  `style-src`.

## CSP is enforcing — it constrains how views may be written

`Config\ContentSecurityPolicy` has `$reportOnly = false`. Violations are **blocked**,
not merely logged, and the first symptom is usually a panel that renders empty rather
than an error. When editing views:

- **No inline `<style>` without the `{csp-style-nonce}` placeholder** — `style-src` is
  `'self'` with no `'unsafe-inline'` (a nonce makes browsers ignore `'unsafe-inline'`
  anyway, so adding it back buys nothing).
- **No `style=""` attribute on any element either** — `style-src-attr` is `'self'`, so an
  inline style is dropped and the element silently renders unstyled. The brand `<img>`
  carried one for months: the logo drew on the text fallback's ocean tile, and every page
  load wrote two violations into `writable/logs/`. It is `img.brand-mark` in the
  stylesheet now. JS setting one property at a time (`el.style.setProperty`) is fine.
- **No inline event handlers** (`onclick=`, `javascript:` URLs) — `script-src-attr` is
  `'self'`. Bind listeners from a file instead.
- **A new off-site form POST needs adding to `form-action`, and so does wherever it
  redirects.** It does not fall back to `default-src`; an unlisted target dies silently at
  the last click. The redirect half is the one that bites: PayFast answers the checkout
  POST with a 302, and **live crosses to `https://payment.payfast.io`** while the sandbox
  stays on `sandbox.payfast.co.za` — so this passes every sandbox test and breaks the
  moment real payments are switched on. All of it is listed now
  (`www.payfast.co.za`, `sandbox.payfast.co.za`, `*.payfast.io`).
  The symptom is a console error quoting a policy that visibly *contains* the host it
  claims was violated, because Chrome names the URL the form pointed at rather than the
  redirect it actually refused. `writable/logs/` records what the browser enforced.
- **`img-src` picks up the map tile host at runtime** from `directory.mapTileUrl`. A
  blank grey map is the symptom of that being wrong, and it looks identical to a tile
  provider outage — check `writable/logs/` for `CSP violation` first. `originOf()` parses
  out scheme+host only, so the `?key=` that `mapTileUrl()` appends does not affect the
  policy — and a *watermarked* map, as opposed to a blank one, is never CSP: that is
  `directory.mapTileKey` missing.

## Schema history worth knowing

- `2026-07-28-120000_RenameProfessionsToCategories.php` — renamed
  `directory_professions`→`directory_categories`, `profession_id`→`category_id`,
  `qualifications`→`credentials` (the domain pivoted from healthcare-only to general
  service businesses).
- `2026-07-29-100000_AddManageTokenToListings.php` — added `manage_token`/`manage_expires`
  columns + indexes, separate from `verify_token`: `verify_token` publishes on first use
  (48h TTL), `manage_token` only proves ownership and is re-issuable anytime (1h TTL).
