<?php

namespace App\Controllers\Concerns;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryListingPhotoModel;

/**
 * Logo and gallery upload handling for the three controllers that own a
 * listing form: Listing (public signup), Manage (owner self-service) and
 * Admin. Each used to carry its own byte-identical copy of these two methods
 * plus its own GALLERY_MAX — the same drift risk the shared _form_fields.php
 * partial exists to avoid on the view side.
 *
 * Every failure is returned as a sentence for the caller to flash. Nothing is
 * dropped in silence: that was the bug where an oversized phone photo vanished
 * while the form still reported "saved".
 *
 * @property \CodeIgniter\HTTP\IncomingRequest $request
 */
trait HandlesListingUploads
{
    /** Public so the edit views can be handed the cap rather than repeating it. */
    public const GALLERY_MAX = 8;

    /** How many more photos this listing can take. */
    protected function gallerySlots(?int $listingId): int
    {
        if ($listingId === null) {
            return self::GALLERY_MAX;
        }

        return max(0, self::GALLERY_MAX - (new DirectoryListingPhotoModel())->countForListing($listingId));
    }

    /**
     * Optional logo replacement. An empty path means "leave the existing logo
     * alone" — both mutation services treat it that way — so a failed upload
     * returns '' plus the reason rather than wiping what is already stored.
     *
     * @return array{path:string,error:string}
     */
    protected function resolveLogo(): array
    {
        $file = $this->request->getFile('logo');

        // No file chosen at all is the normal case, not an error.
        if (! $file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return ['path' => '', 'error' => ''];
        }

        $result = (new ListingImageProcessor())->process(
            $file,
            rtrim(FCPATH, '/') . '/assets/listings',
            'listing'
        );

        return $result['ok']
            ? ['path' => $result['path'], 'error' => '']
            : ['path' => '', 'error' => $result['error']];
    }

    /**
     * @param int|null $listingId null on public signup, where no photos exist yet
     *
     * @return array{photos:array<int,array{path:string,width:?int,height:?int,original_name:?string}>,errors:array<int,string>}
     */
    protected function resolveGalleryPhotos(?int $listingId = null): array
    {
        $files = array_filter(
            $this->request->getFileMultiple('gallery') ?? [],
            static fn ($f) => $f && $f->getError() !== UPLOAD_ERR_NO_FILE
        );

        if ($files === []) {
            return ['photos' => [], 'errors' => []];
        }

        // The cap is on the gallery as a whole, not per submit — appendPhotos()
        // is additive, so counting only this batch would let repeated saves
        // grow the gallery without limit.
        $existing = $listingId !== null
            ? (new DirectoryListingPhotoModel())->countForListing($listingId)
            : 0;
        $slots = max(0, self::GALLERY_MAX - $existing);

        $photos    = [];
        $errors    = [];
        $processor = new ListingImageProcessor();

        foreach ($files as $file) {
            if (count($photos) >= $slots) {
                $errors[] = sprintf(
                    '“%s” was not added — a profile can have at most %d photos%s.',
                    $file->getClientName(),
                    self::GALLERY_MAX,
                    $existing > 0 ? sprintf(' and this one already has %d', $existing) : ''
                );
                continue;
            }

            $result = $processor->process(
                $file,
                rtrim(FCPATH, '/') . '/assets/listings/gallery',
                'gallery'
            );

            if ($result['ok']) {
                $photos[] = [
                    'path'          => $result['path'],
                    'width'         => $result['width'],
                    'height'        => $result['height'],
                    'original_name' => $file->getClientName(),
                ];
            } else {
                $errors[] = $result['error'];
            }
        }

        return ['photos' => $photos, 'errors' => $errors];
    }

    /**
     * Attach collected upload problems to a redirect without hiding the fact
     * that the rest of the save succeeded.
     *
     * @param array<int,string> $errors
     */
    protected function withUploadErrors(\CodeIgniter\HTTP\RedirectResponse $redirect, array $errors): \CodeIgniter\HTTP\RedirectResponse
    {
        return $errors === [] ? $redirect : $redirect->with('upload_errors', $errors);
    }
}
