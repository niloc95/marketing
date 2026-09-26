<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Rows of a listing's uploaded menu. See the migration for why this is not the
 * photos table, and App\Services\ListingMenuService for the rules.
 */
class DirectoryListingMenuFileModel extends Model
{
    public const KIND_PDF   = 'pdf';
    public const KIND_IMAGE = 'image';

    protected $table         = 'xs_directory_listing_menu_files';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'kind', 'path', 'original_name', 'bytes', 'width', 'height', 'sort_order',
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /** The listing's PDF menu row, or null when its menu is photos or absent. */
    public function pdfFor(int $listingId): ?array
    {
        $row = $this->where('listing_id', $listingId)
            ->where('kind', self::KIND_PDF)
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $row : null;
    }
}
