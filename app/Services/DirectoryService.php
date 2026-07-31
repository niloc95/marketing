<?php

namespace App\Services;

use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryTagModel;

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

    public function __construct()
    {
        helper('directory_hours');
        $this->listings    = new DirectoryListingModel();
        $this->categories = new DirectoryCategoryModel();
        $this->locations   = new DirectoryPracticeLocationModel();
        $this->tags        = new DirectoryTagModel();
        $this->photos       = new DirectoryListingPhotoModel();
    }

    /**
     * Paginated browse/search over published listings.
     *
     * @param array{q?:string,category?:string,province?:string,city?:string} $filters
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}
     */
    public function browse(array $filters, int $page = 1, int $perPage = 12): array
    {
        $page    = max(1, $page);
        $perPage = min(48, max(1, $perPage));

        $builder = $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug')
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

        $total = $builder->countAllResults(false);

        $items = $builder
            ->orderBy('xs_directory_listings.is_featured', 'DESC')
            ->orderBy('xs_directory_listings.published_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->findAll();

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Free-text search across everything a visitor would reasonably type.
     *
     * People search by service ("hair salon") far more than by business name, so
     * matching only the listing's own text misses the most common query there
     * is. This deliberately spans four sources:
     *
     *   - FULLTEXT over display_name / description / credentials, in BOOLEAN
     *     mode with a trailing * so "hairdress" reaches "hairdresser"
     *   - the joined category name
     *   - the listing's town and suburb
     *   - its tags, via EXISTS on the pivot
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
                'MATCH(xs_directory_listings.display_name, xs_directory_listings.description, xs_directory_listings.credentials) '
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
        $listing['category'] = $listing['category_id']
            ? $this->categories->find((int) $listing['category_id'])
            : null;
        $listing['locations']      = $this->locations->forListing((int) $listing['id']);
        $listing['tags']           = $this->tags->namesForListing((int) $listing['id']);
        $listing['photos']         = $this->photos->forListing((int) $listing['id']);
        $listing['trading_hours']  = hours_decode($listing['trading_hours'] ?? null);

        return $listing;
    }

    /**
     * Featured (or most recent) published listings for the homepage.
     *
     * @return array<int,array<string,mixed>>
     */
    public function featured(int $limit = 6): array
    {
        return $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->where('xs_directory_listings.status', 'published')
            ->orderBy('xs_directory_listings.is_featured', 'DESC')
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
            ->select('xs_directory_listings.*, p.name AS category_name, p.slug AS category_slug')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left')
            ->where('xs_directory_listings.status', 'published')
            ->where('xs_directory_listings.category_id', $categoryId)
            ->where('xs_directory_listings.id !=', $listingId);

        if ($province !== '') {
            $builder->where('xs_directory_listings.province', $province);
        }

        return $builder->orderBy('xs_directory_listings.is_featured', 'DESC')
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
