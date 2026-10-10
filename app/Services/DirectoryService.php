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

    public function __construct()
    {
        helper(['directory_hours', 'directory_ui']);
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
            ->where('xs_directory_listings.status', 'published');

        $q = trim($filters['q'] ?? '');
        // Ranked by how well each listing matches only when the visitor asked
        // a question with words in it and no other order: "near me" is ordered
        // by distance and "newest" by date, and both are what was asked for.
        $byRelevance = $q !== '' && $near === null && ($filters['sort'] ?? '') !== 'new';
        if ($byRelevance) {
            $builder->select($this->relevanceSelect($q) . ' AS relevance', false);
        }
        if ($q !== '') {
            // Everything applySearch adds is one OR group. Any filter appended
            // after it must be a plain where() — an orWhere here would escape
            // that group and quietly widen the search instead of narrowing it.
            $this->applySearch($builder, $q);
        }
        if (! empty($filters['category'])) {
            $builder->where('p.slug', $filters['category']);
        }
        if ($groupName !== null) {
            $builder->where('p.group_name', $groupName);
        }
        if (! empty($filters['province'])) {
            $builder->where('xs_directory_listings.province', $filters['province']);
        }
        if (! empty($filters['city'])) {
            $builder->like('xs_directory_listings.city', trim($filters['city']));
        }
        if (! empty($filters['place'])) {
            $this->applyPlace($builder, (string) $filters['place']);
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
            // The second key here is not decoration. A suburb-precision geocode
            // gives every listing in that suburb the identical centroid, so
            // distance_m ties exactly and the order used to be arbitrary.
            $builder->orderBy('distance_m', 'ASC', false)
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
     * the one thing a suggestion list must never do. These are five small
     * lookups with their own small limits.
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
            ->where('status', 'published')
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

        // The people inside a business, so typing the name of the dentist
        // you were referred to offers the practice. Same gate as the team
        // EXISTS in applySearch(): a member is only offered while the badge
        // that publishes the team panel is live, or the row would land on a
        // profile that does not show them. The link goes to their card.
        helper('directory_ui');
        $staff = $db->table('xs_directory_listing_team tm')
            ->select('tm.name, tm.slug, tm.role, l.display_name, l.slug AS listing_slug')
            ->join('xs_directory_listings l', 'l.id = tm.listing_id')
            ->where('l.status', 'published')
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
            ->where('status', 'published')
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

        return array_slice($out, 0, max(1, $limit));
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
        $key = 'search_vocabulary_v1';
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
            ->where('status', 'published')->where('city !=', '')
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
            ->where('status', 'published')->where('suburb !=', '')
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
                ->where('l.status', 'published')->where('l.deleted_at', null)
                ->limit(5000)->get()->getResultArray(), 'name'),
            array_column($db->table('xs_directory_tags t')
                ->select('DISTINCT t.name', false)
                ->join('xs_directory_listing_tags lt', 'lt.tag_id = t.id')
                ->join('xs_directory_listings l', 'l.id = lt.listing_id')
                ->where('l.status', 'published')->where('l.deleted_at', null)
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
        $rows = $this->listings
            ->select('p.name AS category, xs_directory_listings.city, COUNT(*) AS c', false)
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id')
            ->where('xs_directory_listings.status', 'published')
            ->where('p.is_active', 1)
            ->where('xs_directory_listings.city !=', '')
            ->groupBy('p.id, p.name, xs_directory_listings.city')
            ->orderBy('c', 'DESC')
            ->orderBy('p.name', 'ASC')
            ->limit(40)
            ->findAll();

        $out = $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['category']])) {
                continue;
            }
            $seen[$row['category']] = true;
            $out[] = $row['category'] . ' in ' . trim((string) $row['city']);
            if (count($out) >= $limit) {
                break;
            }
        }

        return count($out) >= 3 ? $out : array_slice(config('Search')->fallbackExamples, 0, $limit);
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
            ->where('status', 'published')
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
            . ' xs_directory_listings.phone, xs_directory_listings.trading_hours,'
            . ' xs_directory_listings.offers_online_booking, xs_directory_listings.booking_url,'
            . ' xs_directory_listings.address_line, xs_directory_listings.address_line_2,'
            . ' xs_directory_listings.suburb, xs_directory_listings.city,'
            . ' xs_directory_listings.province, xs_directory_listings.postal_code,'
            . ' p.name AS category_name';

        if ($near !== null) {
            $select .= ', ' . $this->distanceSelect($near) . ' AS distance_m';
        }

        $builder = $this->listings
            ->select($select, false)
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->where('xs_directory_listings.status', 'published');

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
        if (! empty($filters['province'])) {
            $builder->where('xs_directory_listings.province', $filters['province']);
        }
        if (! empty($filters['city'])) {
            $builder->like('xs_directory_listings.city', trim($filters['city']));
        }
        if (! empty($filters['place'])) {
            $this->applyPlace($builder, (string) $filters['place']);
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
        // Same bounding box that vets everything else entering this app. A
        // position outside South Africa cannot produce a useful result here,
        // and silently searching from it would return the whole directory
        // sorted by distance to another continent.
        if (! NominatimGeocoder::isPlausible((float) $lat, (float) $lng)) {
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
    private function applySearch($builder, string $q): void
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
     *
     * Two things are deliberately NOT in it, for the reason browse() gives for
     * its own order: reviews, and the paid badge. The team weight is where the
     * badge could creep in, because team members only exist on a Verified
     * profile — so it is held at the description's level, below anything a
     * free profile can match on. A Verified business is found by its team; it
     * is not ranked above a free one for having one.
     */
    private function relevanceSelect(string $q): string
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

        return '(' . implode(' + ', $parts) . ')';
    }

    /**
     * Restrict to one place: a town, a visible suburb, or a service area.
     *
     * The suburb clause carries the same hidden-address guard as applySearch():
     * a filter for "Bromhof" must not return the travelling business whose
     * owner lives there and chose not to say so. Their town and the areas they
     * serve are public, and match as normal.
     */
    private function applyPlace(BaseBuilder|Model $builder, string $place): void
    {
        $like = $this->likePattern(trim($place));
        $builder->where(
            '(xs_directory_listings.city LIKE ' . $like . " ESCAPE '!'"
            . ' OR xs_directory_listings.service_areas LIKE ' . $like . " ESCAPE '!'"
            . ' OR (xs_directory_listings.suburb LIKE ' . $like . " ESCAPE '!' AND NOT " . self::HIDDEN_ADDRESS_SQL . '))',
            null,
            false
        );
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
        if ($listing === null) {
            return null;
        }
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
        $listing['locations'] = listing_is_verified_business($listing)
            ? array_map(
                static function (array $loc): array {
                    $loc['trading_hours'] = hours_decode($loc['trading_hours'] ?? null);

                    return $loc;
                },
                $this->locations->forListing((int) $listing['id'])
            )
            : [];
        $listing['tags']           = $this->tags->namesForListing((int) $listing['id']);
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

        // Free for every listing — see ServiceMenuService.
        $menu                   = new ServiceMenuService();
        $listing['services']    = $menu->servicesFor((int) $listing['id']);
        $listing['attributes']  = $menu->attributeLabelsFor((int) $listing['id']);
        $listing['trading_hours']  = hours_decode($listing['trading_hours'] ?? null);

        // Also free, and resolved against the listing's *current* category, so
        // a school later re-filed as a tutor stops showing the grades it used
        // to offer rather than showing them under the wrong labels.
        $listing['facets'] = (new ListingFacetService())->displayFor(
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
        $listing['team'] = listing_is_verified_business($listing)
            ? $this->team->forListing((int) $listing['id'])
            : [];

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
            ->where('xs_directory_listings.status', 'published')
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
            ->where('xs_directory_listings.status', 'published')
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
            ->where('status', 'published')
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
            ->where('status', 'published')
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
            ->where('status', 'published')
            ->where('province', $province)
            ->where('city !=', '')
            ->groupBy('city')
            ->orderBy('c', 'DESC')
            ->limit($limit)
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            if (($r['city'] ?? '') !== '') {
                $out[(string) $r['city']] = (int) $r['c'];
            }
        }
        return $out;
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
            ->where('status', 'published')
            ->where('province', $province)
            ->where('category_id IS NOT NULL')
            ->groupBy('category_id')
            ->orderBy('c', 'DESC')
            ->limit($limit)
            ->findAll();

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
     * @return array{listings:int,categories:int,provinces:int}
     */
    public function stats(): array
    {
        $counts = $this->categoryListingCounts();

        return [
            'listings'   => (int) $this->listings->where('status', 'published')->countAllResults(),
            'categories' => count($counts),
            'provinces'  => count($this->provinceCounts()),
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
        helper('slug');
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
            ->where('status', 'published')
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
        return $out;
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
            ->where('xs_directory_listings.status', 'published')
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
        helper('slug');
        $today = date('Y-m-d');
        $out   = [];

        $rows = $this->listings
            ->select('category_id, province, COUNT(*) AS c, MAX(updated_at) AS m')
            ->where('status', 'published')
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
        $row = $this->listings->select('display_name, slug')->where('status', 'published')->find($id);

        return $row ?: null;
    }

    /** Published listings in a venue. */
    public function venueListingCount(int $venueId): int
    {
        return $this->listings
            ->where('status', 'published')
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
            ->where('xs_directory_listings.status', 'published')
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
        helper('slug');
        $today = date('Y-m-d');

        $rows = $this->listings
            ->select('province, COUNT(*) AS c, MAX(updated_at) AS m')
            ->where('status', 'published')
            ->where('province !=', '')
            ->groupBy('province')
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            $province = (string) ($r['province'] ?? '');
            if ($province === '' || (int) $r['c'] < $minListings) {
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
     * Sitemap URLs for published listings.
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    public function sitemapUrls(): array
    {
        $rows = $this->listings
            ->select('slug, updated_at')
            ->where('status', 'published')
            // listing_is_indexable() in SQL: a page that says noindex has no
            // business in the sitemap. Grouped so the OR cannot escape the
            // status filter, and unscored rows pass for the reason recent() gives.
            ->groupStart()
                ->where('quality_scored_at', null)
                ->orWhere('quality_score >=', (int) config('Directory')->indexMinQuality)
            ->groupEnd()
            ->orderBy('updated_at', 'DESC')
            ->findAll();

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
