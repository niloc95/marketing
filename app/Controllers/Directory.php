<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Controllers\Concerns\RedirectsToOwningSite;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingMenuFileModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\DirectoryListingMutationService;
use App\Services\CareersService;
use App\Services\DirectoryService;
use App\Services\DirectorySettings;
use App\Services\JobBoardService;
use App\Services\HeroImageService;
use App\Services\Search\SearchIntent;
use App\Services\ListingMenuService;
use App\Services\VerificationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;
use Config\Sites;

class Directory extends BaseController
{
    use RedirectsToOwningSite;

    use LocksDownDocumentCsp;

    /**
     * Crawlers that collect pages to train AI models. Each one is shut out in
     * robots.txt; the same names are blocked at Cloudflare for the ones that
     * ignore it.
     */
    public const AI_TRAINING_BOTS = [
        'GPTBot', 'CCBot', 'Bytespider', 'ClaudeBot',
        'anthropic-ai', 'Amazonbot', 'meta-externalagent', 'Applebot-Extended',
    ];

    public function home()
    {
        $svc            = new DirectoryService();
        $provinceCounts = $svc->provinceCounts();

        // The location list wants a "Johannesburg · Pretoria · Soweto" line, so
        // each province needs its own city query. Bounded by design: there are
        // nine provinces, and only those holding listings are queried at all.
        $provinceCities = [];
        foreach (array_keys($provinceCounts) as $province) {
            $provinceCities[$province] = array_keys($svc->cityCountsForProvince($province, 3));
        }

        // The Free vs Verified section. DirectorySettings rather than
        // VerificationService, which builds a PayFast client this never needs;
        // same answers as monthlyAmount() and isEnabled(). A settings read that
        // fails costs the section, not the home page (as in signup_cta()).
        try {
            $settings       = new DirectorySettings();
            $pricingOffered = $settings->badgeEnabled();
            $pricingAmount  = $settings->badgePrice();
            // Plus on local.webscheduler.app, where the badge is not sold.
            $plusOnSale = service('site')->isApp() && service('site')->scoping() && $settings->internationalEnabled();
            $plusUsd    = $plusOnSale ? $settings->plusPriceUsd() : null;
            $plusRand   = $plusOnSale ? $settings->plusRandAmount() : null;
        } catch (\Throwable $e) {
            log_message('warning', 'Could not read badge settings for the home pricing section: ' . $e->getMessage());
            $pricingOffered = false;
            $pricingAmount  = '';
            $plusUsd        = null;
            $plusRand       = null;
        }

        return view('directory/home', [
            'featured'       => $svc->featured(8),
            'topCategories'  => $svc->topCategories(8),
            'provinceCounts' => $provinceCounts,
            'provinceCities' => $provinceCities,
            'stats'          => $svc->stats(),
            // Real category-and-town pairs, so no example lands on nothing.
            'examples'       => $svc->searchExamples(6),
            // Cached whole, and an empty array on any failure — the hero falls
            // back to its gradient rather than taking the home page with it.
            'slides'         => (new HeroImageService())->slides(),
            // Null for the photo rotation, and on any read failure.
            'heroBackground' => (new HeroImageService())->background(),
            'pricingOffered' => $pricingOffered,
            'pricingAmount'  => $pricingAmount,
            'plusUsd'        => $plusUsd,
            'plusRand'       => $plusRand,
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

        // $filters stays what the visitor sent — every link on the page is
        // rebuilt from it, so the pager carries "dentist in sandton", not a
        // category the visitor never picked. $effective is what is searched.
        [$effective, $intent] = $this->interpretSearch($svc, $filters);

        // Facets are resolved before the browse rather than after it, unlike
        // the category and province below, because the view has to draw the
        // rail showing exactly which filters were honoured — and a filter the
        // sanitiser dropped but the rail still shows ticked is a bug nobody
        // reports, because the page looks like it worked. Against the category
        // being searched, which may be the one read out of the words.
        $effective['facets'] = $svc->sanitiseFacets($filters['facets'], $effective['category']);
        $filters['facets']   = $effective['facets'];

        $result = $svc->browse($effective, $page);

        // One step back, and said out loud: "dentist in Sandton that does teeth
        // whitening" with no match on the whitening shows Sandton's dentists
        // under a notice saying so, rather than nothing. Only the keywords are
        // dropped — never the category or place, which were understood — and
        // only when something was understood, or dropping the keywords would
        // just be "everything".
        //
        // A category (or main category) has to be among what was understood. A
        // place alone is not enough: "a school in Cape Town" with no category
        // called School would drop the one word that says what is wanted and
        // show every business in Cape Town under it.
        $relaxed = false;
        $categoryFromWords = $effective['category'] !== $filters['category'] || $effective['group'] !== $filters['group'];
        if ((int) $result['total'] === 0 && $effective['q'] !== '' && $categoryFromWords) {
            $wider = $svc->browse(['q' => ''] + $effective, $page);
            if ((int) $wider['total'] > 0) {
                $result  = $wider;
                $relaxed = true;
            }
        }

        // Resolved here rather than in the view, because the view turns these
        // into the <title>, the og:title and a rel=canonical, and then asks for
        // `index, follow`. Left unresolved, ?category=Cheap-Loans-Payday is a
        // 200 with an attacker's words in the title and a canonical pointing
        // wherever they like — unlimited indexable URLs on this domain, minted
        // by anyone who can construct a query string. The sibling landing pages
        // (segment(), place()) have always resolved first; /directory was the
        // one entry point that did not.
        $category = $effective['category'] !== ''
            ? $svc->findCategoryBySlug($effective['category'])
            : null;
        $province = in_array($effective['province'], DirectoryService::SA_PROVINCES, true)
            ? $effective['province']
            : null;
        // An unknown ?group= is dropped outright — browse() already ignored it,
        // and clearing it here keeps it off the pager and every rebuilt link.
        $group = $effective['group'] !== '' ? $svc->findGroupBySlug($effective['group']) : null;
        if ($group === null) {
            $filters['group'] = '';
        }

        // Vacancies matching the words as typed, above the business results
        // on the first page only. $filters['q'], not $effective['q']: the
        // interpreter takes "medical" out of "Junior Medical Admin Assistant"
        // as a category, and the job is found by its whole title.
        $jobs     = new JobBoardService();
        $careers  = new CareersService();
        $typed    = $page <= 1 ? (string) $filters['q'] : '';

        return view('directory/index', [
            'result'      => $result,
            'vacancies'   => $typed === '' ? [] : $jobs->discover($typed, 3),
            'roles'       => $typed === '' || service('site')->isApp() ? [] : $careers->discover($typed, 2),
            'jobs'        => $jobs,
            'careers'     => $careers,
            'filters'     => $filters,
            // What the words were read as, or null for a literal search. The
            // view names each part, with a link to take it away.
            'intent'      => $intent,
            'relaxed'     => $relaxed,
            'place'       => $effective['place'] !== '' ? $effective['place'] : null,
            // Null means "asked for, but no such thing" — the view must not
            // treat that page as indexable or name it after the raw input.
            'category'    => $category,
            'group'       => $group,
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
        //
        // Since Oct 2026 a pin carries no phone and no row id (the popup links
        // to the profile, which has both), the cap is 100, and an hourly budget
        // sits on top of the per minute one: 300 pans an hour is far past a
        // person browsing and stops one IP walking the country's viewports.
        $throttler = service('throttler');
        $ip        = md5((string) $this->request->getIPAddress());
        if ($throttler->check('directory-map-' . $ip, 30, MINUTE) === false
            || $throttler->check('directory-map-hour-' . $ip, 300, HOUR) === false) {
            return $this->response
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $throttler->getTokentime())
                ->setJSON(['items' => []]);
        }

        $svc = new DirectoryService();
        // Read exactly as index() reads it, so the pins are the list's.
        $sent               = $this->searchFilters();
        [$filters]          = $this->interpretSearch($svc, $sent);
        // The list widened past the keywords (see index()) and told the map so
        // through data-map-query. Honoured only where index() would have done
        // the same; on its own, a crafted ?relaxed=1 can only show more pins
        // of a search that is already public.
        if ($this->request->getGet('relaxed') === '1'
            && ($filters['category'] !== $sent['category'] || $filters['group'] !== $sent['group'])) {
            $filters['q'] = '';
        }
        $filters['facets'] = $svc->sanitiseFacets($filters['facets'], $filters['category']);
        $items             = $svc->mapPoints($filters, 100);

        helper('directory_hours');

        $out = [];
        foreach ($items as $l) {
            // Each pin's own clock: on .app one map can span time zones.
            hours_context($l);
            $hours = hours_decode($l['trading_hours'] ?? null);

            $out[] = [
                'name'     => (string) $l['display_name'],
                'category' => (string) ($l['category_name'] ?? ''),
                'lat'      => (float) $l['latitude'],
                'lng'      => (float) $l['longitude'],
                'address'  => map_address_text($l),
                'url'      => base_url('directory/' . $l['slug']),
                'logo'     => listing_image_url($l['logo_path'] ?? null),
                // Holiday aware: on a public holiday this follows the owner's
                // holiday answer, and is null (no badge) without one.
                'openNow'  => hours_open_now_line($hours, hours_holiday_rule($l['trading_hours'] ?? null)),
                // The link itself, not the flag: a Book button needs somewhere to
                // go, and the flag alone used to send people to a #book anchor
                // no profile rendered.
                'bookingUrl' => safe_external_url($l['booking_url'] ?? ''),
                'directions' => map_directions_url($l),
                // Metres, or null when the visitor gave no position. The browser
                // decides how to phrase it — see 'approx' below.
                'distance' => isset($l['distance_m']) ? (float) $l['distance_m'] : null,
                // A street/suburb/city pin is a centroid that can sit hundreds of
                // metres off, so any distance measured from it is a rough figure
                // and must not be shown as though it were surveyed.
                'approx'   => ! in_array($l['geocode_precision'] ?? null, ['manual', 'exact'], true),
                // Only the middle of the suburb or city is known, so the map
                // draws an area dot rather than a pin. See map_area_radius().
                'area'     => map_area_radius($l['geocode_precision'] ?? null) !== null,
            ];
        }

        hours_context();

        return $this->response->setJSON(['items' => $out]);
    }

    /**
     * Typeahead suggestions for the search boxes, as JSON.
     *
     * Its own throttle key, not directory-index-. Sharing that one would mean a
     * visitor's own typing spending the 60/min budget their search page needs,
     * and the first thing they would see is their results 429ing.
     *
     * Over the limit answers an empty list with a 200, the way AddressSuggest
     * does. A suggestion list is incidental: showing nothing for a moment is
     * the right failure, where an error would be noise about something the
     * visitor did not ask for. The form underneath still submits.
     *
     * 30/min is generous against the client's 3-character floor and 450ms
     * debounce — that is roughly one request per word typed, not per keystroke.
     */
    public function suggest()
    {
        $throttler = service('throttler');
        if ($throttler->check('directory-suggest-' . md5((string) $this->request->getIPAddress()), 30, MINUTE) === false) {
            return $this->response->setJSON(['items' => []]);
        }

        // Through searchFilters() so this can never read a longer `q` than the
        // page it belongs to.
        $q = $this->searchFilters()['q'];

        // Vacancies take up to three of the eight rows, after the businesses
        // and people: a job title typed in full ("Junior Medical Admin
        // Assistant") otherwise offered nothing at all.
        $vacancies = $this->vacancySuggestions($q);
        $items     = (new DirectoryService())->suggest($q, 8 - count($vacancies));

        return $this->response->setJSON(['items' => array_merge($items, $vacancies)]);
    }

    /**
     * Rows for suggest(): live jobs, then our own open careers roles on the
     * .co.za site (careers is South African hiring only).
     *
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function vacancySuggestions(string $q): array
    {
        $jobs = new JobBoardService();
        $out  = [];
        foreach ($jobs->discover($q, 2) as $post) {
            $out[] = [
                'type'  => 'job',
                'label' => (string) $post['title'],
                'sub'   => implode(' · ', array_filter([$jobs->hiringName($post), $jobs->placeText($post)])),
                'url'   => $jobs->url($post),
            ];
        }
        if (! service('site')->isApp()) {
            $careers = new CareersService();
            foreach ($careers->discover($q, 1) as $role) {
                $out[] = [
                    'type'  => 'job',
                    'label' => (string) $role['title'],
                    'sub'   => 'WebScheduler careers · ' . (implode(' or ', $careers->locationsOf($role)) ?: 'South Africa'),
                    'url'   => $careers->url($role),
                ];
            }
        }

        return $out;
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
     * @return array<string,mixed> every value a string but 'facets', which is
     *                               the raw ?f[] array awaiting sanitiseFacets()
     */
    private function searchFilters(): array
    {
        $cap = static fn ($v, int $len) => mb_substr(trim((string) $v), 0, $len);

        // local.webscheduler.app's country and region, from the city chips on
        // its country and region pages. Only values Config\Countries knows
        // survive, so neither can put arbitrary text into a query or a title.
        $countries = config('Countries');
        $country   = strtoupper($cap($this->request->getGet('country'), 2));
        $country   = $country !== 'ZA' && $countries->isSupported($country) ? $country : '';
        $region    = $cap($this->request->getGet('region'), 120);
        $region    = $country !== '' && in_array($region, $countries->regions($country), true) ? $region : '';

        return [
            'country'  => $country,
            'region'   => $region,
            'q'        => $cap($this->request->getGet('q'), 100),
            // A main-category slug (DirectoryCategoryGroupModel). Broader than
            // category and combinable with it; an unknown one is ignored.
            'group'    => $cap($this->request->getGet('group'), 120),
            'category' => $cap($this->request->getGet('category'), 190),
            'province' => $cap($this->request->getGet('province'), 100),
            'city'     => $cap($this->request->getGet('city'), 100),
            'lat'      => trim((string) $this->request->getGet('lat')),
            'lng'      => trim((string) $this->request->getGet('lng')),
            'radius'   => trim((string) $this->request->getGet('radius')),
            'bounds'   => trim((string) $this->request->getGet('bounds')),
            // Raw here, and whitelisted by DirectoryService::sanitiseFacets()
            // against the category before either the query or the rail sees it:
            // what a facet may be called and may hold depends on the category,
            // which this method does not resolve. The caps are only to stop a
            // crafted query string making the sanitiser walk a huge array.
            'facets'   => $this->facetParams(),
            'sort'     => $this->sortParam(),
            // ?holidays=1: open on public holidays (DirectoryService::browse()).
            // A string for the same pager reason as exact below.
            'holidays' => $this->request->getGet('holidays') === '1' ? '1' : '',
            // ?exact=1: search the words as typed, uninterpreted. A string,
            // not a bool, because the pager rebuilds the URL from this array
            // and http_build_query() would write a false as "exact=0".
            'exact'    => $this->request->getGet('exact') === '1' ? '1' : '',
            // Never read from the URL. Only interpretSearch() sets it, from
            // the words; the pager carries the words.
            'place'    => '',
        ];
    }

    /**
     * Read the typed search into filters: "dentist in Sandton that does teeth
     * whitening" becomes category=dentist, place=Sandton, q="teeth whitening".
     *
     * What the visitor chose outright wins over anything read from the words:
     * a ?category= picked from the select is never replaced by one guessed
     * from q. The words are left alone entirely when:
     *   - ?exact=1 asks for that (the "search the exact words" link)
     *   - a business is called what was typed — "Bob the Builder Plumbing" is
     *     a name to find, not a request for builders (hasListingNamed())
     *
     * @param array<string,mixed> $filters from searchFilters()
     * @return array{0: array<string,mixed>, 1: ?SearchIntent} the filters to
     *         search with, and what the words were read as (null: literal)
     */
    private function interpretSearch(DirectoryService $svc, array $filters): array
    {
        if ($filters['q'] === '' || $filters['exact'] === '1') {
            return [$filters, null];
        }

        $intent = Services::queryInterpreter()->interpret($filters['q']);
        if ($intent->hasFilters() && $svc->hasListingNamed($filters['q'])) {
            return [$filters, null];
        }

        $effective = $filters;
        // A part the visitor overrode outright goes back to being words: "dentist"
        // typed with Plumber picked from the select searches plumbers for
        // "Dentist", rather than the word silently disappearing.
        $overridden = [];

        if ($filters['category'] === '' && $filters['group'] === '') {
            $effective['category'] = $intent->categorySlug ?? '';
            $effective['group']    = $intent->groupSlug ?? '';
        } else {
            $overridden[] = $intent->categoryName ?? $intent->groupName ?? '';
        }
        if ($intent->province !== null && service('site')->isApp()) {
            // On local.webscheduler.app the "province" read from the words is a
            // country or one of its regions (Config\Search::forSite()).
            [$country, $region] = $this->countryOrRegion($intent->province);
            if ($country !== null && $filters['country'] === '' && $filters['region'] === '') {
                $effective['country'] = $country;
                $effective['region']  = $region ?? '';
            } else {
                $overridden[] = $intent->province;
            }
        } elseif ($intent->province !== null) {
            if ($filters['province'] === '') {
                $effective['province'] = $intent->province;
            } else {
                $overridden[] = $intent->province;
            }
        }
        if ($intent->place !== null) {
            if ($filters['city'] === '') {
                $effective['place'] = $intent->place;
            } else {
                $overridden[] = $intent->place;
            }
        }

        // Only filler ("find me a good") leaves no keywords and nothing
        // understood; searching the raw words then is no worse than searching
        // nothing. "near me" alone is understood — it asks for the location
        // button, not for listings that use the word "near".
        if ($intent->keywords() !== '' || $intent->hasFilters() || $intent->nearMe) {
            $effective['q'] = trim(implode(' ', array_filter([...$overridden, $intent->keywords()])));
        }

        return [$effective, $intent];
    }

    /**
     * A name read out of a search on .app, as [country code, region]: a
     * country name gives [code, null], a region gives its country and
     * itself, anything else [null, null].
     *
     * @return array{0:?string,1:?string}
     */
    private function countryOrRegion(string $name): array
    {
        $countries = config('Countries');
        $code      = $countries->codeForName($name);
        if ($code !== null && $code !== 'ZA' && $countries->isSupported($code)) {
            return [$code, null];
        }
        foreach ($countries->profiles as $code => $profile) {
            if ($code !== 'ZA' && in_array($name, $profile['regions'], true)) {
                return [$code, $name];
            }
        }

        return [null, null];
    }

    /**
     * ?sort=new, or '' for the default editorial order.
     *
     * A whitelist of one. Yelp's "New Restaurants", for every category: the
     * default order ranks by profile quality, which is right for "who is good"
     * and wrong for "what opened lately", where the answer is the newest first.
     */
    private function sortParam(): string
    {
        return $this->request->getGet('sort') === 'new' ? 'new' : '';
    }

    /**
     * ?f[curriculum][]=ieb&f[ages]=36, capped but not yet validated.
     *
     * @return array<string,mixed>
     */
    private function facetParams(): array
    {
        $raw = $this->request->getGet('f');
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_slice($raw, 0, 8, true) as $key => $value) {
            if (! is_string($key) || mb_strlen($key) > 40) {
                continue;
            }
            $out[$key] = is_array($value)
                ? array_slice(array_filter($value, 'is_string'), 0, 20)
                : mb_substr(trim((string) $value), 0, 60);
        }

        return $out;
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

        // /directory/united-kingdom: a country page on local.webscheduler.app.
        $code = config('Countries')->codeForSlug($slug);
        if ($code !== null && $code !== 'ZA' && service('site')->scoping()) {
            return $this->renderCountry($svc, $code, null);
        }

        return $this->show($slug);
    }

    /** /directory/{category}/{province} */
    public function place(string $categorySlug, string $provinceSlug)
    {
        $svc = new DirectoryService();

        // /directory/plumbers/united-kingdom: one category in a country, on .app.
        $countryCode = config('Countries')->codeForSlug($provinceSlug);
        if ($countryCode !== null && $countryCode !== 'ZA' && service('site')->scoping()) {
            $category = $svc->findCategoryBySlug($categorySlug);
            if ($category === null) {
                throw PageNotFoundException::forPageNotFound();
            }

            return $this->renderLanding($svc, $category, null, $this->placeFor($countryCode, null));
        }

        // /directory/united-kingdom/scotland: a region page on .app.
        $code = config('Countries')->codeForSlug($categorySlug);
        if ($code !== null && $code !== 'ZA' && service('site')->scoping()) {
            $region = config('Countries')->regionFromSlug($code, $provinceSlug);
            if ($region === null) {
                throw PageNotFoundException::forPageNotFound();
            }

            return $this->renderCountry($svc, $code, $region);
        }

        $category = $svc->findCategoryBySlug($categorySlug);
        $province = $svc->provinceFromSlug($provinceSlug);
        if ($category === null || $province === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        // A South African province page belongs on co.za.
        if (service('site')->isApp() && ($moved = $this->redirectToSite(Sites::ZA)) !== null) {
            return $moved;
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
     * A local.webscheduler.app country page (/directory/{country}) or region
     * page (/directory/{country}/{region}): what province() is on co.za, for
     * every other country with a profile in Config\Countries. Its own site
     * only; asked for on co.za it 301s to .app.
     */
    private function renderCountry(DirectoryService $svc, string $code, ?string $region)
    {
        if (! service('site')->isApp()) {
            return $this->redirectToSite(Sites::APP) ?? throw PageNotFoundException::forPageNotFound();
        }

        $page   = (int) ($this->request->getGet('page') ?? 1);
        $result = $svc->browse(['country' => $code, 'region' => $region ?? ''], $page);
        $min    = (int) config('Directory')->landingMinListings;

        return view('directory/country', [
            'code'       => $code,
            'country'    => (string) config('Countries')->nameForCode($code),
            'slug'       => (string) config('Countries')->profile($code)['slug'],
            'region'     => $region,
            'result'     => $result,
            'indexable'  => (int) $result['total'] >= $min && $page === 1,
            'regions'    => $region === null ? $svc->regionCounts($code) : [],
            'cityCounts' => $svc->cityCountsIn($code, $region),
            'categories' => $svc->topCategoriesIn($code, $region),
        ]);
    }

    /**
     * /directory/at/{venue} — every published business in one complex, mall or
     * building.
     *
     * The `at/` prefix is deliberate. A bare segment would put venue slugs into
     * the same namespace as categories and listings, where segment() already
     * resolves category-then-listing; "at" is reserved instead, so a venue can
     * never shadow a business or be shadowed by one.
     *
     * ?category= narrows within the venue — the chips the view renders. It
     * reaches browse() as an ordinary filter, so paging and everything else
     * behave as they do on any other listing page.
     */
    public function venue(string $slug)
    {
        $svc   = new DirectoryService();
        $venue = $svc->findVenueBySlug($slug);
        if ($venue === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        if (($moved = $this->redirectToOwningSite($venue)) !== null) {
            return $moved;
        }

        $page     = (int) ($this->request->getGet('page') ?? 1);
        $filters  = $this->searchFilters();
        $category = $filters['category'];
        $q        = $filters['q'];
        $facets   = $svc->sanitiseFacets($filters['facets'], $category);
        $result   = $svc->browse([
            'venue'    => (int) $venue['id'],
            'category' => $category,
            'q'        => $q,
            'facets'   => $facets,
        ], $page);

        // Same rule as every other landing tier: the page always renders, but
        // stays out of the index until there is enough on it to be worth a
        // crawler's time. A filtered view is never indexable — that is the thin
        // duplicate of the venue page itself.
        $min       = (int) config('Directory')->landingMinListings;
        $indexable = (int) $result['total'] >= $min && $page === 1 && $category === '' && $q === '';

        return view('directory/venue', [
            'venue'      => $venue,
            'result'     => $result,
            'indexable'  => $indexable,
            'category'   => $category,
            'q'          => $q,
            'categories' => $svc->categoryCountsForVenue((int) $venue['id']),
            'total'      => $svc->venueListingCount((int) $venue['id']),
            'placeProfile' => $svc->placeProfileFor($venue),
        ]);
    }

    /**
     * @param array<string,mixed> $category
     */
    /**
     * /directory/{category}/{country}/{region}: one category in one region,
     * on local.webscheduler.app.
     */
    public function categoryInRegion(string $categorySlug, string $countrySlug, string $regionSlug)
    {
        $svc      = new DirectoryService();
        $category = $svc->findCategoryBySlug($categorySlug);
        $code     = config('Countries')->codeForSlug($countrySlug);
        $region   = $code === null ? null : config('Countries')->regionFromSlug($code, $regionSlug);
        if ($category === null || $code === null || $code === 'ZA' || $region === null || ! service('site')->scoping()) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderLanding($svc, $category, null, $this->placeFor($code, $region));
    }

    /**
     * A country, or a region of one, as the category landing page names it.
     *
     * @return array{country:string,region:?string,name:string,path:string,countryName:string,countryPath:string}
     */
    private function placeFor(string $code, ?string $region): array
    {
        $countries = config('Countries');
        $slug      = (string) $countries->profile($code)['slug'];
        $name      = (string) $countries->nameForCode($code);

        return [
            'country'     => $code,
            'region'      => $region,
            'name'        => $region ?? $name,
            'path'        => $slug . ($region !== null ? '/' . slugify($region) : ''),
            'countryName' => $name,
            'countryPath' => $slug,
        ];
    }

    private function renderLanding(DirectoryService $svc, array $category, ?string $province, ?array $place = null)
    {
        // A country or region landing lives on local.webscheduler.app only.
        if ($place !== null && ! service('site')->isApp()) {
            return $this->redirectToSite(Sites::APP) ?? throw PageNotFoundException::forPageNotFound();
        }

        $page = (int) ($this->request->getGet('page') ?? 1);

        // The landing page is where facets earn their keep: it is already
        // scoped to one category, which is the only scope in which "IEB" or
        // "takes a 3-year-old" means anything.
        $facets = $svc->sanitiseFacets($this->facetParams(), (string) $category['slug']);
        $sort   = $this->sortParam();

        $result = $svc->browse([
            'category' => (string) $category['slug'],
            'province' => (string) ($province ?? ''),
            'country'  => (string) ($place['country'] ?? ''),
            'region'   => (string) ($place['region'] ?? ''),
            'facets'   => $facets,
            'sort'     => $sort,
        ], $page);

        // Even at zero listings the page still renders — with an empty-state
        // "be the first to list" CTA instead of a dead end — since the category
        // itself is real and valid. It's noindexed until it clears the
        // threshold below, same as any other thin/empty landing page.
        //
        // A faceted view is never indexable, for the reason the venue page
        // gives: it is a thin duplicate of the landing page itself, and the
        // combinations multiply without limit. A re-sorted view is the same
        // page in a different order — a duplicate by definition.
        $min        = (int) config('Directory')->landingMinListings;
        $indexable  = (int) $result['total'] >= $min && $page === 1 && $facets === [] && $sort === '';

        return view('directory/landing', [
            'category'       => $category,
            'province'       => $province,
            'place'          => $place,
            // .app links its category pages by country, then by region.
            'placeCounts'    => service('site')->isApp()
                ? $svc->placeCountsForCategory((int) $category['id'], $place['country'] ?? null)
                : [],
            'result'         => $result,
            'facets'         => $facets,
            'sort'           => $sort,
            'indexable'      => $indexable,
            'provinceCounts' => $svc->provinceCountsForCategory((int) $category['id']),
            'siblings'       => $svc->categoriesInGroup((string) ($category['group_name'] ?? ''), (int) $category['id']),
        ]);
    }

    protected function show(string $slug)
    {
        $svc     = new DirectoryService();
        $listing = $svc->getProfile($slug);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (($moved = $this->redirectToOwningSite($listing)) !== null) {
            return $moved;
        }

        // "Open now", "Today" and the holiday line on this business's clock.
        hours_context($listing);

        return view('directory/show', [
            'l'       => $listing,
            // Explicit, not omitted: the renderer keeps data between renders,
            // and a public page must never inherit a preview's approve bar.
            'preview' => null,
            'related' => $svc->relatedListings(
                (int) $listing['id'],
                $listing['category_id'] !== null ? (int) $listing['category_id'] : null,
                (string) ($listing['province'] ?? '')
            ),
        ]);
    }

    /**
     * A food listing's PDF menu, streamed from outside the docroot.
     *
     * The same headers the admin document viewer sends, for the same reason: the
     * bytes are the uploader's, the origin is ours. nosniff stops a browser
     * deciding our application/pdf is really HTML; the sandboxed CSP stops it
     * doing anything if it did. Unlike an ID document this is public, so it may
     * be cached — for an hour, short enough that a replaced menu shows up the
     * same day.
     *
     * 404 for anything that is not a published food listing with a PDF menu, so
     * a listing that has since moved out of the food group stops serving its old
     * menu without anyone having to delete it.
     */
    public function menu(string $slug)
    {
        $listing = (new DirectoryListingModel())->findPublishedBySlug($slug);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        if (($moved = $this->redirectToOwningSite($listing)) !== null) {
            return $moved;
        }

        $category = ! empty($listing['category_id'])
            ? (new DirectoryCategoryModel())->find((int) $listing['category_id'])
            : null;
        if (! ListingMenuService::offersMenu($category['group_name'] ?? null, $category['slug'] ?? null)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $row  = (new DirectoryListingMenuFileModel())->pdfFor((int) $listing['id']);
        $full = $row !== null ? (new ListingMenuService())->pdfPath($row) : null;
        if ($full === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $contents = (string) file_get_contents($full);

        $this->lockDownCspForDocument();

        // The slug is [a-z0-9-] by construction, so it is safe in a header as-is;
        // the uploader's own filename never goes near one.
        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Content-Disposition', 'inline; filename="' . $listing['slug'] . '-menu.pdf"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Robots-Tag', 'noindex')
            ->setHeader('Cache-Control', 'public, max-age=3600')
            ->setBody($contents);
    }

    /**
     * A profile's email address, fetched when a visitor taps "Show email".
     *
     * The address used to sit in the page reversed (strrev), which stopped
     * nothing: any scraper reading the HTML could reverse it back. Now it is
     * not in the page at all, and this endpoint gives out only 20 an hour per
     * IP, which is plenty for a person and useless for a harvest.
     *
     * ?branch={id} reads one of the listing's branches instead, on the same
     * terms the profile shows them (premium only, and only its own branches).
     */
    public function email(string $slug)
    {
        $throttler = service('throttler');
        if ($throttler->check('directory-email-' . md5((string) $this->request->getIPAddress()), 20, HOUR) === false) {
            return $this->response
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $throttler->getTokentime())
                ->setJSON(['email' => '']);
        }

        $listing = (new DirectoryListingModel())->findPublishedBySlug($slug);
        $email   = '';
        if ($listing !== null) {
            $branch = (string) $this->request->getGet('branch');
            if ($branch === '') {
                $email = (string) ($listing['email'] ?? '');
            } elseif (ctype_digit($branch) && listing_has_premium($listing)) {
                $row   = (new DirectoryPracticeLocationModel())->findForListing((int) $branch, (int) $listing['id']);
                $email = (string) ($row['email'] ?? '');
            }
        }

        return $this->response
            ->setStatusCode($email === '' ? 404 : 200)
            ->setHeader('X-Robots-Tag', 'noindex')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['email' => $email]);
    }

    /**
     * The owner's private preview of a pending profile, before it is live: the
     * real profile page, rendered from the pending row the token belongs to,
     * with an approve bar on top. Reached from a preview we sent (see
     * sendPreview()) and from the usual verification email, which links here
     * rather than publishing on the click.
     *
     * The token is a credential in the URL, so the page is kept out of
     * everything that would copy it somewhere: noindex, no caching, no
     * referrer on outbound links, and no analytics (layouts/public.php).
     */
    public function preview(string $token)
    {
        $row = (new DirectoryListingMutationService())->findByPreviewToken($token);
        if ($row === null) {
            return $this->previewGone();
        }
        if (($moved = $this->redirectToOwningSite($row)) !== null) {
            return $moved;
        }

        $svc     = new DirectoryService();
        $listing = $svc->profileFor($row);
        hours_context($listing);

        $this->privatePage();

        return view('directory/show', [
            'l'       => $listing,
            'related' => [],
            'preview' => [
                'mode'    => 'approve',
                'token'   => $token,
                // "Ask for changes" is for a profile we built: an owner who
                // filled in the form themselves edits it after approving. Only
                // the public signup records accepted terms.
                'changes' => empty($row['terms_accepted_at']),
            ],
        ]);
    }

    /**
     * Approve = verify: the same transition, sign in and next step as the
     * link in the usual verification email, by handing over to verify().
     */
    public function previewApprove(string $token)
    {
        if ((new DirectoryListingMutationService())->findByPreviewToken($token) === null) {
            return $this->previewGone();
        }

        return $this->verify($token);
    }

    public function previewChanges(string $token)
    {
        $sent = (new DirectoryListingMutationService())
            ->requestPreviewChanges($token, (string) $this->request->getPost('message'));
        if (! $sent && (new DirectoryListingMutationService())->findByPreviewToken($token) === null) {
            return $this->previewGone();
        }

        return redirect()->to(base_url('preview/' . $token))->with(
            $sent ? 'success' : 'error',
            $sent ? 'Thanks, we have your note. We will make the changes and let you know.' : 'Please tell us what you would like changed.'
        );
    }

    /** An unknown, expired or already approved preview link. */
    private function previewGone()
    {
        $this->privatePage();
        $this->response->setStatusCode(410);

        return view('directory/preview_gone');
    }

    /**
     * Confirm the emailed signup link: publish the listing, and sign the owner in.
     *
     * The sign-in is deliberate. This token proves control of the listing's email
     * address — the identical proof a manage token gives — it is single use, and
     * MutationService::verify() has already cleared it by the time we get here.
     * Without it an owner who wanted the verified badge had to go straight back
     * out to the inbox for a manage link, having just clicked a link from that
     * same inbox.
     *
     * The redirect fires before anything renders, so the token never sits on a
     * page with outbound links to leak through Referer — the concern noted on
     * Manage::redeem(), handled here the same way.
     */
    public function verify(string $token)
    {
        $mut     = new DirectoryListingMutationService();
        $listing = $mut->verify($token);
        if ($listing !== null) {
            // regenerate(true) — the session is about to carry authority, so the
            // pre-authentication id must die with it. See Manage::redeem().
            session()->regenerate(true);
            session()->set(Manage::SESSION_KEY, (int) $listing['id']);

            // A listing outside South Africa is verified but not yet published:
            // verify() left it 'pending' because the International Listing
            // subscription is what publishes it. Sending them to their profile
            // would be sending them to a 404, so they go to checkout, already
            // signed in, with the subscription row opened for them.
            //
            // Both of the reasons this can be false are handled: the plan being
            // switched off is not a foreign listing at all by then (mayPublish()
            // returns true and we never get here), and PayFast having no
            // credentials leaves them on the dashboard, where the EFT route and
            // an explanation live.
            $verification = new VerificationService();
            if (! $verification->mayPublish($listing)) {
                $verification->ensureInternationalSubscription((int) $listing['id']);

                $to = $verification->canTakePayment() ? 'manage/verification/checkout' : 'manage/edit';

                return redirect()->to(base_url($to))
                    ->with('success', 'Your email is confirmed and you are signed in. '
                        . 'Businesses outside South Africa need an International Profile '
                        . 'subscription before the profile goes live. This is the last step.');
            }

            // The same moment on local.webscheduler.app, where the upgrade is
            // Plus rather than the badge: a business abroad lands on the Plus
            // panel, unless it already holds Plus.
            if ($verification->plusAvailableFor($listing) && ! listing_subscription_active($listing)) {
                $usd = $verification->plusPriceUsd();

                return redirect()->to(base_url('manage/edit') . '#get-plus')
                    ->with('success', 'Your business profile is live and you are signed in. Want more on show? '
                        . 'Plus adds your other locations, your team and job vacancies'
                        . ($usd !== null ? ' for ' . money($usd, 'USD') . ' a month' : '')
                        . '. Your business profile stays free either way.');
            }

            // The moment they are most likely to say yes: signed in, listing just
            // live. With the badge on sale and no application yet (none attached
            // at signup), go straight to the badge panel on the dashboard instead
            // of the profile. Someone who already applied goes to their profile.
            if ($verification->isEnabled() && $verification->forListing((int) $listing['id']) === null) {
                return redirect()->to(base_url('manage/edit') . '#get-verified')
                    ->with('success', 'Your business profile is live. Next: Get Verified. '
                        . 'Send your company registration and the owner\'s ID below. There is nothing '
                        . 'to pay until we have checked them, and your business profile stays free either way.');
            }

            return redirect()->to(base_url('directory/' . $listing['slug']))
                ->with('success', 'Your profile is verified and now live. You are signed in. '
                    . ($verification->isEnabled()
                        ? 'Use Manage your profile to edit it or apply for the Verified Business badge.'
                        : 'Use Manage your profile to edit it.'));
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
        // AI training crawlers copy the whole platform into datasets and send
        // no visitors back, so they get nothing. AI answer bots (OAI-SearchBot,
        // ChatGPT-User, PerplexityBot) link to the profile and stay allowed
        // under the wildcard group.
        $body = '';
        foreach (self::AI_TRAINING_BOTS as $bot) {
            $body .= "User-agent: {$bot}\nDisallow: /\n\n";
        }

        $body .= "User-agent: *\n"
            . "Allow: /\n"
            // Owner and admin areas hold no public content and should not be
            // crawled; both are noindex too, this just saves the crawl.
            . "Disallow: /manage\n"
            . "Disallow: /jobs/manage\n"
            . "Disallow: /admin\n"
            // JSON feeds behind the map and the typeahead: nothing to index,
            // and exactly the bulk shape a scraper wants.
            . "Disallow: /directory/map\n"
            . "Disallow: /directory/suggest\n"
            . "\n"
            . 'Sitemap: ' . base_url('sitemap.xml') . "\n";

        return $this->response->setContentType('text/plain')->setBody($body);
    }

    public function sitemap()
    {
        $xml = cache(site_cache_key('directory_sitemap_xml'));
        if ($xml === null) {
            $svc      = new DirectoryService();
            $min      = (int) config('Directory')->landingMinListings;
            $profiles = $svc->sitemapUrls();
            $jobs     = (new JobBoardService())->sitemapUrls();
            // Our own open roles; drafts and closed roles are left out.
            // co.za's, like every page in Config\Sites::$zaOnlyPaths.
            $roles = service('site')->isApp() ? [] : (new \App\Services\CareersService())->sitemapUrls();
            $app   = service('site')->isApp();

            // A lastmod that is always today tells a crawler nothing, and it
            // learns to ignore it. Fixed pages date from their view's last
            // change; pages that show profiles, vacancies or roles date from
            // the newest of those too.
            $fixed = [
                ''                     => [['directory/home'], $profiles],
                'directory'            => [['directory/index'], $profiles],
                'directory/categories' => [['directory/categories'], $profiles],
                'add-profile'          => [['directory/form']],
                'faq'                  => [['directory/faq']],
                'company'              => [['directory/company']],
                'verified'             => [['directory/verified']],
                'compare'              => [['directory/compare']],
                'resources'            => [['directory/resources', 'public/assets/documents']],
                'media'                => [['directory/media']],
                'profile-setup'        => [['directory/profile_setup']],
                'partners'             => [['directory/partners']],
                'careers'              => [['directory/careers'], $roles],
                'jobs'                 => [['jobs/index'], $jobs],
                'contact'              => [['directory/contact']],
                'privacy'              => [[$app ? 'legal/app/privacy' : 'legal/privacy']],
                'terms'                => [[$app ? 'legal/app/terms' : 'legal/terms']],
                'cookie-policy'        => [['legal/cookies']],
            ];
            if ($app) {
                // .app's twin of /verified, which the filter below takes out.
                $fixed['plus'] = [['directory/plus']];
            }

            $pages = [];
            foreach ($fixed as $path => $entry) {
                [$views, $rows] = $entry + [1 => []];
                $dates   = array_column($rows, 'lastmod');
                $dates[] = $this->viewLastmod($views);
                $pages[] = ['loc' => base_url($path === '' ? '/' : $path), 'lastmod' => max($dates)];
            }

            $urls = array_merge(
                $pages,
                $svc->sitemapLandingUrls($min),
                $svc->sitemapProvinceUrls($min),
                $svc->sitemapCountryUrls($min),
                $svc->sitemapVenueUrls($min),
                $profiles,
                // Live vacancies only; service requests are noindex.
                $jobs,
                $roles
            );

            // A sitemap lists its own host's pages only: on local.webscheduler.app,
            // co.za's pages would be URLs that 301 elsewhere.
            if ($app) {
                $sites = config(Sites::class);
                $urls  = array_values(array_filter($urls, static fn (array $u): bool => ! $sites->isZaOnly(
                    ltrim((string) parse_url($u['loc'], PHP_URL_PATH), '/')
                )));
            }

            $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $u) {
                $xml .= '  <url><loc>' . esc($u['loc']) . '</loc><lastmod>' . esc($u['lastmod']) . '</lastmod></url>' . "\n";
            }
            $xml .= '</urlset>' . "\n";

            // 15 min TTL, no active invalidation on listing changes — crawlers
            // don't need sub-15-minute freshness, and coupling every listing
            // mutation to sitemap cache-busting isn't worth it for that.
            cache()->save(site_cache_key('directory_sitemap_xml'), $xml, 900);
        }

        return $this->response
            ->setHeader('Cache-Control', 'public, max-age=900')
            ->setHeader('ETag', md5($xml))
            ->setContentType('application/xml')
            ->setBody($xml);
    }

    /** view-lastmod.json, read once per request by viewLastmod(). */
    private ?array $viewLastmods = null;

    /**
     * The newest last-change date of these views (names as view() takes them)
     * or public/ paths, files or folders. The release bundle's
     * view-lastmod.json holds each file's last commit date, since every file in
     * it carries the build's mtime; without one (local dev) filemtime() stands in.
     *
     * @param list<string> $paths
     */
    private function viewLastmod(array $paths): string
    {
        if ($this->viewLastmods === null) {
            $file               = ROOTPATH . 'view-lastmod.json';
            $this->viewLastmods = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        }
        $manifest = $this->viewLastmods;

        $dates = [];
        foreach ($paths as $p) {
            $public = str_starts_with($p, 'public/');
            $rel    = $public ? $p : 'app/Views/' . $p . '.php';
            if ($manifest !== []) {
                foreach ($manifest as $f => $d) {
                    if ($f === $rel || str_starts_with($f, $rel . '/')) {
                        $dates[] = $d;
                    }
                }
                continue;
            }
            $abs   = $public ? FCPATH . substr($rel, 7) : ROOTPATH . $rel;
            $files = is_dir($abs) ? (glob($abs . '/*') ?: []) : [$abs];
            foreach ($files as $f) {
                if (is_file($f)) {
                    $dates[] = date('Y-m-d', (int) filemtime($f));
                }
            }
        }

        return $dates === [] ? date('Y-m-d') : max($dates);
    }
}
