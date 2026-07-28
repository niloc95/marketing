<?php

namespace App\Services;

use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Models\DirectoryProfessionModel;
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
    private DirectoryProfessionModel $professions;
    private DirectoryPracticeLocationModel $locations;
    private DirectoryTagModel $tags;

    public function __construct()
    {
        $this->listings    = new DirectoryListingModel();
        $this->professions = new DirectoryProfessionModel();
        $this->locations   = new DirectoryPracticeLocationModel();
        $this->tags        = new DirectoryTagModel();
    }

    /**
     * Paginated browse/search over published listings.
     *
     * @param array{q?:string,profession?:string,province?:string,city?:string} $filters
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}
     */
    public function browse(array $filters, int $page = 1, int $perPage = 12): array
    {
        $page    = max(1, $page);
        $perPage = min(48, max(1, $perPage));

        $builder = $this->listings
            ->select('xs_directory_listings.*, p.name AS profession_name, p.slug AS profession_slug')
            ->join('xs_directory_professions p', 'p.id = xs_directory_listings.profession_id', 'left')
            ->where('xs_directory_listings.status', 'published');

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            // FULLTEXT in boolean mode, with a LIKE fallback for short terms.
            $safe = $this->listings->db->escapeLikeString($q);
            $builder->groupStart()
                ->where("MATCH(xs_directory_listings.display_name, xs_directory_listings.description, xs_directory_listings.qualifications) AGAINST (" . $this->listings->db->escape($q) . " IN NATURAL LANGUAGE MODE)", null, false)
                ->orLike('xs_directory_listings.display_name', $q)
                ->orLike('xs_directory_listings.city', $q)
                ->groupEnd();
            unset($safe);
        }
        if (! empty($filters['profession'])) {
            $builder->where('p.slug', $filters['profession']);
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
     * Full published profile: listing + profession + practice locations + tags.
     *
     * @return array<string,mixed>|null
     */
    public function getProfile(string $slug): ?array
    {
        $listing = $this->listings->findPublishedBySlug($slug);
        if ($listing === null) {
            return null;
        }
        $listing['profession'] = $listing['profession_id']
            ? $this->professions->find((int) $listing['profession_id'])
            : null;
        $listing['locations'] = $this->locations->forListing((int) $listing['id']);
        $listing['tags']      = $this->tags->namesForListing((int) $listing['id']);

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
            ->select('xs_directory_listings.*, p.name AS profession_name, p.slug AS profession_slug')
            ->join('xs_directory_professions p', 'p.id = xs_directory_listings.profession_id', 'left')
            ->where('xs_directory_listings.status', 'published')
            ->orderBy('xs_directory_listings.is_featured', 'DESC')
            ->orderBy('xs_directory_listings.published_at', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function professions(): array
    {
        return $this->professions->active();
    }

    /** @return array<int,string> */
    public function provinces(): array
    {
        return self::SA_PROVINCES;
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
