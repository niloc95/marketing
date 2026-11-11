# SEO review: WebScheduler Local, 11 November 2026

Both sites, every public page type: home, discovery, categories, provinces, venues,
profiles, Jobs, Careers and every footer page. Checked against `main` at `bfffe1e`, the dev
server and production (GET only). It follows `2026-10-technical-seo.md` (17 Oct); old IDs
are cross referenced as SEO-nn.

Since that audit the platform grew from 299 to about 2,800 profiles, and .app went live.

## Summary

The foundations are sound:
- Every public page has one title, one description, one self canonical and a robots tag.
- Filtered and search views are `noindex, follow`.
- Each site has its own sitemap, and robots.txt blocks AI training bots.
- JSON-LD uses one `@graph` per page with no errors. The validator run on
  `/directory/dr-bertus-mensveld` returned 0 errors and 0 warnings. Its repeated blocks
  are the validator expanding `@id` references, not duplicate markup.

The real problems were:
- A South African venue in the .app sitemap.
- Paginated lists kept out of the index (most profiles were reachable only through the
  sitemap).
- City links that canonicalised to `/directory`.
- Small schema data issues.
- Two h1s on the .app signup page.
- "Search" wording in the discovery chips.

## Production sitemap (11 Nov 2026)

| Site | URLs | Profiles | Category and province pages | Other |
|---|---|---|---|---|
| co.za | 2,785 | 2,573 | 179 | 9 provinces, 1 venue, 1 job, 14 roles, 18 static |
| .app | 426 | | | |

## Findings and status

Fixed means the fix is on the branch with tests. Open means not fixed in this round.

| ID | Sev | Finding | Status |
|---|---|---|---|
| A1 | L | Team specialisations split on commas only; "…, and Body Contouring" became a topic | Fixed: `TeamMemberService::splitSpecializations()` also splits on `;` and strips a leading "and" or "&" |
| A2 | L | `#person` was defined twice (Person node and the matching team card): two jobTitles, two images, two descriptions | Fixed: the card is an `@id` reference in `employee[]`; its credentials, registration number and specialisations merge into `schema_person()` |
| A3 | M (SEO-12) | JobPosting `validThrough` used UTC (01:59 SAST next day); ZAR and ZA were hard coded | Fixed: the closing day ends in the business timezone; currency and country come from the post |
| A7 | M | A job or role that is not remote and has no city had no `jobLocation` | Fixed: always present unless remote, naming at least the country |
| A4 | L | No JSON-LD on `/jobs`, no list of roles on `/careers` | Fixed: CollectionPage + ItemList on `/jobs`, ItemList beside the FAQ on `/careers` |
| A5 | L | JobPosting sits outside the `@graph`, with an inline hiringOrganization | Open, harmless |
| A6 | L | No `og:site_name` or `og:locale`; co.za `html lang="en"` | Fixed: both added; `lang="en-ZA"` on co.za |
| B1 | **H** | .app sitemap listed `/directory/at/oriental-plaza`, which 301s to co.za | Fixed: `sitemapVenueUrls()` keeps the site's own venues only (`SiteScopeTest`) |
| B2 | M (SEO-05) | Page 2+ was `noindex` with a canonical to page 1 | Fixed: `seo_paged()` gives each page of a qualifying list its own canonical, `index, follow`, rel prev/next and " (page N)". Filtered views stay noindex |
| B3 | L (SEO-11) | The 404 page canonicalised to `/` | Fixed: no canonical and no `og:url` (`seo_meta(['canonical' => false])`) |
| B4 | L (SEO-10) | `/directory/Dr-Bertus-Mensveld` answered 200 | Fixed: 301 to the lowercase slug |
| B5 | L | Sitemap header `no-store, …, public, max-age=900` | Fixed: one value, `public, max-age=900` |
| B6 | L | `/recommend` and `/jobs/post` were not in the sitemap; the category count ignored branches | Fixed |
| B7 | L (SEO-02) | Landing thresholds count noindex profiles | Open, small set while `indexMinDescription = 0` |
| B8 | L | hreflang only on `/` and `/pricing` | Fixed: on every page both sites share; never on profiles, venues or jobs |
| B9 | M | City chips go to `/directory?province=…&city=…`: indexable, but canonical to `/directory` | Fixed for now: a place-only query is noindex. Real fix is phase 2 city pages (D1) |
| B10 | L | Page 2's ItemList was printed under page 1's `@id` | Fixed by B2 |
| C1 | M (SEO-07) | CSS, JS and uploads cached for 4 hours | Prepared: header rules in both vhost files; the Cloudflare step is in the release notes below |
| C2 | L (SEO-04) | All HTML `no-store`; Cloudflare HTML cache | Open, out of scope |
| C3 | L (SEO-03, SEO-08) | Cookie banner LCP, five font weights | Open, not re-measured |
| C4 | L | About 22 KB of inline SVG per profile | Open |
| C5 | L | No preconnect to Google Tag Manager | Fixed |
| D1 | M (SEO-06, SEO-16) | No city landing pages and no written category intros | Open: phase 2, its own plan |
| D2 | M | ~2,500 imported profiles indexable with one-line descriptions | Owner decision 11 Nov: keep; revisit in December with Search Console data |
| D3 | L | `/directory` titled "Discover everything" | Fixed: "Discover local businesses in South Africa" (co.za), "… near you" (.app) |
| D4 | L | En dashes in hours, price and salary text, including Google snippets | Fixed (owner decision): "Mon to Fri 08:00 to 16:30", "R15 000 to R18 000" |
| F1 | M | Two h1s on `/add-profile` on .app | Fixed |
| F2 | M | "Search the exact words instead" and two "search" aria labels | Fixed: Discover wording |
| F3 | L | Profile headings jump from h1 to h3 | Fixed: panel headings are `h2.panel-title`, with CSS mirrored and scoped to the class |
| F4 | L | Profile logo and video thumbnail had empty alt text | Fixed |
| F5 | L | Home example chips link to `/directory?q=` (noindex) | Open: the examples are "Category in City", which only a city page (D1) can serve |
| F6 | L | "directory" appears in copy only when naming competitors | Leave |
| F7 | L | WebSite SearchAction is retired by Google | Leave, harmless |

## Release notes for C1 (each step needs the owner's OK)

1. Copy both vhost files to `/etc/apache2/sites-available/`.
2. Run `sudo apachectl configtest`, then `sudo systemctl reload apache2`.
3. In Cloudflare, under Caching > Configuration > Browser Cache TTL, choose "Respect
   existing headers" for both zones. Otherwise Cloudflare overwrites the year with 4 hours.
4. Check that each of these three answers `max-age=31536000, immutable`:

   ```
   curl -sD- -o /dev/null 'https://local.webscheduler.co.za/assets/directory.css?v=1' | grep -i cache-control
   curl -sD- -o /dev/null 'https://local.webscheduler.co.za/assets/directory.js?v=1' | grep -i cache-control
   curl -sD- -o /dev/null 'https://local.webscheduler.co.za/assets/listings/<an upload>.webp' | grep -i cache-control
   ```

## After release (owner, Search Console)

- Resubmit both sitemaps. The .app sitemap has never been submitted to Search Console or
  Bing.
- Run the Rich Results Test on a live profile, a live job and a careers role.
- On the JobPosting streetAddress issue, click Validate fix.
- Watch "Crawled, currently not indexed" for the imported profiles; that is the evidence
  for D2.

## Tests

- New file `tests/database/SeoReviewTest.php`: pagination, 404, slug case, sitemap header
  and gaps, no en dash, logo alt.
- New `SiteScopeTest` cases: .app venue sitemap, one h1 on the .app signup page, place-only
  query noindex.
- Updated: `SchemaTypesTest` (one Person node) and the hours, salary and range wording
  tests.
- Under `--order-by=random`, `InternationalListingTest::testPayFastSendsAnAppOwnerBackToTheApp`
  and `BookingFoundationTest::testBookingsStayLockedWhileTheSwitchIsOff` can fail. They
  fail on `main` with the same seeds, so these leaks predate this work.
