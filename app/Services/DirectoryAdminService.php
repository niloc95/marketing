<?php

namespace App\Services;

use App\Models\DirectoryListingModel;

/**
 * Admin oversight operations over listings.
 */
class DirectoryAdminService
{
    private DirectoryListingModel $listings;

    public function __construct()
    {
        $this->listings = new DirectoryListingModel();
    }

    /**
     * Paginated list of ALL listings (any status), newest first, optional status filter.
     *
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int,counts:array<string,int>}
     */
    public function list(string $status = '', int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $builder = $this->listings
            ->select('xs_directory_listings.*, p.name AS profession_name')
            ->join('xs_directory_professions p', 'p.id = xs_directory_listings.profession_id', 'left');

        if ($status !== '') {
            $builder->where('xs_directory_listings.status', $status);
        }

        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('xs_directory_listings.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->findAll();

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
            'counts'     => $this->counts(),
        ];
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $rows = $this->listings->select('status, COUNT(*) AS c')->groupBy('status')->findAll();
        $out  = ['all' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
            $out['all'] += (int) $r['c'];
        }
        return $out;
    }

    public function setFeatured(int $id, bool $featured): bool
    {
        return $this->listings->update($id, ['is_featured' => $featured ? 1 : 0]);
    }

    public function publish(int $id): bool
    {
        return $this->listings->update($id, [
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unpublish(int $id): bool
    {
        return $this->listings->update($id, ['status' => 'unpublished']);
    }

    public function remove(int $id): bool
    {
        return $this->listings->delete($id); // soft delete
    }
}
