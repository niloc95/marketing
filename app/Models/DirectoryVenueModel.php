<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * A complex, mall or building that several listings share.
 *
 * Deliberately not the tag table: see the migration's docblock, and
 * DirectoryListingModel::OWNER_EDITABLE for why the association is admin-only.
 */
class DirectoryVenueModel extends Model
{
    protected $table         = 'xs_directory_venues';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'slug', 'address_line', 'suburb', 'city', 'province', 'postal_code',
        'latitude', 'longitude', 'description', 'is_active',
    ];

    protected $validationRules = [
        // Same {id} placeholder rule as DirectoryCategoryModel: without a rule
        // for `id`, an update that resubmits the unchanged slug fails is_unique
        // against its own row.
        'id'   => 'permit_empty|is_natural_no_zero',
        'name' => 'required|min_length[2]|max_length[160]',
        'slug' => 'required|alpha_dash|max_length[180]|is_unique[xs_directory_venues.slug,id,{id}]',
    ];

    /** @return array<int,array<string,mixed>> */
    public function active(): array
    {
        return $this->where('is_active', 1)->orderBy('name', 'ASC')->findAll();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->where('is_active', 1)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Published listings per venue, for the admin table and the landing-page
     * threshold. Counts published only — the number is shown to visitors as
     * "N businesses here", and pending rows are not here yet.
     *
     * @return array<int,int> venue_id => count
     */
    public function listingCounts(): array
    {
        $rows = $this->db->table('xs_directory_listings')
            ->select('venue_id, COUNT(*) AS c')
            ->where('status', 'published')
            ->where('deleted_at', null)
            ->where('venue_id IS NOT NULL')
            ->groupBy('venue_id')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['venue_id']] = (int) $r['c'];
        }

        return $out;
    }
}
