<?php

namespace App\Controllers;

use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use CodeIgniter\Exceptions\PageNotFoundException;

class Directory extends BaseController
{
    public function home()
    {
        $svc            = new DirectoryService();
        $provinceCounts = $svc->provinceCounts();

        // The location cards want a "Johannesburg · Pretoria · Soweto" line, so
        // each province needs its own city query. Bounded by design: there are
        // nine provinces, and only those holding listings are queried at all.
        $provinceCities = [];
        foreach (array_keys($provinceCounts) as $province) {
            $provinceCities[$province] = array_keys($svc->cityCountsForProvince($province, 3));
        }

        return view('directory/home', [
            'featured'       => $svc->featured(8),
            'recent'         => $svc->recent(4),
            'topCategories'  => $svc->topCategories(8),
            'provinceCounts' => $provinceCounts,
            'provinceCities' => $provinceCities,
            'groups'         => $svc->categoriesGrouped(),
            'provinces'      => $svc->provinces(),
            'stats'          => $svc->stats(),
        ]);
    }

    public function index()
    {
        // The most expensive public read in the app — FULLTEXT plus LIKE scans
        // plus an EXISTS subquery, uncached, per request. 60/min is far above
        // anything a person clicking facets and pages will reach, and well
        // below what a scraper walking the result set wants. Search engines are
        // unaffected: they crawl listing pages and the sitemap, not /directory
        // with query strings, which is noindexed past page 1 anyway.
        if (service('throttler')->check('directory-index-' . md5((string) $this->request->getIPAddress()), 60, MINUTE) === false) {
            return $this->response->setStatusCode(429)
                ->setHeader('Retry-After', (string) service('throttler')->getTokentime())
                ->setBody('Too many searches. Please slow down and try again shortly.');
        }

        $svc     = new DirectoryService();
        $filters = $this->searchFilters();
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $result  = $svc->browse($filters, $page);

        // Resolved here rather than in the view, because the view turns these
        // into the <title>, the og:title and a rel=canonical, and then asks for
        // `index, follow`. Left unresolved, ?category=Cheap-Loans-Payday is a
        // 200 with an attacker's words in the title and a canonical pointing
        // wherever they like — unlimited indexable URLs on this domain, minted
        // by anyone who can construct a query string. The sibling landing pages
        // (segment(), place()) have always resolved first; /directory was the
        // one entry point that did not.
        $category = $filters['category'] !== ''
            ? $svc->findCategoryBySlug($filters['category'])
            : null;
        $province = in_array($filters['province'], DirectoryService::SA_PROVINCES, true)
            ? $filters['province']
            : null;

        return view('directory/index', [
            'result'      => $result,
            'filters'     => $filters,
            // Null means "asked for, but no such thing" — the view must not
            // treat that page as indexable or name it after the raw input.
            'category'    => $category,
            'province'    => $province,
            'categories' => $svc->categories(),
            'groups'      => $svc->categoriesGrouped(),
            'provinces'   => $svc->provinces(),
            'radii'       => DirectoryService::RADIUS_OPTIONS,
        ]);
    }

    /**
     * Pins for the search map, as JSON.
     *
     * Separate from index() because the map and the list want different slices:
     * the list is twelve at a time, the map wants every pin in the current
     * viewport. Refetched as the visitor pans, so it is throttled per IP like
     * the address endpoints — it is a public, unauthenticated query that can be
     * asked to scan the whole country.
     */
    public function map()
    {
        // This is the app's densest public egress: one call returns a whole
        // viewport of names, addresses and phone numbers as JSON, no parsing
        // required. Those fields are all public on the listing pages anyway, so
        // the concern is bulk rate, not disclosure — at the old 500 records ×
        // 60/min a single IP could pull 30,000 records a minute and sweep the
        // country by walking the viewport. 200 × 30/min is a fifth of that and
        // still far past what panning a map actually needs: a viewport holding
        // more than 200 businesses is zoomed out far enough that the pins are
        // clustered and individual popups aren't reachable regardless.
        $throttler = service('throttler');
        $key       = 'directory-map-' . md5((string) $this->request->getIPAddress());
        if ($throttler->check($key, 30, MINUTE) === false) {
            return $this->response
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $throttler->getTokentime())
                ->setJSON(['items' => []]);
        }

        $svc   = new DirectoryService();
        $items = $svc->mapPoints($this->searchFilters(), 200);

        helper(['map', 'directory_hours']);

        $out = [];
        foreach ($items as $l) {
            $hours = hours_decode($l['trading_hours'] ?? null);

            $out[] = [
                'id'       => (int) $l['id'],
                'name'     => (string) $l['display_name'],
                'category' => (string) ($l['category_name'] ?? ''),
                'lat'      => (float) $l['latitude'],
                'lng'      => (float) $l['longitude'],
                'address'  => map_address_text($l),
                'phone'    => (string) ($l['phone'] ?? ''),
                'url'      => base_url('directory/' . $l['slug']),
                'logo'     => listing_image_url($l['logo_path'] ?? null),
                'openNow'  => hours_is_open_now($hours),
                'booking'  => ! empty($l['offers_online_booking']),
                'directions' => map_directions_url($l),
                // Metres, or null when the visitor gave no position. The browser
                // decides how to phrase it — see 'approx' below.
                'distance' => isset($l['distance_m']) ? (float) $l['distance_m'] : null,
                // A street/suburb/city pin is a centroid that can sit hundreds of
                // metres off, so any distance measured from it is a rough figure
                // and must not be shown as though it were surveyed.
                'approx'   => ! in_array($l['geocode_precision'] ?? null, ['manual', 'exact'], true),
            ];
        }

        return $this->response->setJSON(['items' => $out]);
    }

    /**
     * The search filters, read once so the HTML page and the map JSON can never
     * disagree about what is being searched.
     *
     * Every value is length-capped. A search term costs far more to answer than
     * to send: each whitespace-separated token joins a boolean-mode FULLTEXT
     * MATCH, four unanchored LIKE '%…%' scans, and a correlated EXISTS across
     * two tables. An uncapped `q` of a few hundred KB is one cheap GET that
     * pins a database core. Nobody searches a directory with more than a few
     * words, so the cap costs real visitors nothing.
     *
     * @return array<string,string>
     */
    private function searchFilters(): array
    {
        $cap = static fn ($v, int $len) => mb_substr(trim((string) $v), 0, $len);

        return [
            'q'        => $cap($this->request->getGet('q'), 100),
            'category' => $cap($this->request->getGet('category'), 190),
            'province' => $cap($this->request->getGet('province'), 100),
            'city'     => $cap($this->request->getGet('city'), 100),
            'lat'      => trim((string) $this->request->getGet('lat')),
            'lng'      => trim((string) $this->request->getGet('lng')),
            'radius'   => trim((string) $this->request->getGet('radius')),
            'bounds'   => trim((string) $this->request->getGet('bounds')),
        ];
    }

    /** /directory/categories — browse every category, grouped, with counts. */
    public function categories()
    {
        $svc = new DirectoryService();
        return view('directory/categories', [
            'groups' => $svc->categoriesGrouped(),
        ]);
    }

    /**
     * /directory/{segment} — a category landing page or a listing profile.
     *
     * Category wins, because category slugs are a closed set that
     * listing_reserved_slugs() keeps listings out of; a profile can therefore
     * never be shadowed by resolving in this order.
     */
    public function segment(string $slug)
    {
        $svc = new DirectoryService();

        $category = $svc->findCategoryBySlug($slug);
        if ($category !== null) {
            return $this->renderLanding($svc, $category, null);
        }

        return $this->show($slug);
    }

    /** /directory/{category}/{province} */
    public function place(string $categorySlug, string $provinceSlug)
    {
        $svc = new DirectoryService();

        $category = $svc->findCategoryBySlug($categorySlug);
        $province = $svc->provinceFromSlug($provinceSlug);
        if ($category === null || $province === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderLanding($svc, $category, $province);
    }

    /**
     * /directory/province/{province} — every published business in a province,
     * whatever its category. The location tier the homepage cards link into.
     */
    public function province(string $slug)
    {
        $svc      = new DirectoryService();
        $province = $svc->provinceFromSlug($slug);
        if ($province === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $page   = (int) ($this->request->getGet('page') ?? 1);
        $result = $svc->browse(['province' => $province], $page);

        // Same rule as renderLanding(): the page always renders (the province is
        // real either way) but stays out of the index until it has enough on it
        // to be worth a crawler's time.
        $min       = (int) config('Directory')->landingMinListings;
        $indexable = (int) $result['total'] >= $min && $page === 1;

        return view('directory/province', [
            'province'   => $province,
            'result'     => $result,
            'indexable'  => $indexable,
            'cityCounts' => $svc->cityCountsForProvince($province),
            'categories' => $svc->topCategoriesInProvince($province),
        ]);
    }

    /**
     * @param array<string,mixed> $category
     */
    private function renderLanding(DirectoryService $svc, array $category, ?string $province)
    {
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $result = $svc->browse([
            'category' => (string) $category['slug'],
            'province' => (string) ($province ?? ''),
        ], $page);

        // Even at zero listings the page still renders — with an empty-state
        // "be the first to list" CTA instead of a dead end — since the category
        // itself is real and valid. It's noindexed until it clears the
        // threshold below, same as any other thin/empty landing page.
        $min        = (int) config('Directory')->landingMinListings;
        $indexable  = (int) $result['total'] >= $min && $page === 1;

        return view('directory/landing', [
            'category'       => $category,
            'province'       => $province,
            'result'         => $result,
            'indexable'      => $indexable,
            'provinceCounts' => $svc->provinceCountsForCategory((int) $category['id']),
            'siblings'       => $svc->categoriesInGroup((string) ($category['group_name'] ?? ''), (int) $category['id']),
        ]);
    }

    public function show(string $slug)
    {
        $svc     = new DirectoryService();
        $listing = $svc->getProfile($slug);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('directory/show', [
            'l'       => $listing,
            'related' => $svc->relatedListings(
                (int) $listing['id'],
                $listing['category_id'] !== null ? (int) $listing['category_id'] : null,
                (string) ($listing['province'] ?? '')
            ),
        ]);
    }

    public function verify(string $token)
    {
        $mut     = new DirectoryListingMutationService();
        $listing = $mut->verify($token);
        if ($listing !== null) {
            return redirect()->to(base_url('directory/' . $listing['slug']))
                ->with('success', 'Your profile is verified and now live.');
        }
        return redirect()->to(base_url('/'))
            ->with('error', 'That verification link is invalid or has expired.');
    }

    /**
     * robots.txt, served dynamically so the Sitemap: line carries the real host
     * from app.baseURL instead of a hardcoded guess.
     */
    public function robots()
    {
        $body = "User-agent: *\n"
            . "Allow: /\n"
            // Owner and admin areas hold no public content and should not be
            // crawled; both are noindex too, this just saves the crawl.
            . "Disallow: /manage\n"
            . "Disallow: /admin\n"
            . "\n"
            . 'Sitemap: ' . base_url('sitemap.xml') . "\n";

        return $this->response->setContentType('text/plain')->setBody($body);
    }

    public function sitemap()
    {
        $xml = cache('directory_sitemap_xml');
        if ($xml === null) {
            $svc  = new DirectoryService();
            $urls = array_merge(
                [
                    ['loc' => base_url('/'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('directory'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('directory/categories'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('list-your-practice'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('faq'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('verified'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('contact'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('privacy'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('terms'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('cookie-policy'), 'lastmod' => date('Y-m-d')],
                ],
                $svc->sitemapLandingUrls((int) config('Directory')->landingMinListings),
                $svc->sitemapProvinceUrls((int) config('Directory')->landingMinListings),
                $svc->sitemapUrls()
            );

            $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $u) {
                $xml .= '  <url><loc>' . esc($u['loc']) . '</loc><lastmod>' . esc($u['lastmod']) . '</lastmod></url>' . "\n";
            }
            $xml .= '</urlset>' . "\n";

            // 15 min TTL, no active invalidation on listing changes — crawlers
            // don't need sub-15-minute freshness, and coupling every listing
            // mutation to sitemap cache-busting isn't worth it for that.
            cache()->save('directory_sitemap_xml', $xml, 900);
        }

        return $this->response
            ->setHeader('Cache-Control', 'public, max-age=900')
            ->setHeader('ETag', md5($xml))
            ->setContentType('application/xml')
            ->setBody($xml);
    }
}
