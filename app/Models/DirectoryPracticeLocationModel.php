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

    /**
     * One location, but only if it really belongs to this listing.
     *
     * The same ownership primitive as DirectoryListingTeamModel::findForListing()
     * and it exists for the same reason: the row ids ride in on a form body the
     * owner controls, and an owner session grants authority over exactly one
     * listing. Returning null on a mismatch collapses "not there" and "not
     * yours" into one answer, which is also all either caller should be told.
     *
     * @return array<string,mixed>|null
     */
    public function findForListing(int $id, int $listingId): ?array
    {
        $row = $this->where('id', $id)
            ->where('listing_id', $listingId)
            ->first();

        return is_array($row) ? $row : null;
    }
}
