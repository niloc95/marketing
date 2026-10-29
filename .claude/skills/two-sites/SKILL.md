---
name: two-sites
description: How one WebScheduler Local install serves two sites — local.webscheduler.co.za (South Africa only) and local.webscheduler.app (every other country, live since 25 Oct 2026). Site context and scoping, the countries registry, cross site redirects, per site copy, settings, caches, analytics and legal pages, country aware geocoding, hours and holidays, money and the Plus plan, local dev for both hosts, the tests that guard against leaks, and the one flag that rolls .app back. Use for any change that touches what a page shows, where a link points, prices, countries, emails, or anything that could differ between co.za and .app.
---

# Two sites, one install

| | `za` | `app` |
|---|---|---|
| Host | `local.webscheduler.co.za` | `local.webscheduler.app` |
| Countries | `ZA` only | every country except ZA (`countries => null`) |
| Paid upgrade | Verified Business badge (documents checked), rand | **Plus** (no checks), US dollars, charged in rand |
| Admin | `/admin` (Cloudflare Access) | none: `/admin*` 302s to co.za's admin |
| Analytics | GA4 `G-5WNR0873VB` | GA4 `G-PNNB92FLC0` (`sites.app.analyticsId`) |

**One codebase, one database, one PHP app, one admin.** The request's **host** picks the
site. A profile's **business country** (`country_code`) picks the site it belongs to. The
visitor's IP never decides anything: `CF-IPCountry` only drives the dismissible "other
site" banner on public pages (`site_hint()`, cookie `ws_site_hint`) and preselects the
country on the .app signup form.

## The pieces

- **`Config\Sites`**: the two profiles (`countries`, `mapView`, `locale`, `currency`,
  `paymentProvider`, `bingVerification`), `$appCopy` (wording that names South Africa on
  co.za, as .app says it), `$zaOnlyPaths`, `host($key)`, `appEnabled()`, `isZaOnly()`.
  Hosts come from `.env` (`sites.za.host`, default the host of `app.baseURL`;
  `sites.app.host`, default `local.webscheduler.app`).
- **`App\Services\SiteContext`** = `service('site')`: `key()`, `isApp()`, `scoping()`
  (true once .app is enabled), `forCountry($code)` / `forRow($row)` (which site owns a
  country or row), `ownsCountry()`, `scopeSql($column)` (the SQL every public query adds),
  `host()`, `profile()`. It is a shared service: tests that change host or flag must
  `Services::resetSingle('site')`.
- **`App\Filters\SiteResolver`** (global, right after `frontcontrollerpath`): resets the
  site and the hours clock per request; while `.app` is off, every app host request 302s
  to co.za; once on, `/admin*` on .app 302s to the co.za admin (GET only), and
  `$zaOnlyPaths` 301 to co.za.
- **`app/Helpers/site_helper.php`** (autoloaded):
  - `site()`: the service.
  - `site_url_for($key, $path)`: an absolute URL on a named site.
  - `row_site_url($row, $path)`, `listing_url($row)`, `listing_site_url($listing, $path)`:
    URLs on the site that **owns** the row. Use these for anything that leaves the request:
    emails, PayFast return URLs, admin "View" links, sitemaps, cross site links.
    `base_url()` means "this request's host" and is only right for a page linking to itself.
  - `site_cache_key($key)`: per site cache keys. Any cached list of profiles, counts,
    sitemaps or vocabulary must use it, or one site's content leaks onto the other.
  - `site_copy($key, $zaText)`: co.za's own text untouched on co.za, `$appCopy[$key]` on .app.
  - `site_area_served()`: schema.org `areaServed` per site. `site_hint()`: the banner.
- **`App\Config\App`**: `allowedHostnames` includes the app host in production too, which
  is what lets `base_url()` follow the request's host.

## Rules that keep the sites apart

1. **Every public query is scoped.** `DirectoryService::published()` adds
   `scopeSql('country_code')`; `JobPostModel::live()` does the same for jobs. A new public
   query that skips them shows South African profiles on .app (or the reverse).
   `tests/database/SiteScopeTest.php` walks the public pages on both hosts and fails on a
   leak. Add any new public page to its `PAGES`.
2. **Wrong host, permanent move.** `Controllers/Concerns/RedirectsToOwningSite` 301s a
   profile, venue or job on the wrong host to the same path on its owner's host (GET/HEAD
   only). Never redirect `POST /payfast/notify`, `payfast/setup-notify`, `manage/*` POSTs or
   unsubscribe POSTs.
3. **Country decides the site, and it can change.** An admin who corrects a country moves
   the profile: the old URL 301s, new emails use the new host. One database, so the
   owner's email and manage access work on both.
4. **Signup on the wrong host hands over.** Choosing South Africa on .app (or another
   country on co.za) shows a note; on submit `Listing::store()` calls `handOver()`,
   which stores the typed fields under a short lived cache token and 303s to the right
   host's `add-profile?handover=…` instead of saving. Pinned by `CrossHostSignupTest`.
5. **co.za stays as it was.** Anything .app needs is added behind `site()->isApp()`,
   `site_copy()` or an `@app` setting, so co.za's HTML does not change. Before releasing
   site plumbing, diff co.za pages against production (the `intl-baseline` worktree
   approach: render both, `diff`).
6. **The admin is on co.za only**, for both sites. Admin links to a profile use
   `listing_url()`. Never add a `local.webscheduler.app` destination to the co.za Access
   application: it breaks admin login (see the `deploy-production` skill).

## Countries (`Config\Countries`)

- `$codes`: country name → ISO code for every country in the signup list.
- `$profiles`: the nine with full support (ZA, GB, US, AU, NZ, NA, BW, KE, NG): `slug`
  (`united-kingdom`), `regions` (ZA = the nine provinces, exactly as stored; GB = the four
  nations; US states; AU states), `timezone` + `regionTimezones`, `dialCode`,
  `postalPattern`, `bbox`, `mapView`, `currency`, `locale`, `holidays`.
- Methods: `codeForName`, `nameForCode`, `codeForSlug`, `profile`, `isSupported`,
  `regions`, `regionFromSlug`, `timezone`, `isLocal` (the one "is this South Africa" test).
- Other countries (Portugal, India, China…) list with generic handling: free text
  region, no address lookup, no holiday calendar.
- **`country_code` is derived, never posted.** `Models/Concerns/DerivesCountryCode`
  fills it from `country` on every write, on listings, practice locations, venues, job
  posts, careers and referrals. `php spark directory:countries:backfill [--dry-run]`
  fills old rows.
- Routes on .app: `/directory/{country}`, `/directory/{country}/{region}`,
  `/directory/{category}/{country}`, `/directory/{category}/{country}/{region}`
  (`Directory::segment`, `place`, `categoryInRegion`, `renderCountry`). Country slugs are
  reserved. co.za keeps `/directory/province/{slug}` and `/directory/{category}/{province}`.
- Discovery: `Config\Search::forSite($key)` swaps the province vocabulary for countries
  and regions on .app (`$appRegionCodes`, `$appPlaceAliases`, `$appFallbackExamples`).
  One word regions need a preposition ("in Victoria") so they don't eat keywords.

## Addresses, maps, hours, holidays

- **Geocoders take a country code.** `MapboxGeocoder` sends `country=` from the form;
  `NominatimGeocoder::isPlausible($lat, $lng, $country)` uses the registry `bbox`; the SA
  province ladder runs for ZA only. ZA cache keys are unchanged (`keySuffix()`).
  `/address-suggest?country=` scopes suggestions. Autocomplete is on for the nine
  supported countries only.
- The address form (`_address_inputs.php` + `directory.js`): a region select for
  supported countries, free text otherwise; `data-site-map-view` on `<body>` and
  `data-country-views` centre maps. Don't name a body attribute `data-map-view`: profile
  maps query `[data-map-view]`.
- **Each profile has its own clock and calendar.** `hours_context($row)` sets the
  timezone and holiday calendar for a profile; `hours_within($row, fn)` scopes them to one
  callback (saves, schema). `hours_now()` / `hours_calendar()` read the current context.
  The app runs in UTC.
- Holidays: `PublicHolidays` (ZA, coded), `YasumiHolidays` (azuyalabs/yasumi: GB per
  nation, US, AU per state, NZ, KE), `NoPublicHolidays` (NA, BW, NG and unsupported
  countries: the holiday section hides). `calendar->named()` lists a year's days.

## Money and Plus

- **`money($amount, $currency = 'ZAR')`** formats every price. ZAR output is byte
  identical to the old hand typed `R` prefixes. USD reads `$8.99`.
- **No rand figures in .app customer copy** (owner rule): the rate moves. Copy says Plus
  is billed in South African rand by a South African company through PayFast. Admin
  screens may show rand.
- **Plus is the International plan once .app is live.** `VerificationService::plusMode()`
  = `site()->scoping()`. `PLAN_INTERNATIONAL` rows are Plus; nobody pays to be published
  (`requiresSubscription()` is false), Plus is optional.
  - `plusOffered()`: live, `international_listing_enabled` on, and priced.
    `plusAvailableFor($listing)`: offered and the listing is outside ZA.
  - Price: `plus_price_usd` × `usd_zar_rate` = `DirectorySettings::plusRandAmount()`,
    snapshotted on the subscription row. `Manage::checkout` re-prices unpaid or lapsed
    rows at today's amount.
  - **Gates:** `listing_has_plus()`; `listing_has_premium()` = badge **or** Plus, the one
    gate for branches, team members and the Jobs board. Profile video stays badge only.
  - `plus_badge_pill()` links to `/plus`. It must never look like the emerald Verified
    pill: nothing about a Plus business is checked.
  - Owner side: `_plus_panel.php` on the dashboard (`#get-plus`); `Directory::verify()`
    lands a newly confirmed profile abroad there. `_plus_compare.php` on signup and home.
    `cancelPlus()`: a lapse keeps the profile and takes the extras.
  - Admin: `/admin/verifications?plan=plus` (or `/admin/plus`).
  - PayFast: return and cancel URLs go to the listing's own site; **the ITN `notify_url`
    always stays on co.za**. Item name "WebScheduler Local Plus".
- The badge is off on .app unless a `verified_badge_enabled@app` settings row exists.
- Profile setup service: `ProfileSetupService::amount()` / `usdAmount()`: R250 on co.za,
  `profile_setup_usd` (US$29.99) on .app, charged in rand.

## Settings, copy, legal, SEO per site

- **`DirectorySettings`**: on .app a row named `{name}@app` wins over `{name}`
  (`stored()`), then `.env`, then `Config\Directory`. New keys for .app: `plus_price_usd`,
  `usd_zar_rate`, `profile_setup_usd`. `/admin/settings` edits both.
- **Copy**: `site_copy()` + `Config\Sites::$appCopy`. "Built for South Africa" is co.za
  only. Header CTA "Add your business" on both. .app has its own FAQ groups
  (`_faq_app_groups.php`), a neutral Company and Media page, and `/plus` (mirrors
  `/verified`; co.za `/plus` 301s to .app). Same copy rules everywhere (CLAUDE.md).
- **Legal**: `Views/legal/app/terms.php` (§4 is Plus) and `legal/app/privacy.php`,
  reviewed by a lawyer before launch. South African law governs, consumer rights kept, no
  UK representative. `Legal::APP_LAST_UPDATED` and `Legal::termsVersion()`: bump the .app
  date when those pages change.
- **SEO**: `html lang`/`inLanguage` per site, `site_area_served()`, per site sitemap
  (`Directory::sitemap`, `sitemapCountryUrls()`, `/plus` on .app only) and robots;
  hreflang only between the two home pages and matching static pages. Bing ownership tag
  on .app only.
- **Analytics**: `sites.app.analyticsId` and `sites.app.clarityId` in `.env` (no .app
  Clarity project yet). Mautic tags app signups `site-app`.

## Local dev for both hosts

- The `international-app` worktree (`../marketing-worktrees/international-app`, port
  8098) has `sites.app.host = app.localhost` and `sites.app.enabled = true` in `.env`:
  co.za is `http://localhost:8098`, .app is `http://app.localhost:8098` (browsers resolve
  `*.localhost` themselves). Its databases are `webscheduler_directory_intl` and
  `webscheduler_directory_tests_intl`; nine `sample-*` profiles abroad live there.
- Any checkout can do the same: add those two `.env` lines, and `app.localhost` to
  `app.devHostnames` if `base_url()` does not follow.
- Uploaded images are gitignored: copy `public/assets/hero/uploads` and
  `public/assets/listings` from the main checkout into a new worktree, or videos and
  photos are missing.

## Tests

- `tests/_support/bootstrap.php` re-pins `sites.app.enabled = false` and
  `sites.app.host = local.webscheduler.app` **after** CI loads `.env`, so a developer's
  `.env` can't switch .app on for the suite (`<env>` in `phpunit.dist.xml` loses to
  `.env`).
- `Tests\Support\WithEnv`: `setEnv()` / `restoreEnv()` to switch .app on inside a test;
  call `restoreEnv()` and `Services::resetSingle('site')` in `tearDown`.
- Hit a host with `$this->withHeaders(['Host' => …])->get(…)`.
- Suites: `SiteScopeTest` (leaks, redirects, per site pages), `SiteContextTest`,
  `CountryRegistryTest`, `MoneyHelperTest`, `CountryClockAndPhoneTest`,
  `CrossHostSignupTest`, `InternationalListingTest` (Plus), `ListingCountryTest`, the
  geocoder tests.

## Production and rollback

- The vhost is `deploy/apache/local.webscheduler.app.conf` (same docroot, its own
  Cloudflare origin certificate at `/etc/ssl/cloudflare/webscheduler.app.{pem,key}`).
- **Rollback: set `sites.app.enabled = false` in the server `.env` and reload PHP FPM.**
  .app then 302s to co.za and co.za serves every country as it did before launch.
- Open items (26 Oct 2026): a separate Cloudflare Access application for
  `local.webscheduler.app/admin*`, sitemaps to Search Console and Bing, rotating the .app
  origin certificate, the first real Plus purchase.
