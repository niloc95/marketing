<?php

namespace App\Services;

use App\Libraries\Geocoding\NominatimGeocoder;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingFacetModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryListingTeamModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryTagModel;
use App\Models\DirectoryVenueModel;
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
        $this->locations   = new DirectoryPracticeLocationModel();
        $this->tags        = new DirectoryTagModel();
        $this->photos       = new DirectoryListingPhotoModel();
        $this->team         = new DirectoryListingTeamModel();
        $this->venues       = new DirectoryVenueModel();
    }

    /** Radius options offered on the search page, in kilometres. */
    public const RADIUS_OPTIONS = [1, 5, 10, 25, 50];

    /**
     * Paginated browse/search over published listings.
     *
     * @param array{q?:string,category?:string,province?:string,city?:string,venue?:mixed,lat?:mixed,lng?:mixed,radius?:mixed,bounds?:string,facets?:array,sort?:string} $filters
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
        if ($q !== '') {
            // Everything applySearch adds is one OR group. Any filter appended
            // after it must be a plain where() — an orWhere here would escape
            // that group and quietly widen the search instead of narrowing it.
            $this->applySearch($builder, $q);
        }
        if (! empty($filters['category'])) {
            $builder->where('p.slug', $filters['category']);
        }
        if (! empty($filters['province'])) {
            $builder->where('xs_directory_listings.province', $filters['province']);
        }
        if (! empty($filters['city'])) {
            $builder->like('xs_directory_listings.city', trim($filters['city']));
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
     * Typeahead suggestions for the search boxes: businesses, categories,
     * venues and places matching the first few characters someone has typed.
     *
     * Deliberately NOT browse(). That method answers "what matches this
     * search" — FULLTEXT in boolean mode, four unanchored LIKEs and a
     * correlated EXISTS over two tables — and running it on every keystroke is
     * the one thing a suggestion list must never do. These are four small
     * indexed lookups with their own small limits.
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

        $db      = db_connect();
        $escaped = $db->escape($q);
        $out     = [];

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
        if (! empty($filters['province'])) {
            $builder->where('xs_directory_listings.province', $filters['province']);
        }
        if (! empty($filters['city'])) {
            $builder->like('xs_directory_listings.city', trim($filters['city']));
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
     * is. This deliberately spans four sources:
     *
     *   - FULLTEXT over display_name / description_text / credentials, in
     *     BOOLEAN mode with a trailing * so "hairdress" reaches "hairdresser".
     *     description_text, not description: the latter holds the rich-text
     *     HTML, and indexing that would make "strong", "blockquote" and
     *     "center" match every listing whose owner used the toolbar
     *   - the joined category name
     *   - the listing's town and suburb
     *   - its tags, via EXISTS on the pivot
     *   - its team members' names and areas of focus, via EXISTS on the team
     *     table, and only while the badge that publishes them is live
     *
     * @param \CodeIgniter\Database\BaseBuilder|\CodeIgniter\Model $builder
     */
    private function applySearch($builder, string $q): void
    {
        $db = $this->listings->db;

        // Strip boolean operators before handing the term to MySQL: a stray "("
        // or "+" is a syntax error in BOOLEAN mode, which would throw rather
        // than simply returning nothing.
        $terms = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $q) ?? '') ?: [];
        $terms = array_values(array_filter($terms, static fn ($t) => $t !== ''));

        $builder->groupStart();

        if ($terms !== []) {
            // Prefix-match every term; MySQL ignores tokens below
            // innodb_ft_min_token_size (3 by default), which is fine here.
            $expr = implode(' ', array_map(static fn ($t) => $t . '*', $terms));
            $builder->where(
                'MATCH(xs_directory_listings.display_name, xs_directory_listings.description_text, xs_directory_listings.credentials) '
                . 'AGAINST (' . $db->escape($expr) . ' IN BOOLEAN MODE)',
                null,
                false
            );
        }

        $builder
            ->orLike('xs_directory_listings.display_name', $q)
            ->orLike('xs_directory_listings.city', $q)
            ->orLike('xs_directory_listings.suburb', $q)
            ->orLike('p.name', $q);

        // Tags live in a pivot, so they cannot be part of the FULLTEXT index.
        $tagSql = 'EXISTS (SELECT 1 FROM xs_directory_listing_tags lt'
            . ' JOIN xs_directory_tags t ON t.id = lt.tag_id'
            . ' WHERE lt.listing_id = xs_directory_listings.id'
            . ' AND t.name LIKE ' . $db->escape('%' . $db->escapeLikeString($q) . '%') . ')';
        $builder->orWhere($tagSql, null, false);

        // Team members, for the same reason and in the same shape: a firm is
        // very often searched for by the name of the person you were referred
        // to, or by an area of law only one of its attorneys practises, and
        // neither string is anywhere on the listing row.
        //
        // The verified_until clause is not optional. Team members only render
        // for a listing with a live badge, so without it a search would return
        // a business on the strength of a panel the visitor cannot see.
        $like    = $db->escape('%' . $db->escapeLikeString($q) . '%');
        $teamSql = 'EXISTS (SELECT 1 FROM xs_directory_listing_team tm'
            . ' WHERE tm.listing_id = xs_directory_listings.id'
            . ' AND xs_directory_listings.verified_until >= CURDATE()'
            . ' AND (tm.name LIKE ' . $like . ' OR tm.specializations LIKE ' . $like . '))';
        $builder->orWhere($teamSql, null, false);

        $builder->groupEnd();
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
        $listing['locations'] = array_map(
            static function (array $loc): array {
                $loc['trading_hours'] = hours_decode($loc['trading_hours'] ?? null);

                return $loc;
            },
            $this->locations->forListing((int) $listing['id'])
        );
        $listing['tags']           = $this->tags->namesForListing((int) $listing['id']);
        // The complex this business sits in, and how many others are in it, so
        // the profile can offer "what else is in this building?".
        $listing['venue'] = ($listing['venue_id'] ?? null)
            ? $this->venues->find((int) $listing['venue_id'])
            : null;
        if (is_array($listing['venue'])) {
            $listing['venue']['listing_count'] = $this->venueListingCount((int) $listing['venue_id']);
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
        return $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug, p.group_name AS category_group'
                . ', v.name AS venue_name, v.slug AS venue_slug')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->join('xs_directory_venues v', 'v.id = xs_directory_listings.venue_id', 'left')
            ->where('xs_directory_listings.status', 'published')
            ->where('xs_directory_listings.is_featured', 1)
            ->orderBy('xs_directory_listings.published_at', 'DESC')
            ->limit($limit)
            ->findAll();
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
     * published listing count. categories() already orders by group_name,
     * so insertion order gives alphabetical groups for free.
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
