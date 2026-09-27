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
     * The only FCPATH-relative directories deleteFileAt() will unlink from:
     * every listing upload (logo, gallery, team, menu images) and the admin's
     * hero uploads. The committed hero photos in assets/hero/ are deliberately
     * outside it — they are part of the release, not content.
     */
    public const UPLOAD_ROOTS = ['assets/listings', 'assets/hero/uploads'];

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
        $this->deleteFileAt((string) ($photo['path'] ?? ''));
        $this->delete((int) $photo['id']);
    }

    /**
     * Delete one stored image file, if it is really inside public/.
     *
     * Split out of deleteWithFile() because logos need exactly this and have no
     * row of their own — logo_path is a column on the listing. Both callers get
     * the same containment check rather than a second, subtly different copy.
     *
     * Silently does nothing for an empty path, a missing file, or anything that
     * resolves outside UPLOAD_ROOTS: this runs during cleanup, where refusing
     * to delete is always the safe failure.
     *
     * Inside FCPATH is not enough. public/ also holds index.php, .htaccess and
     * committed assets, and a path that ever reaches here from a request
     * (see HandlesListingUploads::resolveTeamPhotos) must not be able to name
     * them. A refusal is logged, because nothing legitimate produces one.
     */
    public function deleteFileAt(string $path): void
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return;
        }

        // Imported listings can carry an absolute URL here — not our file, and
        // realpath would resolve it to nothing anyway. Bail explicitly.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {
            return;
        }

        $root = rtrim(realpath(FCPATH) ?: FCPATH, '/');
        $full = realpath($root . '/' . $path);

        if ($full === false || ! is_file($full)) {
            return;
        }

        foreach (self::UPLOAD_ROOTS as $dir) {
            if (str_starts_with($full, $root . '/' . $dir . '/')) {
                @unlink($full);

                return;
            }
        }

        log_message('warning', 'Refused to delete a file outside the upload directories: {path}', ['path' => $path]);
    }
}
