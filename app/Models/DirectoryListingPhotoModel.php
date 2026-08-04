<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryListingPhotoModel extends Model
{
    protected $table         = 'xs_directory_listing_photos';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'path', 'original_name', 'width', 'height', 'sort_order',
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

    /**
     * Append newly-processed photos to a listing's gallery. Additive, not a
     * replace-all like DirectoryTagModel::syncListingTags() — the photo files
     * are already on disk once uploaded, so a "replace" semantic would orphan
     * them for no benefit.
     *
     * @param array<int,array{path:string,width:?int,height:?int,original_name?:?string}> $photos
     */
    public function appendPhotos(int $listingId, array $photos): void
    {
        if ($photos === []) {
            return;
        }

        $sortOrder = $this->countForListing($listingId);
        $rows      = [];
        foreach ($photos as $photo) {
            $rows[] = [
                'listing_id'    => $listingId,
                'path'          => $photo['path'],
                'original_name' => $photo['original_name'] ?? null,
                'width'         => $photo['width'] ?? null,
                'height'        => $photo['height'] ?? null,
                'sort_order'    => $sortOrder++,
            ];
        }
        $this->insertBatch($rows);
    }

    /**
     * Delete a photo row and the file behind it. Paths are FCPATH-relative and
     * server-generated, but the realpath check is cheap insurance against a
     * row whose path was ever tampered with reaching unlink() outside public/.
     *
     * @param array<string,mixed> $photo a row from this table
     */
    public function deleteWithFile(array $photo): void
    {
        $root = rtrim(realpath(FCPATH) ?: FCPATH, '/');
        $full = realpath($root . '/' . ltrim((string) ($photo['path'] ?? ''), '/'));

        if ($full !== false && str_starts_with($full, $root . '/') && is_file($full)) {
            @unlink($full);
        }

        $this->delete((int) $photo['id']);
    }
}
