<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryPracticeLocationModel extends Model
{
    protected $table         = 'xs_directory_practice_locations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'name', 'address_line', 'suburb', 'city', 'province',
        'phone', 'is_primary', 'sort_order',
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('is_primary', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}
