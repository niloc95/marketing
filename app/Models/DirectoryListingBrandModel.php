<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Rows behind the "Brands we carry" strip. Written only by ListingBrandService.
 */
class DirectoryListingBrandModel extends Model
{
    protected $table         = 'xs_directory_listing_brands';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'name', 'logo_path', 'width', 'height', 'sort_order',
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    public function countForListing(int $listingId): int
    {
        return $this->where('listing_id', $listingId)->countAllResults();
    }
}
