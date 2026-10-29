<?php

namespace App\Services;

use App\Libraries\Geocoding\NominatimGeocoder;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingFacetModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryListingTeamModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Models\DirectoryCategoryGroupModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryTagModel;
use App\Models\DirectoryVenueModel;
use App\Services\Search\DiscoveryModelService;
use App\Services\Search\RuleBasedInterpreter;
use App\Services\Search\SearchVocabulary;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Public directory reads: browse/search, profile, reference lists, sitemap.
 * All query composition lives here so a cache/remote-DB layer can wrap it later.
 */
class DirectoryService
{
    public const SA_PROVINCES = [
        'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
        'Mpumalanga', 'Northern Cape', 'North West', 'Western Cape',
    ];

    private DirectoryListingModel $listings;
    private DirectoryCategoryModel $categories;
    private DirectoryCategoryGroupModel $groups;
    private DirectoryPracticeLocationModel $locations;
    private DirectoryTagModel $tags;
    private DirectoryListingPhotoModel $photos;
    private DirectoryListingTeamModel $team;
    private DirectoryVenueModel $venues;

    /**
     * The "published" condition every public read uses, limited to the
     * request's site (co.za: South Africa, .app: everywhere else) once
     * local.webscheduler.app is live. Until then it is exactly the old
     * status = 'published' and co.za keeps showing every country.
     *
     * Raw SQL for where(…, null, false); $prefix is "", "l." or the table
     * name, always written here, never taken from a request.
     */
    private function published(string $prefix = ''): string
    {
        $scope = service('site')->scopeSql($prefix . 'country_code');

        return $prefix . "status = 'published'" . ($scope !== '' ? ' AND ' . $scope : '');
    }

    public function __construct()
    {
        helper('directory_hours');
        $this->listings    = new DirectoryListingModel();
        $this->categories = new DirectoryCategoryModel();
        $this->groups     = new DirectoryCategoryGroupModel();
        $this->locations   = new DirectoryPracticeLocationModel();
        $this->tags        = new DirectoryTagModel();
        $this->photos       = new DirectoryListingPhotoModel();
        $this->team         = new DirectoryListingTeamModel();
        $this->venues       = new DirectoryVenueModel();
    }

    /** Radius options offered on the search page, in kilometres. */
    public const RADIUS_OPTIONS = [1, 5, 10, 25, 50];

    /**
     * A mobile business that hides its address (see listing_address_public()).
     * Such a listing takes no part in any query that is answered from its
     * coordinates or its building — the map, near me, a map viewport, a venue
     * — because a radius or a box asked often enough is the address again.
     * It is still found by name, category, city, province and its service areas.
     */
    private const HIDDEN_ADDRESS_SQL = "(xs_directory_listings.customer_location = 'travel' AND xs_directory_listings.show_address = 0)";

    /**
     * Paginated browse/search over published listings.
     *
     * @param array{q?:string,group?:string,category?:string,province?:string,city?:string,venue?:mixed,lat?:mixed,lng?:mixed,radius?:mixed,bounds?:string,facets?:array,sort?:string} $filters
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int,near:?array}
     */
    public function browse(array $filters, int $page = 1, int $perPage = 12): array
    {
        $page    = max(1, $page);
        $perPage = min(48, max(1, $perPage));

        $near = $this->nearPoint($filters);

        // Resolved BEFORE the builder is created, and that ordering is not
        // cosmetic. sanitiseFacets() runs a query (it resolves the category),
        // and CodeIgniter keeps its table-alias registry on the *connection*,
        // not on the builder: any query that completes mid-build calls
        // setAliasedTables([]) and forgets that `p` and `v` are aliases. The
        // next countAllResults() then re-protects the identifiers and asks for
        // `xs_p`.`slug`, which is a 500 on any search that carries a facet and
        // a category — that is, every faceted search. Nothing about the failure
        // points at facets; the column it names is the category filter.
        $facets = ! empty($filters['facets']) && ! empty($filters['category'])
            ? $this->sanitiseFacets($filters['facets'], (string) $filters['category'])
            : [];
        // Also a query, so also here. Null for no group or an unknown slug, and
        // an unknown slug is ignored rather than returning nothing.
        $groupName = $this->groupNameFor($filters);

        // The venue join is two columns and a LEFT JOIN: the result card renders
        // its chip from them, and a listing without a venue is untouched.
        // mapPoints() deliberately does not join it — the map JSON has no chip.
        $select = 'xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug, p.group_name AS category_group'
            . ', v.name AS venue_name, v.slug AS venue_slug';
        if ($near !== null) {
            // Metres from the visitor. Bound as a value rather than interpolated
            // — this comes off a query string.
            $select .= ', ' . $this->distanceSelect($near) . ' AS distance_m';
        }

        $builder = $this->listings
            ->select($select, false)
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->join('xs_directory_venues v', 'v.id = xs_directory_listings.venue_id', 'left')
            ->where($this->published('xs_directory_listings.'), null, false);

        $q = trim($filters['q'] ?? '');
        // Ranked by how well each listing matches only when the visitor asked
        // a question with words in it and no other order: "near me" is ordered
        // by distance and "newest" by date, and both are what was asked for.
        $byRelevance = $q !== '' && $near === null && ($filters['sort'] ?? '') !== 'new';
        // Related words from the discovery model ("chemotherapy" for
        // "cancer"), set by Directory::index(). Letters only, as learned.
        $expand = $q === '' ? [] : $this->searchTerms(implode(' ', array_filter((array) ($filters['expand'] ?? []), 'is_string')));
        if ($byRelevance) {
            $builder->select($this->relevanceSelect($q, $expand) . ' AS relevance', false);
        }
        if ($q !== '') {
            // Everything applySearch adds is one OR group. Any filter appended
            // after it must be a plain where() — an orWhere here would escape
            // that group and quietly widen the search instead of narrowing it.
            $this->applySearch($builder, $q, $expand);
        }
        if (! empty($filters['category'])) {
            $builder->where('p.slug', $filters['category']);
        }
        if ($groupName !== null) {
            $builder->where('p.group_name', $groupName);
        }
        // A business with a branch in the province or town is found there
        // too, and the card says which branch (branch_place).
        if (! empty($filters['province'])) {
            $cond = '= ' . $this->listings->db->escape((string) $filters['province']);
            $builder->where($this->ownOrBranch('province', $cond), null, false);
            $builder->select($this->branchPlace('province', $cond) . ' AS branch_place', false);
        }
        // local.webscheduler.app's country and region pages. Both arrive
        // already checked against Config\Countries (Directory::index(),
        // Directory::country()).
        if (! empty($filters['country'])) {
            $builder->where('xs_directory_listings.country_code', $filters['country']);
        }
        if (! empty($filters['region'])) {
            $builder->where('xs_directory_listings.region', $filters['region']);
        }
        if (! empty($filters['city'])) {
            $cond = 'LIKE ' . $this->likePattern(trim((string) $filters['city'])) . " ESCAPE '!'";
            $builder->where($this->ownOrBranch('city', $cond), null, false);
            if (empty($filters['province'])) {
                $builder->select($this->branchPlace('city', $cond) . ' AS branch_place', false);
            }
        }
        if (! empty($filters['place'])) {
            $this->applyPlace($builder, (string) $filters['place']);
        }
        if (! empty($filters['holidays'])) {
            $this->applyHolidays($builder, $filters['today'] ?? null);
        }
        // where(), never orWhere() — see the comment above applySearch(). The
        // venue page asks for one building, and an orWhere here would hand it
        // every listing in the country instead.
        if (! empty($filters['venue'])) {
            $builder->where('xs_directory_listings.venue_id', (int) $filters['venue']);
        }
        if ($near !== null) {
            $this->applyNear($builder, $near);
        }
        if (! empty($filters['bounds'])) {
            $this->applyBounds($builder, (string) $filters['bounds']);
        }
        if (! empty($filters['venue']) || $near !== null || ! empty($filters['bounds'])) {
            $builder->where('NOT ' . self::HIDDEN_ADDRESS_SQL, null, false);
        }
        // Last, and like the venue filter it is a plain where() — see the note
        // above applySearch(). Facets only mean anything inside a category, so
        // an unscoped /directory ignores them entirely.
        if ($facets !== []) {
            $this->applyFacets($builder, $facets);
        }

        $total = $builder->countAllResults(false);

        // Clamp to the last real page. `page` comes off the query string, and
        // an unclamped one turns into the OFFSET verbatim — ?page=999999999999
        // asks MySQL to walk and discard twelve billion rows before returning
        // nothing. Clamping after the count (rather than guessing a ceiling
        // before it) keeps every legitimate page reachable.
        $totalPages = (int) max(1, ceil($total / $perPage));
        $page       = min($page, $totalPages);

        // Nearest first when the visitor gave us a position — a featured
        // listing 40km away is not a better answer to "near me" than the one
        // down the road. Otherwise the usual editorial order.
        //
        // quality_score is the second key and published_at has dropped to a
        // tiebreaker. That swap is the whole point: recency used to be the only
        // real driver, so an empty listing created yesterday outranked a rich
        // one from last month and nobody had a reason to finish their profile.
        // The score is earned by filling the profile in and is free to every
        // listing — see ListingQualityService, which cannot read anything the
        // paid badge gates, so this does not quietly sell ranking.
        //
        // Ratings (review_count / rating_avg) are deliberately not a key here
        // or anywhere else: a business must not be able to climb the results
        // by collecting stars, any more than by paying for the badge.
        //
        // is_featured stays above it: editorial, admin-only, rare, and NOT for
        // sale. It is now the only thing that outranks a better profile, so if
        // it is ever sold the promise in _plan_cards.php breaks in a way the
        // score cannot repair.
        if ($near !== null) {
            // The last key here is not decoration. A suburb-precision geocode
            // gives every listing in that suburb the identical centroid, so
            // distance_m ties exactly and the order used to be arbitrary.
            //
            // The first key keeps listings with no pin below every listing
            // that has one. With no radius set nothing filters them out, and
            // MySQL sorts a NULL distance first, which put the unmapped ones
            // at the top of a "near me" list looking like the closest.
            $builder->orderBy('distance_m IS NULL', 'ASC', false)
                ->orderBy('distance_m', 'ASC', false)
                ->orderBy('xs_directory_listings.quality_score', 'DESC');
        } elseif (($filters['sort'] ?? '') === 'new') {
            // "What opened lately". Distance still wins above: a visitor who
            // asked for near me asked a different question. is_featured is not
            // a key here — pinning an old listing above the new ones would make
            // the sort lie about what it is sorting by.
            $builder->orderBy('xs_directory_listings.published_at', 'DESC')
                ->orderBy('xs_directory_listings.quality_score', 'DESC');
        } else {
            // A search is ordered by how well each listing answers it first,
            // and only then by the editorial order below. Before this, the
            // match score was computed and thrown away: a business named
            // exactly what was typed could sit on page three behind fuller
            // profiles that mentioned the word once. relevanceSelect() is built
            // from what a listing says about itself, never from the badge or
            // its reviews — see the note there.
            if ($byRelevance) {
                $builder->orderBy('relevance', 'DESC', false);
            }
            $builder->orderBy('xs_directory_listings.is_featured', 'DESC')
                ->orderBy('xs_directory_listings.quality_score', 'DESC')
                ->orderBy('xs_directory_listings.published_at', 'DESC');
        }

        $items = $builder->limit($perPage, ($page - 1) * $perPage)->findAll();

        // The card's facet line — "Ages 18 months – 6 years · Montessori" — in
        // one indexed query for the whole page. Asking per card would be twelve
        // to forty-eight round trips for data that is a single IN away, which
        // is the same reason the venue chip is a join rather than a lookup.
        if ($items !== []) {
            $facetRows = (new DirectoryListingFacetModel())->forListings(array_column($items, 'id'));
            foreach ($items as $i => $item) {
                $items[$i]['facets'] = $facetRows[(int) $item['id']] ?? [];
            }
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
            'near'       => $near,
        ];
    }

    /**
     * Typeahead suggestions for the search boxes: businesses, the people in
     * Verified businesses, categories, venues and places matching the first
     * few characters someone has typed.
     *
     * Deliberately NOT browse(). That method answers "what matches this
     * search" — FULLTEXT in boolean mode, four unanchored LIKEs and a
     * correlated EXISTS over two tables — and running it on every keystroke is
     * the one thing a suggestion list must never do. These are small lookups
     * with their own small limits. The one FULLTEXT among them (descriptions,
     * see descriptionSuggestions()) is the indexed part of browse(), not the
     * LIKEs and EXISTS that make it heavy.
     *
     * Ordering puts a prefix match above a mid-word one, so typing "plu" offers
     * "Plumbers" before "Superb Plumbing". LOCATE() rather than a second query:
     * one pass over the same rows, and it costs nothing at these limits.
     *
     * Names come back raw. Everything here is owner-supplied text that is
     * already public on the profile, and the caller is JSON — so it is encoded
     * once, by setJSON(), and written into the page with textContent. Escaping
     * here as well would show a business called "Mom & Pop" as "Mom &amp; Pop".
     *
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    public function suggest(string $q, int $limit = 8): array
    {
        $q = trim($q);
        // The same floor the endpoint and the browser enforce. Three is also
        // MySQL's default innodb_ft_min_token_size, so shorter terms are not
        // usefully searchable anyway.
        if (mb_strlen($q) < 3) {
            return [];
        }

        // "dentist in sa" is a search being typed, not a business name, and a
        // LIKE on the whole of it finds nothing. Read it first: offer the
        // searches it is heading towards, then look up only the words that
        // were not understood ("sa" here, too short to bother with). When
        // every word was understood, fall back to the whole string.
        $typed  = $q;
        $intent = \Config\Services::queryInterpreter()->interpret($q);
        $out    = $this->intentSuggestions($intent);
        if ($intent->hasFilters() && $intent->keywords() !== '') {
            $q = $intent->keywords();
        }
        if (mb_strlen($q) < 3) {
            return array_slice($out, 0, max(1, $limit));
        }

        $db      = db_connect();
        $escaped = $db->escape($q);

        $listings = $this->listings
            ->select('display_name, slug, city, province, quality_score', false)
            ->where($this->published(), null, false)
            ->like('display_name', $q)
            ->orderBy('LOCATE(' . $escaped . ', display_name) = 1', 'DESC', false)
            ->orderBy('is_featured', 'DESC')
            // Five slots out of an unbounded LIKE '%q%' set, and before this
            // the leftovers were handed out alphabetically — so an empty "AAA
            // Plumbing" took a slot from a complete listing on every query.
            ->orderBy('quality_score', 'DESC')
            ->orderBy('display_name', 'ASC')
            ->limit(5)
            ->findAll();

        foreach ($listings as $row) {
            $out[] = [
                'type'  => 'listing',
                'label' => (string) $row['display_name'],
                'sub'   => trim(implode(', ', array_filter([(string) ($row['city'] ?? ''), (string) ($row['province'] ?? '')]))),
                'url'   => base_url('directory/' . $row['slug']),
            ];
        }

        // Then what a business does: "cervical" offers PinkDrive, whose
        // name never says it, from its programmes, services or tags. Same
        // tables applySearch() reads, so the row leads to a profile the
        // results page also finds. Word starts only ("cerv", not "rvic").
        $left = 5 - count($listings);
        if ($left > 0) {
            $out = array_merge($out, $this->topicSuggestions($q, array_column($listings, 'slug'), $left));
        }

        // Then what a business says about itself: "cancer" offers the
        // oncologists whose descriptions say it, not just PinkDrive's tag.
        // The same FULLTEXT the results page runs, so every row here is a
        // profile pressing Enter also finds. Four letters, not three: "plu*"
        // in a description is every "plus", where in a name it is plumbers.
        $terms   = mb_strlen($q) >= 4 ? $this->searchTerms($q) : [];
        $matched = $terms === [] ? [] : $this->descriptionSuggestions($terms, $out, 5 - count(array_filter($out, static fn ($r) => $r['type'] === 'listing')));
        $out     = array_merge($out, $matched);

        // The people inside a business, so typing the name of the dentist
        // you were referred to offers the practice. Same gate as the team
        // EXISTS in applySearch(): a member is only offered while the badge
        // that publishes the team panel is live, or the row would land on a
        // profile that does not show them. The link goes to their card.
        $staff = $db->table('xs_directory_listing_team tm')
            ->select('tm.name, tm.slug, tm.role, l.display_name, l.slug AS listing_slug')
            ->join('xs_directory_listings l', 'l.id = tm.listing_id')
            ->where($this->published('l.'), null, false)
            ->where('l.deleted_at', null)
            ->where('l.verified_until >=', date('Y-m-d'))
            ->like('tm.name', $q)
            ->orderBy('LOCATE(' . $escaped . ', tm.name) = 1', 'DESC', false)
            ->orderBy('tm.name', 'ASC')
            ->limit(2)
            ->get()
            ->getResultArray();

        foreach ($staff as $row) {
            $anchor = team_member_anchor($row);
            $out[]  = [
                'type'  => 'person',
                'label' => (string) $row['name'],
                'sub'   => trim(implode(' · ', array_filter([(string) ($row['role'] ?? ''), (string) $row['display_name']]))),
                'url'   => base_url('directory/' . $row['listing_slug']) . ($anchor !== '' ? '#' . $anchor : ''),
            ];
        }

        $categories = $this->categories
            ->where('is_active', 1)
            ->like('name', $q)
            ->orderBy('LOCATE(' . $escaped . ', name) = 1', 'DESC', false)
            ->orderBy('name', 'ASC')
            ->limit(3)
            ->findAll();

        foreach ($categories as $row) {
            $out[] = [
                'type'  => 'category',
                'label' => (string) $row['name'],
                'sub'   => (string) ($row['group_name'] ?? ''),
                'url'   => base_url('directory/' . $row['slug']),
            ];
        }

        // No category is called "cancer", but Oncologist is where most of the
        // profiles that say it sit. Learned from the profiles, not a synonym:
        // a synonym would filter the results page to one category and hide
        // the radiation oncologists and PinkDrive.
        if ($terms !== [] && count($categories) < 3) {
            $out = array_merge($out, $this->relatedCategorySuggestions($terms, $q, array_column($categories, 'id'), 3 - count($categories)));
        }

        // Cities of published listings, not a place table — the only places
        // worth offering are ones that will actually return results.
        $venues = $this->venues
            ->where('is_active', 1)
            ->like('name', $q)
            ->orderBy('LOCATE(' . $escaped . ', name) = 1', 'DESC', false)
            ->orderBy('name', 'ASC')
            ->limit(2)
            ->findAll();

        foreach ($venues as $row) {
            $out[] = [
                'type'  => 'venue',
                'label' => (string) $row['name'],
                'sub'   => trim(implode(', ', array_filter([(string) ($row['suburb'] ?? ''), (string) ($row['city'] ?? '')]))),
                'url'   => base_url('directory/at/' . $row['slug']),
            ];
        }

        $places = $this->listings
            ->select('city, province, COUNT(*) AS c', false)
            ->where($this->published(), null, false)
            ->where('city !=', '')
            ->like('city', $q)
            ->groupBy('city, province')
            ->orderBy('c', 'DESC')
            ->limit(3)
            ->findAll();

        foreach ($places as $row) {
            $out[] = [
                'type'  => 'place',
                'label' => (string) $row['city'],
                'sub'   => (string) ($row['province'] ?? ''),
                'url'   => base_url('directory') . '?city=' . rawurlencode((string) $row['city']),
            ];
        }

        // Nothing at all for "oncoligist": offer the search spelt the way
        // the platform's own profiles spell it, first.
        if ($out === [] && config('Search')->discoverySpelling && mb_strlen($typed) >= 5) {
            $fixed = $this->correctSpelling($typed);
            if ($fixed !== null) {
                return [[
                    'type'  => 'search',
                    'label' => 'Did you mean "' . $fixed . '"?',
                    'sub'   => '',
                    'url'   => base_url('directory') . '?q=' . rawurlencode($fixed),
                ]];
            }
        }

        // Rows found in descriptions are a sample of a longer list, so end
        // with the way to all of it. It keeps its place inside the limit.
        if ($matched !== []) {
            $out   = array_slice($out, 0, max(1, $limit - 1));
            $out[] = [
                'type'  => 'search',
                'label' => 'Discover every match for "' . $q . '"',
                'sub'   => '',
                'url'   => base_url('directory') . '?q=' . rawurlencode($q),
            ];
        }

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Business rows for suggest() matched in the description, after the
     * name and topic rows. Ordered by how well they match, then the quality
     * score; never is_featured, so nothing paid moves a profile up. The sub
     * line names the category, which is why a doctor turned up for "cancer".
     *
     * @param list<string> $terms from searchTerms()
     * @param list<array{type:string,label:string,sub:string,url:string}> $offered rows already in the list
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function descriptionSuggestions(array $terms, array $offered, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $match = $this->fulltextMatch($terms);
        $taken = array_flip(array_column($offered, 'url'));

        // A few spare rows, because the ones already offered by name or
        // topic are skipped here rather than in SQL.
        $rows = $this->listings
            ->select('xs_directory_listings.display_name, xs_directory_listings.slug, xs_directory_listings.city, xs_directory_listings.province, c.name AS category', false)
            ->join('xs_directory_categories c', 'c.id = xs_directory_listings.category_id', 'left')
            ->where($this->published('xs_directory_listings.'), null, false)
            ->where($match, null, false)
            ->orderBy($match, 'DESC', false)
            ->orderBy('xs_directory_listings.quality_score', 'DESC')
            ->orderBy('xs_directory_listings.display_name', 'ASC')
            ->limit($limit + count($taken))
            ->findAll();

        $out = [];
        foreach ($rows as $row) {
            $url = base_url('directory/' . $row['slug']);
            if (isset($taken[$url])) {
                continue;
            }
            $where = trim(implode(', ', array_filter([(string) ($row['city'] ?? ''), (string) ($row['province'] ?? '')])));
            $out[] = [
                'type'  => 'listing',
                'label' => (string) $row['display_name'],
                'sub'   => trim(implode(' · ', array_filter([(string) ($row['category'] ?? ''), $where]))),
                'url'   => $url,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Categories the words point at, learned from the profiles by the
     * discovery model: the dropdown's Category rows and the results page's
     * "Related categories". Empty while the model is switched off.
     *
     * @param list<int|string> $skipIds
     * @return list<array{id:int,name:string,slug:string,profiles:int}>
     */
    public function relatedCategories(string $q, int $limit = 3, array $skipIds = []): array
    {
        if (! config('Search')->discoveryModel) {
            return [];
        }

        try {
            return (new DiscoveryModelService())->related($q, $limit, $skipIds);
        } catch (\Throwable $e) {
            // Before the migration has run, or a broken table: suggestions
            // carry on without it.
            log_message('warning', 'Discovery model unavailable: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Related words to widen a search with, from the discovery model. Empty
     * while switched off or before the model has been built.
     *
     * @return list<string>
     */
    public function relatedWords(string $q): array
    {
        if (! config('Search')->discoveryExpand) {
            return [];
        }
        try {
            return (new DiscoveryModelService())->expansions($q);
        } catch (\Throwable $e) {
            log_message('warning', 'Discovery model unavailable: ' . $e->getMessage());

            return [];
        }
    }

    /** The search spelt with the platform's own words, or null. See DiscoveryModelService::correct(). */
    public function correctSpelling(string $q): ?string
    {
        if (! config('Search')->discoverySpelling) {
            return null;
        }
        try {
            return (new DiscoveryModelService())->correct($q);
        } catch (\Throwable $e) {
            log_message('warning', 'Discovery model unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Categories for suggest() that most of the profiles mentioning the words
     * belong to, at least two of them, so one stray profile cannot make a link.
     *
     * @param list<string> $terms from searchTerms()
     * @param list<int|string> $skipIds categories already offered by name
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function relatedCategorySuggestions(array $terms, string $q, array $skipIds, int $limit): array
    {
        $out = [];
        foreach ($this->relatedCategories($q, $limit, $skipIds) as $row) {
            $out[] = [
                'type'  => 'category',
                'label' => $row['name'],
                'sub'   => $row['profiles'] . ' related profiles',
                'url'   => base_url('directory/' . $row['slug']),
            ];
        }
        if ($out !== []) {
            return $out;
        }

        // The model knows nothing about these words yet (a new word, or no
        // nightly run so far): group today's FULLTEXT matches instead.
        $builder = $this->listings->builder()
            ->select('c.name, c.slug, COUNT(*) AS matches', false)
            ->join('xs_directory_categories c', 'c.id = xs_directory_listings.category_id')
            ->where($this->published('xs_directory_listings.'), null, false)
            ->where('xs_directory_listings.deleted_at', null)
            ->where('c.is_active', 1)
            ->where($this->fulltextMatch($terms), null, false);
        if ($skipIds !== []) {
            $builder->whereNotIn('c.id', $skipIds);
        }
        $rows = $builder
            ->groupBy('c.id, c.name, c.slug')
            ->having('matches >=', 2)
            ->orderBy('matches', 'DESC')
            ->orderBy('c.name', 'ASC')
            ->limit(max(1, $limit))
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'type'  => 'category',
                'label' => (string) $row['name'],
                'sub'   => (int) $row['matches'] . ' related profiles',
                'url'   => base_url('directory/' . $row['slug']),
            ];
        }

        return $out;
    }

    /**
     * Business rows for suggest() matched on a service, programme or tag
     * rather than the name. The sub line names what matched.
     *
     * @param list<string> $skipSlugs already offered by name
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function topicSuggestions(string $q, array $skipSlugs, int $limit): array
    {
        $db    = db_connect();
        $start = $db->escape($db->escapeLikeString($q) . '%');
        $word  = $db->escape('% ' . $db->escapeLikeString($q) . '%');
        $match = static fn (string $col): string => "({$col} LIKE {$start} ESCAPE '!' OR {$col} LIKE {$word} ESCAPE '!')";

        $skip = $skipSlugs === [] ? ''
            : ' AND l.slug NOT IN (' . implode(', ', array_map([$db, 'escape'], $skipSlugs)) . ')';

        $rows = $db->query(
            'SELECT l.display_name, l.slug, l.city, l.province, MIN(m.name) AS topic'
            . ' FROM xs_directory_listings l JOIN ('
            . ' SELECT sv.listing_id, sv.name FROM xs_directory_listing_services sv WHERE ' . $match('sv.name')
            . ' UNION SELECT lt.listing_id, t.name FROM xs_directory_listing_tags lt'
            . ' JOIN xs_directory_tags t ON t.id = lt.tag_id WHERE ' . $match('t.name')
            . ') m ON m.listing_id = l.id'
            . ' WHERE ' . $this->published('l.') . ' AND l.deleted_at IS NULL' . $skip
            . ' GROUP BY l.id ORDER BY l.is_featured DESC, l.quality_score DESC, l.display_name ASC'
            . ' LIMIT ' . max(1, $limit)
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $where = trim(implode(', ', array_filter([(string) ($row['city'] ?? ''), (string) ($row['province'] ?? '')])));
            $out[] = [
                'type'  => 'listing',
                'label' => (string) $row['display_name'],
                'sub'   => trim(implode(' · ', array_filter([(string) $row['topic'], $where]))),
                'url'   => base_url('directory/' . $row['slug']),
            ];
        }

        return $out;
    }

    /**
     * Whole searches to offer while one is being typed: "Dentist in Sandton"
     * once the category is understood and the last word starts a place we
     * have listings in, or the understood search itself once it names both.
     *
     * Only places from searchVocabulary(), so every row leads somewhere with
     * results — the same reason the city rows below come from listings.
     *
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function intentSuggestions(\App\Services\Search\SearchIntent $intent): array
    {
        $subject = $intent->categoryName ?? $intent->groupName;
        if ($subject === null) {
            return [];
        }

        $labels = [];
        if ($intent->place !== null) {
            $labels[] = $subject . ' in ' . $intent->place;
        } else {
            $words    = explode(' ', RuleBasedInterpreter::normalise($intent->raw));
            $fragment = (string) end($words);
            $leftover = array_merge($intent->terms, $intent->serviceTerms);
            if (mb_strlen($fragment) >= 2 && in_array($fragment, $leftover, true)) {
                // Towns before suburbs: "sa" should offer Sandton the town
                // before every suburb called Sa-something.
                $places = $this->searchVocabulary()->places;
                uasort($places, static fn ($a, $b) => [$a['kind'] !== 'city', $a['name']] <=> [$b['kind'] !== 'city', $b['name']]);
                foreach ($places as $key => $place) {
                    if (str_starts_with($key, $fragment)) {
                        $labels[] = $subject . ' in ' . $place['name'];
                    }
                    if (count($labels) >= 3) {
                        break;
                    }
                }
            }
        }

        $out = [];
        foreach (array_unique($labels) as $label) {
            $out[] = [
                'type'  => 'search',
                'label' => $label,
                'sub'   => '',
                'url'   => base_url('directory') . '?q=' . rawurlencode($label),
            ];
        }

        return $out;
    }

    /**
     * Everything the search interpreter can recognise, from our own data: the
     * active categories and main categories, the towns and visible suburbs
     * that published listings are in, and the words their service menus and
     * tags use.
     *
     * Cached for ten minutes. A new town appearing in search a few minutes
     * after its first listing is published costs nothing; reading four tables
     * on every search would. A cache failure reads through.
     */
    public function searchVocabulary(): SearchVocabulary
    {
        $key = site_cache_key('search_vocabulary_v1');
        try {
            $cached = cache()->get($key);
            if ($cached instanceof SearchVocabulary) {
                return $cached;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Search vocabulary cache unavailable, reading through: ' . $e->getMessage());
        }

        $vocabulary = $this->buildSearchVocabulary();

        try {
            cache()->save($key, $vocabulary, 600);
        } catch (\Throwable $e) {
            log_message('warning', 'Could not cache the search vocabulary: ' . $e->getMessage());
        }

        return $vocabulary;
    }

    private function buildSearchVocabulary(): SearchVocabulary
    {
        $norm = static fn (string $text): string => RuleBasedInterpreter::normalise($text);

        $categories = [];
        foreach ($this->categories() as $c) {
            $entry = ['slug' => (string) $c['slug'], 'name' => (string) $c['name']];
            $categories[$norm((string) $c['name'])] ??= $entry;
            $categories[$norm(str_replace('-', ' ', (string) $c['slug']))] ??= $entry;
        }

        $groups = [];
        foreach ($this->groups->ordered() as $g) {
            $groups[$norm((string) $g['name'])] ??= ['slug' => (string) $g['slug'], 'name' => (string) $g['name']];
        }

        // Towns first, so a suburb that shares a town's name stays a town.
        $places = [];
        $towns  = $this->listings->select('DISTINCT city', false)
            ->where($this->published(), null, false)->where('city !=', '')
            ->findAll();
        foreach ($towns as $row) {
            $k = $norm((string) $row['city']);
            if (mb_strlen($k) >= 3) {
                $places[$k] ??= ['name' => trim((string) $row['city']), 'kind' => 'city'];
            }
        }
        // Only suburbs the public can see — see HIDDEN_ADDRESS_SQL. Otherwise
        // a hidden owner's suburb would become a word the search recognises.
        $suburbs = $this->listings->select('DISTINCT suburb', false)
            ->where($this->published(), null, false)->where('suburb !=', '')
            ->where('NOT ' . self::HIDDEN_ADDRESS_SQL, null, false)
            ->findAll();
        foreach ($suburbs as $row) {
            $k = $norm((string) $row['suburb']);
            if (mb_strlen($k) >= 3) {
                $places[$k] ??= ['name' => trim((string) $row['suburb']), 'kind' => 'suburb'];
            }
        }

        $db    = $this->listings->db;
        $names = array_merge(
            array_column($db->table('xs_directory_listing_services sv')
                ->select('DISTINCT sv.name', false)
                ->join('xs_directory_listings l', 'l.id = sv.listing_id')
                ->where($this->published('l.'), null, false)->where('l.deleted_at', null)
                ->limit(5000)->get()->getResultArray(), 'name'),
            array_column($db->table('xs_directory_tags t')
                ->select('DISTINCT t.name', false)
                ->join('xs_directory_listing_tags lt', 'lt.tag_id = t.id')
                ->join('xs_directory_listings l', 'l.id = lt.listing_id')
                ->where($this->published('l.'), null, false)->where('l.deleted_at', null)
                ->limit(5000)->get()->getResultArray(), 'name'),
        );
        $filler = array_flip(config('Search')->fillerWords);
        $words  = [];
        foreach ($names as $name) {
            foreach (explode(' ', $norm((string) $name)) as $word) {
                if (mb_strlen($word) >= 4 && ! isset($filler[$word])) {
                    $words[$word] = true;
                }
            }
        }

        return new SearchVocabulary($categories, $groups, $places, $words);
    }

    /**
     * Example searches for the home page, built from what is actually here:
     * the busiest category-and-town pairs, one per category. An example must
     * never land on an empty page, so nothing is invented — with too few real
     * pairs, the config's fallbacks are used instead.
     *
     * @return list<string> e.g. "Dentist in Sandton"
     */
    public function searchExamples(int $limit = 6): array
    {
        // On local.webscheduler.app a profile with no town still makes an
        // example from its country ("Attorney in Portugal"): discovery reads
        // a country there, and the site is young enough that every one counts.
        $isApp = service('site')->isApp();
        $place = $isApp
            ? "COALESCE(NULLIF(TRIM(xs_directory_listings.city), ''), xs_directory_listings.country)"
            : 'xs_directory_listings.city';

        $builder = $this->listings
            ->select('p.name AS category, ' . $place . ' AS city, COUNT(*) AS c', false)
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id')
            ->where($this->published('xs_directory_listings.'), null, false)
            ->where('p.is_active', 1);
        if (! $isApp) {
            $builder->where('xs_directory_listings.city !=', '');
        }
        $rows = $builder
            ->groupBy('p.id, p.name, ' . $place, false)
            ->orderBy('c', 'DESC')
            ->orderBy('p.name', 'ASC')
            ->limit(40)
            ->findAll();

        $out = $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['category']]) || trim((string) $row['city']) === '') {
                continue;
            }
            $seen[$row['category']] = true;
            $out[] = $row['category'] . ' in ' . trim((string) $row['city']);
            if (count($out) >= $limit) {
                break;
            }
        }

        return count($out) >= 3
            ? $out
            : array_slice(config('Search')->forSite(service('site')->key())->fallbackExamples, 0, $limit);
    }

    /**
     * Whether a published business is called something containing $q.
     *
     * The guard against over-reading a name: "Bob the Builder Plumbing" holds a
     * category word, and filtering it to Builders would hide the one business
     * the visitor typed the name of. When a name matches, the search runs on
     * the words as typed instead.
     */
    public function hasListingNamed(string $q): bool
    {
        $q = trim($q);
        if (mb_strlen($q) < 4) {
            return false;
        }

        return $this->listings
            ->where($this->published(), null, false)
            ->like('display_name', $q)
            ->countAllResults() > 0;
    }

    /**
     * Every published listing with a position inside the given viewport, for
     * the search map.
     *
     * Separate from browse() because the map and the list want different
     * things: the list is paginated twelve at a time, the map wants every pin
     * in view at once. Capped rather than paginated — beyond a few hundred pins
     * clustering is doing all the work anyway, and an uncapped query over a
     * whole-country viewport is a denial-of-service waiting to happen.
     *
     * @param array<string,mixed> $filters same shape browse() takes
     * @return list<array<string,mixed>>
     */
    public function mapPoints(array $filters, int $limit = 500): array
    {
        $near = $this->nearPoint($filters);

        // Before the builder, for the alias-registry reason browse() explains.
        $facets = ! empty($filters['facets']) && ! empty($filters['category'])
            ? $this->sanitiseFacets($filters['facets'], (string) $filters['category'])
            : [];
        $groupName = $this->groupNameFor($filters);

        $select = 'xs_directory_listings.id, xs_directory_listings.slug, xs_directory_listings.display_name,'
            . ' xs_directory_listings.latitude, xs_directory_listings.longitude,'
            . ' xs_directory_listings.geocode_precision, xs_directory_listings.logo_path,'
            . ' xs_directory_listings.trading_hours,'
            . ' xs_directory_listings.offers_online_booking, xs_directory_listings.booking_url,'
            . ' xs_directory_listings.address_line, xs_directory_listings.address_line_2,'
            . ' xs_directory_listings.suburb, xs_directory_listings.city,'
            . ' xs_directory_listings.province, xs_directory_listings.postal_code,'
            // Whose clock "Open now" reads: see hours_context().
            . ' xs_directory_listings.region, xs_directory_listings.country_code, xs_directory_listings.timezone,'
            . ' p.name AS category_name';

        if ($near !== null) {
            $select .= ', ' . $this->distanceSelect($near) . ' AS distance_m';
        }

        $builder = $this->listings
            ->select($select, false)
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->where($this->published('xs_directory_listings.'), null, false);

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $this->applySearch($builder, $q);
        }
        if (! empty($filters['category'])) {
            $builder->where('p.slug', $filters['category']);
        }
        if ($groupName !== null) {
            $builder->where('p.group_name', $groupName);
        }
        // Own address only, unlike browse(): a pin is the profile's own point,
        // so a branch match would drop a Johannesburg pin on a Cape Town map.
        if (! empty($filters['province'])) {
            $builder->where('xs_directory_listings.province', $filters['province']);
        }
        if (! empty($filters['city'])) {
            $builder->like('xs_directory_listings.city', trim($filters['city']));
        }
        if (! empty($filters['place'])) {
            $this->applyPlace($builder, (string) $filters['place'], false);
        }
        if (! empty($filters['holidays'])) {
            $this->applyHolidays($builder, $filters['today'] ?? null);
        }
        if ($near !== null) {
            $this->applyNear($builder, $near);
        }
        if (! empty($filters['bounds'])) {
            $this->applyBounds($builder, (string) $filters['bounds']);
        }
        // The list and the map are read from one filter array precisely so they
        // cannot disagree; leaving facets out here would put pins on the map for
        // schools the list beside it has just filtered away.
        if ($facets !== []) {
            $this->applyFacets($builder, $facets);
        }

        // No pin for an address the owner hid — see HIDDEN_ADDRESS_SQL.
        $builder->where('NOT ' . self::HIDDEN_ADDRESS_SQL, null, false);

        // A listing with no coordinates cannot be a pin. The join to the
        // spatial table is what enforces it, and it is also what makes the
        // bounding-box filter above use the spatial index.
        $builder->join(
            'xs_directory_listing_points pt',
            'pt.listing_id = xs_directory_listings.id',
            'inner'
        );

        if ($near !== null) {
            $builder->orderBy('distance_m', 'ASC', false)
                ->orderBy('xs_directory_listings.quality_score', 'DESC');
        } else {
            // There used to be no ORDER BY here at all, which sounds harmless
            // next to a limit of 1000 and is not: the caller caps at 200, so a
            // country-wide viewport kept whichever 200 rows InnoDB reached
            // first — in practice the 200 oldest listings. If the cap has to
            // throw pins away, throw away the emptiest profiles instead.
            //
            // Caveat worth knowing: this makes the surviving set geographically
            // non-uniform at low zoom, since a city with richer profiles keeps
            // more pins than a rural one. The honest fix is server-side
            // clustering rather than a bigger cap.
            $builder->orderBy('xs_directory_listings.quality_score', 'DESC');
        }

        return $builder->limit(max(1, min($limit, 1000)))->findAll();
    }

    /**
     * The visitor's position and search radius, or null when they gave none.
     *
     * @param array<string,mixed> $filters
     * @return array{lat:float,lng:float,radius:int}|null
     */
    private function nearPoint(array $filters): ?array
    {
        $lat = $filters['lat'] ?? null;
        $lng = $filters['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        // On co.za, the same bounding box that vets everything else entering
        // this app: a position outside South Africa cannot produce a useful
        // result there, and silently searching from it would return the whole
        // directory sorted by distance to another continent. local.webscheduler.app
        // covers every other country, so there any real position on the planet
        // is a fair starting point.
        $plausible = service('site')->isApp()
            ? abs((float) $lat) <= 90.0 && abs((float) $lng) <= 180.0
            : NominatimGeocoder::isPlausible((float) $lat, (float) $lng);
        if (! $plausible) {
            return null;
        }

        $radius = (int) ($filters['radius'] ?? 0);
        if (! in_array($radius, self::RADIUS_OPTIONS, true)) {
            $radius = 0; // no radius limit; still sorts by distance
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng, 'radius' => $radius];
    }

    /**
     * Distance in metres from the visitor to each listing.
     *
     * Reads latitude/longitude off the listing rather than the spatial table so
     * it works whether or not the points table has been joined — browse() shows
     * a distance without needing the join, mapPoints() needs the join anyway.
     *
     * @param array{lat:float,lng:float,radius:int} $near
     */
    private function distanceSelect(array $near): string
    {
        // Interpolated, not bound: this lands in a SELECT expression that also
        // has to be usable in ORDER BY, and CI4 only binds against WHERE. Safe
        // because nearPoint() has already forced both values through a numeric
        // check and a South African bounding box — they are floats by here.
        return sprintf(
            'ST_Distance_Sphere(POINT(xs_directory_listings.longitude, xs_directory_listings.latitude),'
            . ' POINT(%.7F, %.7F))',
            $near['lng'],
            $near['lat']
        );
    }

    /**
     * Restrict to listings within the radius, using the spatial index.
     *
     * The bounding box is the part the index can answer; ST_Distance_Sphere
     * then trims the corners of that box down to a true circle. Doing only the
     * distance test would work but would read every row.
     *
     * @param array{lat:float,lng:float,radius:int} $near
     */
    private function applyNear(BaseBuilder|Model $builder, array $near): void
    {
        if ($near['radius'] <= 0) {
            return;
        }

        $metres = $near['radius'] * 1000;

        // Degrees of latitude are ~111km everywhere; degrees of longitude
        // shrink towards the poles, hence the cosine. Generous by 1% so the box
        // can never clip a listing the distance test would have kept.
        $latSpan = ($metres / 111_320) * 1.01;
        $lngSpan = ($metres / (111_320 * max(0.01, cos(deg2rad($near['lat']))))) * 1.01;

        $builder
            ->where('xs_directory_listings.latitude >=', $near['lat'] - $latSpan)
            ->where('xs_directory_listings.latitude <=', $near['lat'] + $latSpan)
            ->where('xs_directory_listings.longitude >=', $near['lng'] - $lngSpan)
            ->where('xs_directory_listings.longitude <=', $near['lng'] + $lngSpan)
            ->where($this->distanceSelect($near) . ' <= ' . $metres, null, false);
    }

    /**
     * Restrict to listings inside a map viewport.
     *
     * @param string $bounds "south,west,north,east" as Leaflet reports it
     */
    private function applyBounds(BaseBuilder|Model $builder, string $bounds): void
    {
        $parts = array_map('trim', explode(',', $bounds));
        if (count($parts) !== 4) {
            return;
        }
        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return;
            }
        }

        [$south, $west, $north, $east] = array_map('floatval', $parts);

        // A viewport dragged across the antimeridian would arrive inverted.
        // Nothing in a South African directory should ever do that, so treat it
        // as junk rather than trying to split the box.
        if ($south > $north || $west > $east) {
            return;
        }

        $builder
            ->where('xs_directory_listings.latitude >=', $south)
            ->where('xs_directory_listings.latitude <=', $north)
            ->where('xs_directory_listings.longitude >=', $west)
            ->where('xs_directory_listings.longitude <=', $east);
    }

    /**
     * Narrow a browse to listings that state particular facet values.
     *
     * One EXISTS per facet, ANDed: choosing IEB *and* boarding means both, the
     * way every faceted search behaves. Within one facet the values are ORed
     * through an IN, because ticking two curricula asks for either.
     *
     * where(), never orWhere() — the same trap the venue filter documents.
     * Everything applySearch() adds is one OR group, and an orWhere here
     * escapes it, turning "IEB schools matching 'montessori'" into every
     * listing in the country. VerticalFacetTest pins exactly that.
     *
     * Every key and value must already have been through sanitiseFacets(),
     * which keeps only keys the category offers, values from that facet's own
     * option list and integers clamped to its bounds — so what reaches escape()
     * here cannot be attacker-shaped. The escaping is belt and braces, the way
     * distanceSelect() vets its coordinates before interpolating them.
     *
     * Deliberately runs no queries of its own, and must not be made to: the
     * caller is half-way through building a query, and a completed query here
     * would clear the connection's table-alias registry. See the note in
     * browse() where the sanitising happens instead.
     *
     * @param array<string,list<string>|int> $facets
     */
    private function applyFacets(BaseBuilder|Model $builder, array $facets): void
    {
        $db = $this->listings->db;

        foreach ($facets as $key => $chosen) {
            $scope = 'SELECT 1 FROM xs_directory_listing_facets f'
                . ' WHERE f.listing_id = xs_directory_listings.id'
                . ' AND f.facet_key = ' . $db->escape($key);

            if (is_int($chosen)) {
                // "Takes a 3-year-old" and "fees up to R5 000" are the same
                // question of a stored range: does it reach this number. A null
                // high bound is a one-sided facet ('fees from'), not a gap, so
                // it must not fail the test.
                $builder->where(
                    'EXISTS (' . $scope
                    . ' AND f.num_low <= ' . $chosen
                    . ' AND (f.num_high IS NULL OR f.num_high >= ' . $chosen . '))',
                    null,
                    false
                );
                continue;
            }

            $builder->where(
                'EXISTS (' . $scope
                . ' AND f.value IN (' . implode(', ', array_map([$db, 'escape'], $chosen)) . '))',
                null,
                false
            );
        }
    }

    /**
     * Submitted facet filters reduced to what the category actually offers.
     *
     * Public because the results view needs to render the rail showing exactly
     * what was honoured, and the pager needs to carry exactly that forward —
     * a filter silently dropped here but still drawn as ticked is the kind of
     * bug nobody reports, because the page looks like it worked.
     *
     * Returns facet key => list of option keys, or a single int for a range.
     *
     * @return array<string,list<string>|int>
     */
    public function sanitiseFacets(mixed $raw, string $categorySlug): array
    {
        if (! is_array($raw) || $raw === [] || $categorySlug === '') {
            return [];
        }

        $category = $this->findCategoryBySlug($categorySlug);
        if ($category === null) {
            return [];
        }

        $offered = config('ListingFacets')->filterableFor(
            $category['group_name'] ?? null,
            $category['slug'] ?? null
        );

        $out = [];
        foreach ($offered as $key => $facet) {
            if (! isset($raw[$key])) {
                continue;
            }

            if (($facet['type'] ?? '') === 'range') {
                $n = is_array($raw[$key]) ? null : trim((string) $raw[$key]);
                if ($n === null || $n === '' || ! is_numeric($n)) {
                    continue;
                }
                $out[$key] = max((int) $facet['min'], min((int) $facet['max'], (int) $n));
                continue;
            }

            $submitted = is_array($raw[$key]) ? $raw[$key] : [$raw[$key]];
            $kept      = [];
            foreach ($submitted as $value) {
                if (is_string($value) && isset($facet['options'][$value]) && ! in_array($value, $kept, true)) {
                    $kept[] = $value;
                }
            }
            if ($kept !== []) {
                $out[$key] = $kept;
            }
        }

        return $out;
    }

    /**
     * Free-text search across everything a visitor would reasonably type.
     *
     * People search by service ("hair salon") far more than by business name, so
     * matching only the listing's own text misses the most common query there
     * is. This deliberately spans these sources:
     *
     *   - FULLTEXT over display_name / description_text / credentials, in
     *     BOOLEAN mode with a trailing * so "hairdress" reaches "hairdresser".
     *     description_text, not description: the latter holds the rich-text
     *     HTML, and indexing that would make "strong", "blockquote" and
     *     "center" match every listing whose owner used the toolbar
     *   - the joined category name
     *   - the listing's town and suburb
     *   - its tags, via EXISTS on the pivot
     *   - its service menu, via EXISTS — "teeth whitening" is usually a line on
     *     the menu, not a word in the description
     *   - its team members' names, roles, qualifications and areas of focus,
     *     via EXISTS on the team table, and only while the badge that
     *     publishes them is live
     *
     * The whole phrase is tried everywhere, and each word on its own against
     * the name, category, tags and services: "teeth whitening" should still
     * find a menu that says "Whitening". relevanceSelect() is what puts the
     * listing that matched the whole phrase first.
     *
     * @param \CodeIgniter\Database\BaseBuilder|\CodeIgniter\Model $builder
     */
    private function applySearch($builder, string $q, array $expand = []): void
    {
        $terms = $this->searchTerms($q);
        $like  = $this->likePattern($q);

        $builder->groupStart();

        if ($terms !== []) {
            $builder->where($this->fulltextMatch($terms), null, false);
        }

        $builder
            ->orLike('xs_directory_listings.display_name', $q)
            ->orLike('xs_directory_listings.city', $q)
            // "Sandton plumber" finds the Randburg plumber who travels there.
            ->orLike('xs_directory_listings.service_areas', $q)
            ->orLike('p.name', $q);

        // A suburb match only for an address the public may see: otherwise a
        // search for "Bromhof" would say where a hidden-address owner lives.
        $builder->orWhere(
            '(xs_directory_listings.suburb LIKE ' . $like
            . " ESCAPE '!' AND NOT " . self::HIDDEN_ADDRESS_SQL . ')',
            null,
            false
        );

        // Tags and services live in their own tables, so they cannot be part
        // of the FULLTEXT index. Services carry no gate: the menu is part of
        // every free profile.
        $builder->orWhere($this->tagExists($q, $this->likeTerms($terms)), null, false);
        $builder->orWhere($this->serviceExists($q, $this->likeTerms($terms)), null, false);

        // Each word on its own, against the fields short enough for a word to
        // mean something in. Not the description: FULLTEXT above already
        // covers it, as whole words rather than any substring.
        foreach ($this->likeTerms($terms) as $term) {
            $builder->orLike('xs_directory_listings.display_name', $term)
                ->orLike('p.name', $term);
        }

        // Team members, for the same reason and in the same shape: a firm is
        // very often searched for by the name of the person you were referred
        // to, by an area of law only one of its attorneys practises, or by a
        // role or qualification ("dental hygienist", "CA(SA)"), and none of
        // those strings is anywhere on the listing row.
        //
        // The verified_until clause is not optional. Team members only render
        // for a listing with a live badge, so without it a search would return
        // a business on the strength of a panel the visitor cannot see.
        $builder->orWhere($this->teamExists($q), null, false);

        // The brands a business carries ("nike" finds the shoe shops). Free
        // for every profile, so no badge clause. Prefix only, on the phrase and
        // each word, so the name index does the work.
        $builder->orWhere($this->brandExists($q, $this->likeTerms($terms)), null, false);

        // Related words the discovery model learned: a profile that only
        // says "chemotherapy" answers "cancer" too. Descriptions only, and
        // relevanceSelect() scores it below every direct match.
        if ($expand !== []) {
            $builder->orWhere($this->fulltextMatch($expand), null, false);
        }

        $builder->groupEnd();
    }

    /**
     * How well a listing answers the search, as a number to sort by.
     *
     * Weighted by where the words were found, strongest first: the business's
     * own name, its service menu, its category, its tags, its team, and
     * anywhere in its description. A match on the whole phrase scores more
     * than a match on one of its words.
     *
     *   name 12 exact / 10 starts with / 8 contains, +3 a word (max 6)
     *   service 9 phrase / 5 a word      category 8 phrase / 4 a word
     *   tag 6 phrase / 3 a word          team 4 phrase
     *   town or service area 2           description (FULLTEXT) up to 4
     *   a related word from the discovery model 1
     *
     * Two things are deliberately NOT in it, for the reason browse() gives for
     * its own order: reviews, and the paid badge. The team weight is where the
     * badge could creep in, because team members only exist on a Verified
     * profile — so it is held at the description's level, below anything a
     * free profile can match on. A Verified business is found by its team; it
     * is not ranked above a free one for having one.
     */
    /** @param list<string> $expand related words, see applySearch() */
    private function relevanceSelect(string $q, array $expand = []): string
    {
        $db        = $this->listings->db;
        $terms     = $this->searchTerms($q);
        $wordTerms = $this->likeTerms($terms);
        $like      = $this->likePattern($q);
        $prefix    = $db->escape($db->escapeLikeString($q) . '%');
        $name      = 'xs_directory_listings.display_name';

        $nameWords = $wordTerms === [] ? '0'
            : 'LEAST(3 * (' . implode(' + ', array_map(
                fn (string $t) => '(' . $name . ' LIKE ' . $this->likePattern($t) . " ESCAPE '!')",
                $wordTerms
            )) . '), 6)';

        $parts = [
            'CASE WHEN ' . $name . ' = ' . $db->escape($q) . ' THEN 12'
                . ' WHEN ' . $name . ' LIKE ' . $prefix . " ESCAPE '!' THEN 10"
                . ' WHEN ' . $name . ' LIKE ' . $like . " ESCAPE '!' THEN 8"
                . ' ELSE ' . $nameWords . ' END',
            'GREATEST(9 * ' . $this->serviceExists($q, []) . ', 5 * ' . $this->serviceExists('', $wordTerms) . ')',
            'GREATEST(8 * COALESCE(p.name LIKE ' . $like . " ESCAPE '!', 0), 4 * " . $this->anyLike('p.name', $wordTerms) . ')',
            'GREATEST(6 * ' . $this->tagExists($q, []) . ', 3 * ' . $this->tagExists('', $wordTerms) . ')',
            '4 * ' . $this->teamExists($q),
            '2 * (COALESCE(xs_directory_listings.city LIKE ' . $like . " ESCAPE '!', 0)"
                . ' OR COALESCE(xs_directory_listings.service_areas LIKE ' . $like . " ESCAPE '!', 0))",
        ];
        if ($terms !== []) {
            $parts[] = 'LEAST(2 * ' . $this->fulltextMatch($terms) . ', 4)';
        }
        if ($expand !== []) {
            $parts[] = '(' . $this->fulltextMatch($expand) . ' > 0)';
        }

        return '(' . implode(' + ', $parts) . ')';
    }

    /**
     * "Open on public holidays", from the columns the model derives from
     * trading_hours (DirectoryListingModel::deriveHolidayColumns()). On a
     * holiday it means open today, exceptions and usual hours weekdays
     * included; otherwise, a general answer of open or usual hours.
     * Unanswered listings never match: we never claim they are open.
     *
     * $today is for tests; Directory::searchFilters() never sets it.
     */
    private function applyHolidays(BaseBuilder|Model $builder, ?string $today = null): void
    {
        // South African public holidays mean nothing on local.webscheduler.app,
        // and no other country has a calendar yet.
        if (service('site')->isApp()) {
            return;
        }
        helper('directory_hours');
        $today ??= hours_now()->format('Y-m-d');
        if (hours_calendar()->on($today) !== null) {
            $builder->where('FIND_IN_SET(' . db_connect()->escape($today) . ', xs_directory_listings.holiday_open_dates) >', 0, false);
        } else {
            $builder->whereIn('xs_directory_listings.holiday_hours', ['open', 'usual']);
        }
    }

    /**
     * Restrict to one place: a town, a visible suburb, or a service area.
     *
     * The suburb clause carries the same hidden-address guard as applySearch():
     * a filter for "Bromhof" must not return the travelling business whose
     * owner lives there and chose not to say so. Their town and the areas they
     * serve are public, and match as normal.
     */
    private function applyPlace(BaseBuilder|Model $builder, string $place, bool $branches = true): void
    {
        $like = $this->likePattern(trim($place));
        $builder->where(
            '(xs_directory_listings.city LIKE ' . $like . " ESCAPE '!'"
            . ' OR xs_directory_listings.service_areas LIKE ' . $like . " ESCAPE '!'"
            . ' OR (xs_directory_listings.suburb LIKE ' . $like . " ESCAPE '!' AND NOT " . self::HIDDEN_ADDRESS_SQL . ')'
            // A branch is somewhere customers go, so its suburb is public.
            // Not on the map (mapPoints() passes false): see its own filters.
            . ($branches ? ' OR ' . $this->branchExists('(bl.city LIKE ' . $like . " ESCAPE '!' OR bl.suburb LIKE " . $like . " ESCAPE '!')") : '')
            . ')',
            null,
            false
        );
    }

    /**
     * "Own address matches, or a branch does", for the province and city
     * filters. $cond is the SQL that follows the column ("= 'Gauteng'",
     * "LIKE '%sandton%' ESCAPE '!'"), always built here from escaped values.
     */
    private function ownOrBranch(string $column, string $cond): string
    {
        return '(xs_directory_listings.' . $column . ' ' . $cond
            . ' OR ' . $this->branchExists('bl.' . $column . ' ' . $cond) . ')';
    }

    /**
     * EXISTS over the listing's branches (directory_practice_locations).
     * Branches only render while the badge or Plus is live (getProfile()),
     * the same gate as teamExists(): without it a page would list a
     * business on the strength of a panel the visitor cannot see.
     */
    private function branchExists(string $match): string
    {
        return '(xs_directory_listings.verified_until >= CURDATE()'
            . ' AND EXISTS (SELECT 1 FROM xs_directory_practice_locations bl'
            . ' WHERE bl.listing_id = xs_directory_listings.id AND ' . $match . '))';
    }

    /**
     * "Rondebosch, Cape Town": the first branch that matched, for the card,
     * or NULL when the profile's own address matched (the card shows that
     * already) or the branches are not on show.
     */
    private function branchPlace(string $column, string $cond): string
    {
        return '(CASE WHEN xs_directory_listings.' . $column . ' ' . $cond
            . ' OR xs_directory_listings.verified_until IS NULL'
            . ' OR xs_directory_listings.verified_until < CURDATE() THEN NULL ELSE'
            . " (SELECT CONCAT_WS(', ', NULLIF(bl.suburb, ''), NULLIF(bl.city, ''))"
            . ' FROM xs_directory_practice_locations bl'
            . ' WHERE bl.listing_id = xs_directory_listings.id AND bl.' . $column . ' ' . $cond
            . ' ORDER BY bl.sort_order, bl.id LIMIT 1) END)';
    }

    /**
     * The search split into words, with the characters BOOLEAN mode treats as
     * operators removed: a stray "(" or "+" is a syntax error there, which
     * would throw rather than simply return nothing. Capped, because each word
     * becomes more SQL and nobody types ten.
     *
     * @return list<string>
     */
    private function searchTerms(string $q): array
    {
        $terms = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $q) ?? '') ?: [];
        $terms = array_values(array_unique(array_filter($terms, static fn ($t) => $t !== '')));

        return array_slice($terms, 0, 8);
    }

    /**
     * The words worth a substring match on their own: three letters or more
     * (below that, "%it%" is in every second name) and not filler — "the" in
     * "The Plumbing Co" is not a reason to match every other "The".
     *
     * @param list<string> $terms
     * @return list<string>
     */
    private function likeTerms(array $terms): array
    {
        static $filler = null;
        $filler ??= array_flip(config('Search')->fillerWords);

        $out = [];
        foreach ($terms as $term) {
            if (mb_strlen($term) >= 3 && ! isset($filler[mb_strtolower($term)])) {
                $out[] = $term;
            }
        }

        return array_slice($out, 0, 6);
    }

    /** @param list<string> $terms */
    private function fulltextMatch(array $terms): string
    {
        // Prefix-match every term; MySQL ignores tokens below
        // innodb_ft_min_token_size (3 by default), which is fine here.
        $expr = implode(' ', array_map(static fn ($t) => $t . '*', $terms));

        return 'MATCH(xs_directory_listings.display_name, xs_directory_listings.description_text, xs_directory_listings.credentials) '
            . 'AGAINST (' . $this->listings->db->escape($expr) . ' IN BOOLEAN MODE)';
    }

    /** '%term%', escaped for a LIKE with ESCAPE '!'. */
    private function likePattern(string $term): string
    {
        $db = $this->listings->db;

        return $db->escape('%' . $db->escapeLikeString($term) . '%');
    }

    /**
     * 1 when $column contains any of the words, else 0.
     *
     * @param list<string> $terms
     */
    private function anyLike(string $column, array $terms): string
    {
        if ($terms === []) {
            return '0';
        }

        return 'COALESCE(' . implode(' OR ', array_map(
            fn (string $t) => $column . ' LIKE ' . $this->likePattern($t) . " ESCAPE '!'",
            $terms
        )) . ', 0)';
    }

    /**
     * EXISTS over the listing's tags containing the phrase or any of the words.
     * Either may be empty; with both empty this is a constant 0.
     *
     * @param list<string> $terms
     */
    private function tagExists(string $phrase, array $terms): string
    {
        $match = $this->phraseOrWords('t.name', $phrase, $terms);

        return $match === null ? '0'
            : 'EXISTS (SELECT 1 FROM xs_directory_listing_tags lt'
                . ' JOIN xs_directory_tags t ON t.id = lt.tag_id'
                . ' WHERE lt.listing_id = xs_directory_listings.id AND ' . $match . ')';
    }

    /**
     * EXISTS over the listing's service menu, same contract as tagExists().
     *
     * @param list<string> $terms
     */
    private function serviceExists(string $phrase, array $terms): string
    {
        $match = $this->phraseOrWords('sv.name', $phrase, $terms);

        return $match === null ? '0'
            : 'EXISTS (SELECT 1 FROM xs_directory_listing_services sv'
                . ' WHERE sv.listing_id = xs_directory_listings.id AND ' . $match . ')';
    }

    /** EXISTS over the team, only while the badge that shows them is live. */
    private function teamExists(string $phrase): string
    {
        $like = $this->likePattern($phrase);

        return 'EXISTS (SELECT 1 FROM xs_directory_listing_team tm'
            . ' WHERE tm.listing_id = xs_directory_listings.id'
            . ' AND xs_directory_listings.verified_until >= CURDATE()'
            . ' AND (tm.name LIKE ' . $like . " ESCAPE '!'"
            . ' OR tm.role LIKE ' . $like . " ESCAPE '!'"
            . ' OR tm.credentials LIKE ' . $like . " ESCAPE '!'"
            . ' OR tm.specializations LIKE ' . $like . " ESCAPE '!'))";
    }

    /** @param list<string> $terms */
    private function brandExists(string $phrase, array $terms): string
    {
        $db       = $this->listings->db;
        $patterns = array_map(
            static fn (string $t): string => 'b.name LIKE ' . $db->escape($db->escapeLikeString($t) . '%') . " ESCAPE '!'",
            array_values(array_unique(array_merge([$phrase], $terms)))
        );

        return 'EXISTS (SELECT 1 FROM xs_directory_listing_brands b'
            . ' WHERE b.listing_id = xs_directory_listings.id'
            . ' AND (' . implode(' OR ', $patterns) . '))';
    }

    /**
     * "(col LIKE '%phrase%' OR col LIKE '%word%' …)", or null for nothing to
     * match.
     *
     * @param list<string> $terms
     */
    private function phraseOrWords(string $column, string $phrase, array $terms): ?string
    {
        $patterns = array_values(array_unique(array_merge($phrase === '' ? [] : [$phrase], $terms)));
        if ($patterns === []) {
            return null;
        }

        return '(' . implode(' OR ', array_map(
            fn (string $t) => $column . ' LIKE ' . $this->likePattern($t) . " ESCAPE '!'",
            $patterns
        )) . ')';
    }

    /**
     * Full published profile: listing + category + practice locations + tags.
     *
     * @return array<string,mixed>|null
     */
    public function getProfile(string $slug): ?array
    {
        $listing = $this->listings->findPublishedBySlug($slug);

        return $listing === null ? null : $this->profileFor($listing);
    }

    /**
     * Everything the profile page renders, for a listing row already in hand.
     * getProfile() for the public page; the owner's private preview passes a
     * pending row it found by token, so the preview is the real page.
     *
     * $overrides replaces what would be read from the child tables, for the
     * preview of unsaved edits (Manage::preview()): any of 'tags', 'services',
     * 'attributes' and 'facets', already in the shape this method builds.
     * Keys left out are read as usual.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public function profileFor(array $listing, array $overrides = []): array
    {
        $listing['category'] = ($listing['category_id'] ?? null)
            ? $this->categories->find((int) $listing['category_id'])
            : null;
        // Branches are rendered by the same panels as the listing's own address,
        // so their hours have to arrive in the same shape — decoded, keyed
        // mon..sun. Decoding here rather than in the view keeps _hours_panel.php
        // free of any branch-specific case.
        //
        // Extra branches are part of the paid badge, gated here for the same
        // reason as the team below: the panel and the JSON-LD both follow from
        // this one decision. A lapsed badge hides the rows; it never deletes them.
        $listing['locations'] = listing_has_premium($listing)
            ? array_map(
                static function (array $loc): array {
                    $loc['holiday_rule']  = hours_holiday_rule($loc['trading_hours'] ?? null);
                    $loc['trading_hours'] = hours_decode($loc['trading_hours'] ?? null);

                    return $loc;
                },
                $this->locations->forListing((int) $listing['id'])
            )
            : [];
        $listing['tags']           = $overrides['tags'] ?? $this->tags->namesForListing((int) $listing['id']);
        // The complex this business sits in, and how many others are in it, so
        // the profile can offer "what else is in this building?".
        $listing['venue'] = ($listing['venue_id'] ?? null)
            ? $this->venues->find((int) $listing['venue_id'])
            : null;
        if (is_array($listing['venue'])) {
            $listing['venue']['listing_count'] = $this->venueListingCount((int) $listing['venue_id']);
        }
        // A Place profile and the venue made from it link both ways: the place
        // lists what is inside it, and a business inside links to the place.
        $listing['inside'] = null;
        $placeId           = (int) ($listing['venue']['listing_id'] ?? 0);
        if ($placeId === (int) $listing['id']) {
            $listing['inside'] = $this->insidePlace((int) $listing['venue_id'], (int) $listing['id']);
        } elseif ($placeId > 0) {
            $listing['venue']['place'] = $this->placeProfileFor($listing['venue']);
        }
        $listing['photos']         = $this->photos->forListing((int) $listing['id']);
        $listing['brands']         = (new ListingBrandService())->forListing((int) $listing['id']);

        // Free for every listing — see ServiceMenuService.
        $menu                   = new ServiceMenuService();
        $listing['services']    = $overrides['services'] ?? $menu->servicesFor((int) $listing['id']);
        $listing['attributes']  = $overrides['attributes'] ?? $menu->attributeLabelsFor((int) $listing['id']);
        // The public holiday answer is read from the same JSON before the
        // days are decoded out of it (directory_hours_helper).
        $listing['holiday_rule']   = hours_holiday_rule($listing['trading_hours'] ?? null);
        $listing['trading_hours']  = hours_decode($listing['trading_hours'] ?? null);

        // Also free, and resolved against the listing's *current* category, so
        // a school later re-filed as a tutor stops showing the grades it used
        // to offer rather than showing them under the wrong labels.
        $listing['facets'] = $overrides['facets'] ?? (new ListingFacetService())->displayFor(
            (int) $listing['id'],
            $listing['category']['group_name'] ?? null,
            $listing['category']['slug'] ?? null,
        );

        // The uploaded menu — free, and gated on the CURRENT category for the
        // facets' reason: a restaurant re-filed as a florist stops showing it.
        $listing['menu'] = ListingMenuService::offersMenu(
            $listing['category']['group_name'] ?? null,
            $listing['category']['slug'] ?? null,
        ) ? (new ListingMenuService())->forListing((int) $listing['id']) : [];

        // Team members are part of the paid badge, so the gate is here at load
        // rather than in the view: one place decides, the panel and the
        // JSON-LD both follow from it, and a listing without a live badge does
        // not pay for the query. The rows are untouched — see
        // TeamMemberService::canManage().
        $listing['team'] = listing_has_premium($listing)
            ? $this->team->forListing((int) $listing['id'])
            : [];

        // The profile video, on the team's terms: paid, so gated here at load,
        // and a lapsed badge hides it without touching the stored columns.
        $listing['video'] = service('listingVideo')->forProfile($listing);

        // Free for every listing. Published reviews only, and no query at all
        // for a listing whose stored review_count is 0. The panel and the
        // JSON-LD both read this one copy, so the markup matches the page.
        $listing['reviews'] = (new ReviewService())->forProfile($listing);

        return $listing;
    }

    /**
     * Curated listings for the homepage — the ones an admin has actually
     * flagged via admin/feature/{id}.
     *
     * Deliberately strict. This used to order by is_featured without filtering
     * on it, which quietly turned "Featured" into "most recent" and made the
     * flag meaningless; recent() now covers freshness, so the section can mean
     * what it says and simply not render when nothing is flagged.
     *
     * @return array<int,array<string,mixed>>
     */
    public function featured(int $limit = 8): array
    {
        $rows = $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug, p.group_name AS category_group'
                . ', v.name AS venue_name, v.slug AS venue_slug')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->join('xs_directory_venues v', 'v.id = xs_directory_listings.venue_id', 'left')
            ->where($this->published('xs_directory_listings.'), null, false)
            ->where('xs_directory_listings.is_featured', 1)
            ->orderBy('xs_directory_listings.published_at', 'DESC')
            ->limit($limit)
            ->findAll();

        // The home carousel shows each business on its own first gallery
        // photo. One query for the whole row, first photo per listing by the
        // owner's own order, rather than one lookup per card.
        if ($rows !== []) {
            $covers = [];
            foreach ($this->photos
                ->select('listing_id, path')
                ->whereIn('listing_id', array_column($rows, 'id'))
                ->orderBy('sort_order', 'ASC')
                ->orderBy('id', 'ASC')
                ->findAll() as $photo) {
                $covers[(int) $photo['listing_id']] ??= (string) $photo['path'];
            }
            foreach ($rows as $i => $row) {
                $rows[$i]['cover_path'] = $covers[(int) $row['id']] ?? null;
            }
        }

        return $rows;
    }

    /**
     * Most recently published listings — the homepage's freshness signal, and
     * the reason featured() no longer needs a recency fallback.
     *
     * This is the one surface still led by recency, which is exactly why it
     * needs a floor. Search results are ordered by quality_score now, so a thin
     * new listing sinks there; here it would land at the top by definition, and
     * "the newest to join" would keep being a parade of empty profiles.
     *
     * The floor is a gate rather than a sort key on purpose: the section is
     * still about who is new, just not about who is new and has not bothered.
     *
     * @param int|null $minScore overrides Config\Directory::$recentMinQuality
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 4, ?int $minScore = null): array
    {
        $floor = $minScore ?? (int) config('Directory')->recentMinQuality;

        return $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug, p.group_name AS category_group'
                . ', v.name AS venue_name, v.slug AS venue_slug')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->join('xs_directory_venues v', 'v.id = xs_directory_listings.venue_id', 'left')
            ->where($this->published('xs_directory_listings.'), null, false)
            // groupStart/groupEnd, not a bare orWhere — same discipline the
            // comment above applySearch() demands. An ungrouped orWhere here
            // would escape the status filter and put unpublished listings on
            // the home page.
            ->groupStart()
                // Fail open on a listing we have never scored. A profile should
                // not be hidden because our own sweep has not reached it yet,
                // and during a deploy this is what stops the strip emptying
                // between the migration and the backfill.
                ->where('xs_directory_listings.quality_scored_at', null)
                ->orWhere('xs_directory_listings.quality_score >=', $floor)
            ->groupEnd()
            ->orderBy('xs_directory_listings.published_at', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * A main-category group by its ?group= slug, or null.
     *
     * @return array<string,mixed>|null
     */
    public function findGroupBySlug(string $slug): ?array
    {
        return $this->groups->findBySlug($slug);
    }

    /** The group_name a ?group= filter narrows to, or null for none or an unknown slug. */
    private function groupNameFor(array $filters): ?string
    {
        $slug = (string) ($filters['group'] ?? '');
        if ($slug === '') {
            return null;
        }
        $group = $this->groups->findBySlug($slug);

        return $group === null ? null : (string) $group['name'];
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        return $this->categories->active();
    }

    /**
     * Published listing count per category, for the "browse all categories"
     * hub page and anywhere else that needs to avoid linking to an empty
     * (and therefore 404ing — see Directory::renderLanding()) landing page.
     *
     * @return array<int,int> category_id => count
     */
    public function categoryListingCounts(): array
    {
        $rows = $this->listings
            ->select('category_id, COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('category_id IS NOT NULL')
            ->groupBy('category_id')
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['category_id']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * All active categories grouped by group_name, each annotated with its
     * published listing count. categories() already orders by the groups'
     * admin-set position, so insertion order gives that order for free.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function categoriesGrouped(): array
    {
        $counts = $this->categoryListingCounts();
        $groups = [];
        foreach ($this->categories() as $c) {
            $c['listing_count'] = $counts[(int) $c['id']] ?? 0;
            $groups[(string) ($c['group_name'] ?: 'Other')][] = $c;
        }
        return $groups;
    }

    /** @return array<int,string> */
    public function provinces(): array
    {
        return self::SA_PROVINCES;
    }

    /**
     * The categories worth putting on the homepage: those with listings, busiest
     * first. Built from the two methods above rather than a third query, and
     * filtered to count > 0 because renderLanding() 404s on an empty category.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topCategories(int $limit = 8): array
    {
        $counts = $this->categoryListingCounts();
        $out    = [];

        foreach ($this->categories() as $c) {
            $n = $counts[(int) $c['id']] ?? 0;
            if ($n > 0) {
                $c['listing_count'] = $n;
                $out[]              = $c;
            }
        }

        usort($out, static fn (array $a, array $b) => $b['listing_count'] <=> $a['listing_count']);

        return array_slice($out, 0, $limit);
    }

    /**
     * Published listings per province, countrywide. The global sibling of
     * provinceCountsForCategory() — powers the homepage location cards and
     * decides which province landing pages are worth linking to.
     *
     * @return array<string,int> province name => count, busiest first
     */
    public function provinceCounts(): array
    {
        $rows = $this->listings
            ->select('province, COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('province !=', '')
            ->groupBy('province')
            ->orderBy('c', 'DESC')
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            if (($r['province'] ?? '') !== '') {
                $out[(string) $r['province']] = (int) $r['c'];
            }
        }
        return $this->addCounts($out, $this->branchCounts('b.province', "b.province <> '' AND b.province <> l.province"));
    }

    /**
     * Profiles a place gains through branches alone, as place => count, for
     * the tallies beside browse()'s province and city filters. Same gate as
     * branchExists(), and a profile counts once per place, never where its
     * own address already counts it ($where says what "already" means).
     *
     * @return array<string,int>
     */
    private function branchCounts(string $groupBy, string $where): array
    {
        $rows = db_connect()->table('xs_directory_practice_locations b')
            ->select($groupBy . ' AS k, COUNT(DISTINCT l.id) AS c', false)
            ->join('xs_directory_listings l', 'l.id = b.listing_id')
            ->where($this->published('l.'), null, false)
            ->where('l.deleted_at', null)
            ->where('l.verified_until >= CURDATE()', null, false)
            ->where($where, null, false)
            ->groupBy($groupBy, false)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            if ((string) ($r['k'] ?? '') !== '') {
                $out[(string) $r['k']] = (int) $r['c'];
            }
        }

        return $out;
    }

    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     * @return array<string,int> busiest first
     */
    private function addCounts(array $a, array $b): array
    {
        foreach ($b as $k => $c) {
            $a[$k] = ($a[$k] ?? 0) + $c;
        }
        arsort($a);

        return $a;
    }

    /**
     * Published profiles per region of one country, busiest first: the
     * region chips on a local.webscheduler.app country page. The
     * counterpart of provinceCounts() for every country but South Africa,
     * whose regions live in `region` rather than `province`.
     *
     * @return array<string,int> region => count
     */
    public function regionCounts(string $countryCode): array
    {
        return $this->countsBy('region', $countryCode, null, 50);
    }

    /**
     * The busiest cities in a country, or in one of its regions.
     *
     * @return array<string,int> city => count
     */
    public function cityCountsIn(string $countryCode, ?string $region = null, int $limit = 8): array
    {
        return $this->countsBy('city', $countryCode, $region, $limit);
    }

    /**
     * Categories present in a country or one of its regions, busiest first,
     * each with a 'listing_count' for that place, as topCategoriesInProvince().
     *
     * @return array<int,array<string,mixed>>
     */
    public function topCategoriesIn(string $countryCode, ?string $region = null, int $limit = 8): array
    {
        $builder = $this->listings
            ->select('category_id, COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('country_code', $countryCode)
            ->where('category_id IS NOT NULL');
        if ($region !== null) {
            $builder->where('region', $region);
        }
        $rows = $builder->groupBy('category_id')->orderBy('c', 'DESC')->limit($limit)->findAll();

        $byId = [];
        foreach ($this->categories() as $c) {
            $byId[(int) $c['id']] = $c;
        }

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['category_id'];
            if (isset($byId[$cid])) {
                $cat                  = $byId[$cid];
                $cat['listing_count'] = (int) $r['c'];
                $out[]                = $cat;
            }
        }

        return $out;
    }

    /**
     * Where one category's profiles are on local.webscheduler.app: per
     * country (country code => count), or per region of one country (region
     * => count). The .app counterpart of provinceCountsForCategory().
     *
     * @return array<string,int>
     */
    public function placeCountsForCategory(int $categoryId, ?string $countryCode = null): array
    {
        $column  = $countryCode === null ? 'country_code' : 'region';
        $builder = $this->listings
            ->select($column . ', COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('category_id', $categoryId)
            ->where($column . ' IS NOT NULL')
            ->where($column . ' !=', '');
        if ($countryCode !== null) {
            $builder->where('country_code', $countryCode);
        }

        $out = [];
        foreach ($builder->groupBy($column)->orderBy('c', 'DESC')->findAll() as $r) {
            $out[(string) $r[$column]] = (int) $r['c'];
        }

        return $out;
    }

    /** @return array<string,int> */
    private function countsBy(string $column, string $countryCode, ?string $region, int $limit): array
    {
        $builder = $this->listings
            ->select($column . ', COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('country_code', $countryCode)
            ->where($column . ' !=', '');
        if ($region !== null) {
            $builder->where('region', $region);
        }
        $rows = $builder->groupBy($column)->orderBy('c', 'DESC')->limit($limit)->findAll();

        $out = [];
        foreach ($rows as $r) {
            if (($r[$column] ?? '') !== '') {
                $out[(string) $r[$column]] = (int) $r['c'];
            }
        }

        return $out;
    }

    /**
     * The busiest cities in a province, for the homepage card's "Johannesburg ·
     * Pretoria · Soweto" line and the province page's city chips.
     *
     * @return array<string,int> city => count
     */
    public function cityCountsForProvince(string $province, int $limit = 8): array
    {
        if ($province === '') {
            return [];
        }

        $rows = $this->listings
            ->select('city, COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('province', $province)
            ->where('city !=', '')
            ->groupBy('city')
            ->orderBy('c', 'DESC')
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            if (($r['city'] ?? '') !== '') {
                $out[(string) $r['city']] = (int) $r['c'];
            }
        }
        $p = db_connect()->escape($province);

        return array_slice($this->addCounts($out, $this->branchCounts(
            'b.city',
            "b.province = {$p} AND b.city <> '' AND NOT (l.province = {$p} AND l.city = b.city)"
        )), 0, $limit, true);
    }

    /**
     * Categories present in a province, busiest first — the cross-links on a
     * province landing page. Each entry gains a 'listing_count' scoped to the
     * province, so the chips can't advertise a count the page won't show.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topCategoriesInProvince(string $province, int $limit = 8): array
    {
        if ($province === '') {
            return [];
        }

        $rows = $this->listings
            ->select('category_id, COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('province', $province)
            ->where('category_id IS NOT NULL')
            ->groupBy('category_id')
            ->orderBy('c', 'DESC')
            ->findAll();

        $counts = [];
        foreach ($rows as $r) {
            $counts[(string) $r['category_id']] = (int) $r['c'];
        }
        $p      = db_connect()->escape($province);
        $counts = $this->addCounts($counts, $this->branchCounts(
            'l.category_id',
            "b.province = {$p} AND l.province <> {$p} AND l.category_id IS NOT NULL"
        ));
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $cid => $c) {
            $rows[] = ['category_id' => $cid, 'c' => $c];
        }

        if ($rows === []) {
            return [];
        }

        $byId = [];
        foreach ($this->categories() as $c) {
            $byId[(int) $c['id']] = $c;
        }

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['category_id'];
            if (isset($byId[$cid])) {
                $cat                  = $byId[$cid];
                $cat['listing_count'] = (int) $r['c'];
                $out[]                = $cat;
            }
        }
        return $out;
    }

    /**
     * Headline numbers for the homepage trust bar.
     *
     * @return array{listings:int,categories:int,provinces:int,countries:int}
     */
    public function stats(): array
    {
        $counts = $this->categoryListingCounts();

        return [
            'listings'   => (int) $this->listings->where($this->published(), null, false)->countAllResults(),
            'categories' => count($counts),
            'provinces'  => count($this->provinceCounts()),
            // local.webscheduler.app counts countries where co.za counts provinces.
            'countries'  => service('site')->isApp()
                ? (int) db_connect()->table('xs_directory_listings')->select('COUNT(DISTINCT country_code) AS n')
                    ->where($this->published(), null, false)->where('deleted_at', null)->get()->getRow('n')
                : 1,
        ];
    }

    /**
     * Tag names attached to a listing — used to prefill the "areas of focus"
     * field on the owner and admin edit forms.
     *
     * @return array<int,string>
     */
    public function tagsForListing(int $listingId): array
    {
        return $this->tags->namesForListing($listingId);
    }

    /** @return array<string,mixed>|null */
    public function findCategoryBySlug(string $slug): ?array
    {
        $row = $this->categories->where('slug', $slug)->where('is_active', 1)->first();
        return is_array($row) ? $row : null;
    }

    /**
     * Snap a province name from an outside source to its canonical spelling.
     *
     * Geocoders return whatever their own data says — "Kwazulu-Natal",
     * "GAUTENG", or a province that simply isn't one of the nine. The province
     * column feeds the landing-page slugs and the province filter, so anything
     * that doesn't match exactly has to become empty rather than a value no
     * filter will ever select.
     */
    public static function normaliseProvince(string $name): string
    {
        $name = trim($name);
        foreach (self::SA_PROVINCES as $known) {
            if (strcasecmp($known, $name) === 0) {
                return $known;
            }
        }

        return '';
    }

    /** Match a province slug ("western-cape") back to its canonical name. */
    public function provinceFromSlug(string $slug): ?string
    {
        foreach (self::SA_PROVINCES as $p) {
            if (slugify($p) === $slug) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Provinces that actually hold published listings in a category, with
     * counts. Powers the sibling links on a landing page and the sitemap's
     * decision about which pages are worth submitting.
     *
     * @return array<string,int> province name => count
     */
    public function provinceCountsForCategory(int $categoryId): array
    {
        $rows = $this->listings
            ->select('province, COUNT(*) AS c')
            ->where($this->published(), null, false)
            ->where('category_id', $categoryId)
            ->where('province !=', '')
            ->groupBy('province')
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            if (($r['province'] ?? '') !== '') {
                $out[(string) $r['province']] = (int) $r['c'];
            }
        }
        return $this->addCounts($out, $this->branchCounts(
            'b.province',
            'l.category_id = ' . $categoryId . " AND b.province <> '' AND b.province <> l.province"
        ));
    }

    /**
     * Other published listings sharing a category and province, for the
     * "more like this" block. Excludes the listing being viewed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function relatedListings(int $listingId, ?int $categoryId, string $province, int $limit = 3): array
    {
        if ($categoryId === null) {
            return [];
        }

        $builder = $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug, p.group_name AS category_group'
                . ', v.name AS venue_name, v.slug AS venue_slug')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->join('xs_directory_venues v', 'v.id = xs_directory_listings.venue_id', 'left')
            ->where($this->published('xs_directory_listings.'), null, false)
            ->where('xs_directory_listings.category_id', $categoryId)
            ->where('xs_directory_listings.id !=', $listingId);

        if ($province !== '') {
            $builder->where('xs_directory_listings.province', $province);
        }

        // Same three keys as browse(), for the same reason: these are three
        // slots on someone else's profile page, and a fuller neighbour is a
        // better suggestion than a newer one.
        return $builder->orderBy('xs_directory_listings.is_featured', 'DESC')
            ->orderBy('xs_directory_listings.quality_score', 'DESC')
            ->orderBy('xs_directory_listings.published_at', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Sibling categories in the same group, for cross-linking landing pages.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoriesInGroup(string $group, int $excludeId, int $limit = 8): array
    {
        if ($group === '') {
            return [];
        }
        return $this->categories
            ->where('group_name', $group)
            ->where('is_active', 1)
            ->where('id !=', $excludeId)
            ->orderBy('sort_order', 'ASC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Landing-page URL sets for the sitemap: every category with published
     * listings, plus each category × province combination that clears the
     * indexable threshold. Combinations below it are deliberately omitted —
     * submitting near-empty pages is a thin-content problem, not traffic.
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    public function sitemapLandingUrls(int $minListings): array
    {
        $today = date('Y-m-d');
        $out   = [];

        $rows = $this->listings
            ->select('category_id, province, COUNT(*) AS c, MAX(updated_at) AS m')
            ->where($this->published(), null, false)
            ->where('category_id IS NOT NULL')
            ->groupBy('category_id, province')
            ->findAll();

        $byCategory = [];
        foreach ($rows as $r) {
            $cid = (int) $r['category_id'];
            $byCategory[$cid]['count'] = ($byCategory[$cid]['count'] ?? 0) + (int) $r['c'];
            $byCategory[$cid]['m']     = max($byCategory[$cid]['m'] ?? '', (string) $r['m']);
        }

        $slugs = [];
        foreach ($this->categories->findAll() as $c) {
            $slugs[(int) $c['id']] = (string) $c['slug'];
        }

        foreach ($byCategory as $cid => $info) {
            if (isset($slugs[$cid]) && $info['count'] >= $minListings) {
                $out[] = [
                    'loc'     => base_url('directory/' . $slugs[$cid]),
                    'lastmod' => substr($info['m'] ?: $today, 0, 10),
                ];
            }
        }

        // Category and province pages count branches (browse()), so these
        // entries must too. The category totals above stay one per profile.
        $branches = $this->branchCounts(
            "CONCAT(l.category_id, '|', b.province)",
            "l.category_id IS NOT NULL AND b.province <> '' AND b.province <> l.province"
        );
        foreach ($rows as &$r) {
            $key = (int) $r['category_id'] . '|' . (string) ($r['province'] ?? '');
            if (isset($branches[$key])) {
                $r['c'] = (int) $r['c'] + $branches[$key];
                unset($branches[$key]);
            }
        }
        unset($r);
        foreach ($branches as $key => $c) {
            [$cid, $province] = explode('|', $key, 2);
            $rows[] = ['category_id' => (int) $cid, 'province' => $province, 'c' => $c, 'm' => $today];
        }

        foreach ($rows as $r) {
            $cid      = (int) $r['category_id'];
            $province = (string) ($r['province'] ?? '');
            if ($province === '' || ! isset($slugs[$cid]) || (int) $r['c'] < $minListings) {
                continue;
            }
            $out[] = [
                'loc'     => base_url('directory/' . $slugs[$cid] . '/' . slugify($province)),
                'lastmod' => substr((string) $r['m'] ?: $today, 0, 10),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findVenueBySlug(string $slug): ?array
    {
        return $this->venues->findBySlug($slug);
    }

    /**
     * What a Place profile shows under "Inside": the published businesses on
     * its venue, minus the place itself. browse() rather than a query of its
     * own, so hidden address listings stay out exactly as on the venue page.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function insidePlace(int $venueId, int $placeId, int $limit = 12): array
    {
        $items = array_values(array_filter(
            $this->browse(['venue' => $venueId], 1, $limit + 1)['items'] ?? [],
            static fn (array $r): bool => (int) $r['id'] !== $placeId
        ));

        return [
            'items' => array_slice($items, 0, $limit),
            'total' => max(0, $this->venueListingCount($venueId) - 1),
        ];
    }

    /**
     * The published Place profile a venue was made from, if any.
     *
     * @param array<string,mixed> $venue
     * @return array{display_name:string,slug:string}|null
     */
    public function placeProfileFor(array $venue): ?array
    {
        $id = (int) ($venue['listing_id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $row = $this->listings->select('display_name, slug')->where($this->published(), null, false)->find($id);

        return $row ?: null;
    }

    /** Published listings in a venue. */
    public function venueListingCount(int $venueId): int
    {
        return $this->listings
            ->where($this->published(), null, false)
            ->where('venue_id', $venueId)
            ->countAllResults();
    }

    /**
     * Categories present in one venue, for the chips that filter within it.
     * Counted rather than listed flat so a 262-shop complex shows the trades
     * that are actually in it, biggest first.
     *
     * @return array<int,array{name:string,slug:string,c:int}>
     */
    public function categoryCountsForVenue(int $venueId, int $limit = 12): array
    {
        $rows = $this->listings
            ->select('p.name AS name, p.slug AS slug, COUNT(*) AS c', false)
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'inner')
            ->where($this->published('xs_directory_listings.'), null, false)
            ->where('xs_directory_listings.venue_id', $venueId)
            ->groupBy('p.id')
            ->orderBy('c', 'DESC')
            ->limit($limit)
            ->findAll();

        return array_map(
            static fn (array $r): array => ['name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'c' => (int) $r['c']],
            $rows
        );
    }

    /**
     * Venue pages worth submitting — same threshold rule as the landing and
     * province emitters. An inactive venue is never listed: its page 404s.
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    public function sitemapVenueUrls(int $minListings): array
    {
        $today  = date('Y-m-d');
        $counts = $this->venues->listingCounts();

        $out = [];
        foreach ($this->venues->active() as $venue) {
            if (($counts[(int) $venue['id']] ?? 0) < $minListings) {
                continue;
            }
            $out[] = [
                'loc'     => base_url('directory/at/' . $venue['slug']),
                'lastmod' => substr((string) ($venue['updated_at'] ?: $today), 0, 10),
            ];
        }

        return $out;
    }

    /**
     * Province landing pages worth submitting. Same threshold rule as
     * sitemapLandingUrls(): a province below it renders (and is linked from the
     * homepage) but stays out of the sitemap and carries noindex.
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    public function sitemapProvinceUrls(int $minListings): array
    {
        $today = date('Y-m-d');

        $rows = $this->listings
            ->select('province, COUNT(*) AS c, MAX(updated_at) AS m')
            ->where($this->published(), null, false)
            ->where('province !=', '')
            ->groupBy('province')
            ->findAll();

        // The page counts branches (browse()), so its sitemap entry must too.
        $branches = $this->branchCounts('b.province', "b.province <> '' AND b.province <> l.province");
        foreach (array_keys($branches) as $province) {
            if (! in_array($province, array_column($rows, 'province'), true)) {
                $rows[] = ['province' => $province, 'c' => 0, 'm' => $today];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $province = (string) ($r['province'] ?? '');
            if ($province === '' || (int) $r['c'] + ($branches[$province] ?? 0) < $minListings) {
                continue;
            }
            $out[] = [
                'loc'     => base_url('directory/province/' . slugify($province)),
                'lastmod' => substr((string) $r['m'] ?: $today, 0, 10),
            ];
        }

        return $out;
    }

    /**
     * local.webscheduler.app's country and region pages that clear the
     * landing threshold, for its sitemap. Empty on co.za, whose places are
     * provinces (sitemapProvinceUrls()).
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    public function sitemapCountryUrls(int $minListings): array
    {
        if (! service('site')->isApp()) {
            return [];
        }

        $countries = config('Countries');
        $today     = date('Y-m-d');
        $rows      = $this->listings
            ->select('country_code, region, COUNT(*) AS c, MAX(updated_at) AS m')
            ->where($this->published(), null, false)
            ->groupBy(['country_code', 'region'])
            ->findAll();

        $byCountry = [];
        $out       = [];
        foreach ($rows as $r) {
            $code = (string) ($r['country_code'] ?? '');
            $slug = $countries->profile($code)['slug'] ?? null;
            if ($slug === null) {
                continue;
            }
            $byCountry[$code]['c'] = ($byCountry[$code]['c'] ?? 0) + (int) $r['c'];
            $byCountry[$code]['m'] = max($byCountry[$code]['m'] ?? '', (string) $r['m']);

            $region = (string) ($r['region'] ?? '');
            if ($region !== '' && (int) $r['c'] >= $minListings && in_array($region, $countries->regions($code), true)) {
                $out[] = ['loc' => base_url('directory/' . $slug . '/' . slugify($region)), 'lastmod' => substr($r['m'] ?: $today, 0, 10)];
            }
        }
        foreach ($byCountry as $code => $agg) {
            if ($agg['c'] >= $minListings) {
                $out[] = ['loc' => base_url('directory/' . $countries->profile($code)['slug']), 'lastmod' => substr($agg['m'] ?: $today, 0, 10)];
            }
        }

        // And each category in each country and region, by the same rule.
        $slugs = array_column($this->categories(), 'slug', 'id');
        $rows  = $this->listings
            ->select('category_id, country_code, region, COUNT(*) AS c, MAX(updated_at) AS m')
            ->where($this->published(), null, false)
            ->where('category_id IS NOT NULL')
            ->groupBy(['category_id', 'country_code', 'region'])
            ->findAll();
        $byCategory = [];
        foreach ($rows as $r) {
            $code = (string) ($r['country_code'] ?? '');
            $slug = $countries->profile($code)['slug'] ?? null;
            $cat  = $slugs[(int) $r['category_id']] ?? null;
            if ($slug === null || $cat === null) {
                continue;
            }
            $key                   = $cat . '/' . $slug;
            $byCategory[$key]['c'] = ($byCategory[$key]['c'] ?? 0) + (int) $r['c'];
            $byCategory[$key]['m'] = max($byCategory[$key]['m'] ?? '', (string) $r['m']);

            $region = (string) ($r['region'] ?? '');
            if ($region !== '' && (int) $r['c'] >= $minListings && in_array($region, $countries->regions($code), true)) {
                $out[] = ['loc' => base_url('directory/' . $key . '/' . slugify($region)), 'lastmod' => substr($r['m'] ?: $today, 0, 10)];
            }
        }
        foreach ($byCategory as $key => $agg) {
            if ($agg['c'] >= $minListings) {
                $out[] = ['loc' => base_url('directory/' . $key), 'lastmod' => substr($agg['m'] ?: $today, 0, 10)];
            }
        }

        return $out;
    }

    /**
     * Sitemap URLs for published listings.
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    public function sitemapUrls(): array
    {
        $config  = config('Directory');
        $builder = $this->listings
            ->select('slug, updated_at')
            ->where($this->published(), null, false)
            // listing_is_indexable() in SQL: a page that says noindex has no
            // business in the sitemap. Grouped so the OR cannot escape the
            // status filter, and unscored rows pass for the reason recent() gives.
            ->groupStart()
                ->where('quality_scored_at', null)
                ->orWhere('quality_score >=', (int) $config->indexMinQuality)
            ->groupEnd();

        // The description bar, which applies to unscored rows too. CHAR_LENGTH,
        // not LENGTH: the helper counts characters, and LENGTH counts bytes.
        $minText = (int) $config->indexMinDescription;
        if ($minText > 0) {
            $builder->where('CHAR_LENGTH(TRIM(COALESCE(description_text, \'\'))) >=', $minText, false);
        }

        $rows = $builder->orderBy('updated_at', 'DESC')->findAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'loc'     => base_url('directory/' . $r['slug']),
                'lastmod' => substr((string) ($r['updated_at'] ?? date('Y-m-d')), 0, 10),
            ];
        }
        return $out;
    }
}
