# listing. → local. migration: validation, 17 October 2026

How it was checked:
- **Live hosts:** GET and HEAD requests only.
- **Production server (`ssh lightsail`):** read-only access to configs, logs and `sar`, plus `SELECT` queries.
- **Fixes:** tested on a local Apache, on branch `fix/legacy-host-hygiene`. **Nothing is deployed.**

One correction to the brief: the host is a hand-built Ubuntu 24.04 Lightsail instance (Apache 2.4, PHP 8.3-FPM, MySQL 8.0)
behind Cloudflare, not Bitnami.

Files in this folder:

| File | What it is |
|---|---|
| `redirect_audit.py` | The audit script (Python 3 standard library). Run as `python3 -I redirect_audit.py listing-urls-with-impressions.csv out.csv --variants 20` |
| `redirect-audit.csv` | Its output: 291 URLs plus 60 variants (http/https × slash/no slash for the top 20) |
| `listing-urls-with-impressions.csv` | The input |
| `staging_matrix.py` | The request matrix used to compare the current and proposed vhosts on a local Apache |

## Verdict

**The migration's redirects are technically correct.**
- Every URL that had impressions gets exactly one **301** to the same path on `https://local.webscheduler.co.za`.
- That includes http, https and trailing slash variants, and every one ends on a 200.
- There are no 302s, no meta refreshes or JS redirects, and nothing goes to the home page.
- The one real redirect fault was `/add-listing`, which takes two hops; that's fixed on the branch.

**What the redirects can't carry is the indexing.** 249 of the 291 URLs land on a page that is `noindex` by design:
- the profile quality rule (score under 40, from 8 Oct);
- the description rule (description under 50 characters, from 12 Oct).

Those 249 pages carried **71 of the 90 clicks (79%) and 4,280 of the 5,179 impressions (83%)** in the CSV. That's a
strategy decision rather than a migration fault; see "Decisions for you", item 1.

## Task 1: Redirect audit (host: `listing.webscheduler.co.za`)

| Result | Primary URLs (291) | Variants (60) |
|---|---|---|
| First response 301 | 291 | 60 |
| One hop | 290 | 57 |
| Target = same path on `local.` | 291 | 60 |
| Final 200 | 291 | 60 |
| **Pass** | **41** | **12** |
| Fail: final page `noindex` | 249 | 45 |
| Fail: chain of 2 hops (`/add-listing`) | 1 | 3 |

**Where redirects are made**
- `listing.`: in the vhost `/etc/apache2/sites-available/listing.webscheduler.co.za.conf` (repo copy
  `deploy/apache/listing.webscheduler.co.za.conf`, identical to production before the fix). Both the `:80` and
  `:443` blocks use `RewriteRule ^/(.*?)/*$ https://local.webscheduler.co.za/$1 [R=301,L]`, and `:443` exempts
  `POST /payfast/notify` (tested on `THE_REQUEST`).
- `local.`: http → https is a `RedirectMatch permanent` in `local.webscheduler.co.za.conf:23`. The trailing
  slash strip is in `public/.htaccess`. `/add-listing` → `/add-profile` is `Listing::legacyRedirect()`
  (`app/Controllers/Listing.php:36-41`).
- No CI4 filter redirects between hosts.

**The `/add-listing` chain:** `listing./add-listing` 301 → `local./add-listing` 301 → `local./add-profile`.
Fixed in the vhost so it's a single hop, with the query string and the `/verified` suffix kept, matching the app.

**Old URLs with no equivalent:** none in the CSV. All 291 slugs still exist on `local.`, including
`sangoma-traditional-healer-in-durban`. Deleted and unpublished profiles return a real 404 on `local.`, so a
removed profile's old URL gives 301 → 404 (same path, never the home page).

## Task 2: Old-host hygiene

| Check | Before | After the branch | Host |
|---|---|---|---|
| `listing./robots.txt` | 301 → `local./robots.txt` | **200**, `User-agent: *` / `Allow: /` | listing. |
| `listing./sitemap.xml` | 301 → `local./sitemap.xml` (a cross-host sitemap of `local.` URLs) | **410** | listing. |
| `local./sitemap.xml` | 200, **81 URLs, every one 200, not noindex, self-canonical** (all 81 checked) | unchanged | local. |
| `local./robots.txt` references the sitemap | yes | unchanged | local. |

Googlebot fetched `listing./robots.txt` 104 times in 14 days, and every fetch got a 301. Google does follow
robots.txt redirects, so this wasn't blocking crawling, but a direct 200 removes the dependency.

### Diff (`deploy/apache/listing.webscheduler.co.za.conf`, commit `b56cdbe`)

```diff
@@ header
 #      .htaccess slash rule would otherwise make it two).
+#      Exceptions (17 Oct 2026): /robots.txt is served here (200, allow all) so
+#      Google keeps crawling the old URLs, /sitemap.xml is 410, and the renamed
+#      signup paths go straight to /add-profile. The robots file is
+#      deploy/apache/listing-legacy/robots.txt -> /var/www/listing-legacy/.
@@ <VirtualHost *:80>
 	ServerName listing.webscheduler.co.za
+	# The old host's own robots.txt and sitemap, answered here, never redirected …
+	Alias /robots.txt /var/www/listing-legacy/robots.txt
+	<Directory /var/www/listing-legacy>
+		Require all granted
+	</Directory>
 	RewriteEngine On
+	RewriteRule ^/robots\.txt$ - [L]
+	RewriteRule ^/sitemap\.xml$ - [G,L]
+	# Signup was renamed /add-profile on 6 Oct 2026 (/list-your-practice before …
+	RewriteRule ^/add-listing(/verified)?/*$ https://local.webscheduler.co.za/add-profile$1 [R=301,L]
+	RewriteRule ^/list-your-practice/*$ https://local.webscheduler.co.za/add-profile [R=301,L]
 	RewriteRule ^/(.*?)/*$ https://local.webscheduler.co.za/$1 [R=301,L]
@@ <VirtualHost *:443>   (same Alias block, and the same four rules placed
                          before the unchanged PayFast RewriteCond and catch-all)
```

New file `deploy/apache/listing-legacy/robots.txt`:

```
User-agent: *
Allow: /
```

### Staging result

A local Apache ran the production vhost and the proposed vhost with the same request matrix. **Only these rows
differ:**
- `/robots.txt` (also with `?x=1`, on both ports): 301 → **200**.
- `/sitemap.xml` (both ports): 301 → **410**.
- `/add-listing`, `/add-listing/`, `/add-listing/verified?via=x&invite=y` and `/list-your-practice`: now one
  301 straight to `/add-profile…`, with the query kept.

**Unchanged:**
- profile URLs, slash variants, query strings, `/`, `/index.php/…` and `/directory/robots.txt`;
- `/add-listing-guide`, which isn't caught by the new rule;
- **`POST /payfast/notify` on 443: still not redirected.**

### To deploy (after your go-ahead)

```bash
ssh lightsail
sudo cp /etc/apache2/sites-available/listing.webscheduler.co.za.conf{,.bak-20261017}
sudo mkdir -p /var/www/listing-legacy
sudo -u deploy git -C /home/deploy/src fetch && sudo -u deploy git -C /home/deploy/src checkout main   # after merge
sudo cp /home/deploy/src/deploy/apache/listing-legacy/robots.txt /var/www/listing-legacy/robots.txt
sudo cp /home/deploy/src/deploy/apache/listing.webscheduler.co.za.conf /etc/apache2/sites-available/
sudo apachectl configtest && sudo systemctl reload apache2
curl -sI https://listing.webscheduler.co.za/robots.txt      # 200
curl -sI https://listing.webscheduler.co.za/sitemap.xml     # 410
curl -sI https://listing.webscheduler.co.za/add-listing     # 301 → /add-profile
curl -s -o /dev/null -w '%{http_code}' -X POST https://listing.webscheduler.co.za/payfast/notify   # not 301
```

Rollback: copy the `.bak-20261017` file back, then `systemctl reload apache2`.

## Task 3: `noindex` on `local.`

**Always `noindex`, correctly:**
- every admin view (`app/Views/admin/*.php:5`, `noindex, nofollow`);
- the owner dashboard and sign-in: `manage_edit`, `manage_request`, `partners_login`, `partners_dashboard`;
- checkout and thank-you pages: `verification_checkout`, `profile_setup_checkout`, `profile_setup_thanks`;
- `unsubscribe`, `recommend_stop`, `jobs/alerts_off`, `jobs/manage` and `jobs/owner_edit`;
- error pages: `errors/not_found` (`noindex, follow`), `errors/html/production`, `error_exception`;
- `/health` and menu PDFs, through an `X-Robots-Tag: noindex` header (`Health.php:45`, `Directory.php:653`).

**Conditional (public templates):**

| Template | Rule | Why |
|---|---|---|
| Profile `directory/show.php:82` | `listing_is_indexable()`: description ≥ 50 characters **and** quality ≥ 40 (unscored passes) | Since 8 and 12 Oct (`8ecdb89`, `0a162de`). Keeps thin stubs out of Google. **This is what noindexes 249 of the CSV's URLs** |
| Category `directory/landing.php:63` | at least 3 published profiles, page 1 only | Thin list pages; page 2 onwards is `noindex` with canonical → page 1 |
| Province `directory/province.php:33` | at least 3 published profiles, page 1 only (`Directory.php:481-482`) | Same rule |
| Venue `directory/venue.php:64` | the same minimum; `?category=` and `?q=` make it `noindex` | Same rule |
| Directory index `directory/index.php:75` | `noindex` with any filter, search or page 2 onwards | Search results |
| `/careers` `careers.php:56` | `noindex` when filtered | Filters |
| Job `jobs/show.php:39` | indexable only for a live vacancy; service requests and closed posts are `noindex` | Google for Jobs policy |
| `/jobs` `jobs/index.php:38` | `$indexable` (filters and pages) | Filters |
| `/jobs/post` `jobs/post.php:23` | `noindex` except the unlisted vacancy form | Form |

**Flagged:** public business profiles are `noindex` (all 262 imported profiles, plus 3 public signups). So are
categories and provinces with fewer than 3 profiles. Right now Limpopo, for example. No category or region page
that clears the minimum is `noindex`.

## Task 4: 404s and 5xx by host (Googlebot only)

**This can't cover 30 days.** Apache logs rotate daily with `rotate 14` (`/etc/logrotate.d/apache2`), so only
3 to 17 Oct exists. There's one log file per host: `listing-access.log` is **local.** (it was `listing.` before
3 Oct), `listing-legacy-access.log` is listing., and `marketing-access.log` is webscheduler.co.za. Googlebot was
identified by user agent and confirmed by reverse DNS (`*.googlebot.com` or `*.google.com`).

| Host | Googlebot requests | 404 | 5xx | 302 | Groups | Cause / fix |
|---|---|---|---|---|---|---|
| local. | 1,054 (17 IPs, all verified Google) | 7 | 0 | 0 | `/assets/listings/listing_20260921_094923_43fc5c48.webp` ×7 | A replaced logo; the file was removed and no row references it. Google re-fetches it from its image index. **Intentional** |
| listing. | 255 (all verified) | 0 | 0 | 0 | 255 × 301 (104 of them `robots.txt`) | Expected; robots fixed by the branch |
| webscheduler.co.za | 187 | 63 | 0 | 0 | 60 are scanner paths (`/gcp-key.json`, `/proc/self/environ`, `/serviceAccountKey.json`…) from **spoofed** user agents with no Google reverse DNS. Real Googlebot 404s: `/favicon.ico`, `/feed/`, `/comments/feed/` | Scanners: intentional. Real ones: add `/favicon.ico` to the marketing site, and 410 the two feed URLs (old WordPress-style paths). **Not changed: marketing site, needs your OK** |

**No 5xx and no 302 for Googlebot on any of the three hosts on this server.** So the domain-wide 302s, 5xx and
"robots.txt unavailable" that Search Console reports come from other hosts. Checked directly (report only, not
changed):

| Host | `/` | `/robots.txt` | Likely contribution |
|---|---|---|---|
| `dental.webscheduler.co.za` | **302** → `/auth/login` | 200 | 302s |
| `updates.webscheduler.co.za` (Mautic) | **302** → `/s/dashboard` | 200 | 302s |
| `directory.webscheduler.co.za` | **526** | **526** | 5xx and "robots.txt unavailable": a leftover DNS record with nothing behind it (noted in the deploy skill) |
| `demo.webscheduler.co.za` | timed out after 10 s | 200 | Possibly 5xx or timeouts |
| `app.webscheduler.co.za` | 404 | 200 | 404s |

Search Console's per-host split isn't available here, so these are "consistent with", not "proven".

**13 Oct 2026 (local.):**
- **Googlebot:** about 53 log lines with a Googlebot user agent (robots.txt, profiles, images, the vacancy), every
  one 200 or 304. That's more than Search Console's 11 requests, which may count only some resource types.
- **Releases:** two on that day, with migrations at 11:04 (`AddReviewsEnabledToListings`) and 15:21 UTC
  (`CreateCareers`, `AddSectionsToJobPosts`). The build runs on the server.
- **Load (`sar`, 10-minute samples):** CPU averaged 0.2% user, rising to 0.8% in the 11:00 and 15:00 hours. Steal
  was 0.2% and iowait 0.0%, no sample went above 30% CPU, and the journal has no warnings. The nightly
  `mysqldump` runs at 02:15 UTC with no visible effect.
- **Conclusion:** nothing on the server explains a 3.2 s average. **The cause can't be established**, because the
  access log format records no response time (`%D`). A few slow fetches among 11 would produce that average, but
  that is not evidence. No link to the indexing drop is claimed.

## Task 5: Response-time baseline (local., 17 Oct, 7 samples each, from South Africa)

| Page | Median TTFB | p95 |
|---|---|---|
| `/` | 359 ms | 992 ms |
| `/directory/cpt-dental` | 355 ms | 367 ms |
| `/directory/center-aesthetic-dermatology` | 357 ms | 365 ms |
| `/directory/skullair-tattoos` | 341 ms | 353 ms |
| `/directory/yoga-love` | 345 ms | 353 ms |
| `/directory/plaza-tailors-and-outfitters` | 345 ms | 957 ms |
| `/directory/home-decor` | 334 ms | 939 ms |
| `/directory/clothing-apparel` | 329 ms | 364 ms |
| `/directory/hair-salon` | 329 ms | 950 ms |
| `/jobs` | 330 ms | 901 ms |
| `/jobs/1-aesthetic-laser-therapist` | 325 ms | 337 ms |

- **Nothing is consistently over 1 s.** The p95 outliers are the first (cold) hit after a quiet spell.
- **No image over 300 KB** on the five profile pages; the largest is 135 KB. The 233 KB `social-1080.png` is the
  `og:image` only and isn't loaded by the page.
- This corrects SEO-04 in the October technical SEO audit, which overstated TTFB from a single cold sample. That
  report is amended on its branch.

## Decisions for you

1. **The `noindex` rule is now the main limit on search traffic (strategy).**
   - 249 of the 291 URLs that had impressions land on `noindex` profiles. They carried 79% of the clicks and 83% of
     the impressions.
   - The weekly drop lines up with the move (3 Oct) **and** the two rule changes (8 and 12 Oct). I can't separate
     their effects without Search Console's daily data.
   - The two rules are settings, `directory.indexMinQuality` and `directory.indexMinDescription`, so they can be
     changed in `.env` without a deploy.
   - Options: keep them, lower them, or exempt profiles that have earned impressions until they're written up.
2. **Deploy the vhost change.** Merge `fix/legacy-host-hygiene`, then run the block above.
3. **Delete the `directory.webscheduler.co.za` DNS record in Cloudflare.** It answers 526 to everything,
   including robots.txt.
4. **Keep logs longer and record response times.** `rotate 14` → `rotate 35`, and add `%D` to the `cloudflare`
   LogFormat, so the next 30-day question can be answered.
5. **Marketing site:** add `/favicon.ico`, and 410 `/feed/` and `/comments/feed/`.
6. **The booking app hosts** (`dental.`, `updates.`, `demo.`, `app.`): whether the root 302s and the `demo.`
   timeout should change is for whoever owns those apps. Nothing was touched.
7. **In Search Console:** remove any sitemap still submitted on the **`listing.`** property, keep the Change of
   address, and after the deploy use URL inspection on `listing./robots.txt`.
