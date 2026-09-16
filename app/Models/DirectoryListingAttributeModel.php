<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Feature keys a listing has ticked. Keys are defined in Config\ListingAttributes;
 * written only through App\Services\ServiceMenuService, which filters them
 * against that config first.
 */
class DirectoryListingAttributeModel extends Model
{
    protected $table         = 'xs_directory_listing_attributes';
    protected $primaryKey    = 'listing_id';
    protected $returnType    = 'array';
    protected $allowedFields = ['listing_id', 'attribute_key'];

    /** @return array<int,string> */
    public function keysForListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('attribute_key', 'ASC')
            ->findColumn('attribute_key') ?? [];
    }

    /**
     * Replace a listing's feature set. Keys must already be filtered.
     *
     * @param array<int,string> $keys
     */
    public function sync(int $listingId, array $keys): void
    {
        $table = $this->db->table($this->table);
        $table->where('listing_id', $listingId)->delete();

        $rows = [];
        foreach (array_unique($keys) as $key) {
            $rows[] = ['listing_id' => $listingId, 'attribute_key' => $key];
        }
        if ($rows !== []) {
            $table->insertBatch($rows);
        }
    }
}
