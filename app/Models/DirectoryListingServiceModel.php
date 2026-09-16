<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * "Services & prices" rows for a listing. Written only through
 * App\Services\ServiceMenuService, which validates and replaces the whole set.
 */
class DirectoryListingServiceModel extends Model
{
    protected $table         = 'xs_directory_listing_services';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'name', 'price_label', 'sort_order'];

    /** @return array<int,array<string,mixed>> */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}
