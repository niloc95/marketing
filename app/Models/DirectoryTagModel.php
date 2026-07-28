<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryTagModel extends Model
{
    protected $table         = 'xs_directory_tags';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'slug'];

    /**
     * Find-or-create a tag by name, returning its id.
     */
    public function resolveId(string $name): int
    {
        helper('slug');
        $slug = slugify($name);
        if ($slug === '') {
            return 0;
        }
        $existing = $this->where('slug', $slug)->first();
        if (is_array($existing)) {
            return (int) $existing['id'];
        }
        return (int) $this->insert(['name' => trim($name), 'slug' => $slug], true);
    }

    /**
     * Tag names for a listing.
     *
     * @return array<int,string>
     */
    public function namesForListing(int $listingId): array
    {
        return $this->select('xs_directory_tags.name')
            ->join('xs_directory_listing_tags lt', 'lt.tag_id = xs_directory_tags.id')
            ->where('lt.listing_id', $listingId)
            ->orderBy('xs_directory_tags.name', 'ASC')
            ->findColumn('name') ?? [];
    }

    /**
     * Replace a listing's tag set (by names). Writes the pivot.
     *
     * @param array<int,string> $names
     */
    public function syncListingTags(int $listingId, array $names): void
    {
        $db    = $this->db;
        $pivot = $db->table('xs_directory_listing_tags');
        $pivot->where('listing_id', $listingId)->delete();

        $rows = [];
        foreach (array_unique(array_filter(array_map('trim', $names))) as $name) {
            $tagId = $this->resolveId($name);
            if ($tagId > 0) {
                $rows[] = ['listing_id' => $listingId, 'tag_id' => $tagId];
            }
        }
        if ($rows !== []) {
            $pivot->insertBatch($rows);
        }
    }
}
