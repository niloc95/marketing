<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Extra description cards under "About our practice". Written only by
 * ListingSectionService.
 */
class DirectoryListingSectionModel extends Model
{
    protected $table         = 'xs_directory_listing_sections';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'heading', 'body', 'body_text', 'sort_order'];

    /** @return list<array<string,mixed>> */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * One card, only if it belongs to this profile: the id rides in on a form
     * the owner controls (see DirectoryPracticeLocationModel::findForListing()).
     *
     * @return array<string,mixed>|null
     */
    public function findForListing(int $id, int $listingId): ?array
    {
        $row = $this->where('id', $id)->where('listing_id', $listingId)->first();

        return is_array($row) ? $row : null;
    }
}
