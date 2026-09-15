<?php

namespace App\Controllers\Concerns;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryListingPhotoModel;
use App\Services\TeamMemberService;

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

    /**
     * Throw away a logo that was processed but never got attached to a row.
     *
     * resolveLogo() has to run before the save, because the path is part of the
     * data being saved — so a submission that then fails validation has already
     * written a resized WebP into public/assets/listings/ that nothing will
     * ever reference, ever serve, or ever clean up. One determined visitor
     * fighting a validation error leaves a file per attempt.
     *
     * deleteFileAt() does the realpath containment check, so a path that
     * somehow points outside the docroot is ignored rather than unlinked.
     */
    protected function discardLogo(string $path): void
    {
        if ($path !== '') {
            (new DirectoryListingPhotoModel())->deleteFileAt($path);
        }
    }

    /**
     * Throw away headshots that were processed but never got attached to a row.
     *
     * The team equivalent of discardLogo(), and needed for the same reason: the
     * files are written before the save so their paths can be part of it, which
     * means a rejected save has already put images in public/ that nothing will
     * ever reference. One determined visitor fighting a validation error leaves
     * one file per person per attempt.
     *
     * @param mixed $team rows as returned by resolveTeamPhotos()
     */
    protected function discardTeamPhotos(mixed $team): void
    {
        $files = new DirectoryListingPhotoModel();

        foreach (is_array($team) ? $team : [] as $row) {
            if (is_array($row) && ! empty($row['photo_path'])) {
                $files->deleteFileAt((string) $row['photo_path']);
            }
        }
    }

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
     * Resolve the headshots posted alongside the team rows.
     *
     * The team lives inside the listing form, so its file inputs are named
     * team_photo[0], team_photo[1] … and arrive as one indexed array. Each
     * processed path is merged back into the matching team row as `photo_path`,
     * which is where TeamMemberService::syncFromForm() looks for it.
     *
     * An index with no file is not an error — it is the normal case for a row
     * whose photo is not being changed, and an empty photo_path means "keep
     * whatever is stored", exactly as resolveLogo() does for the listing.
     *
     * Runs before the save, like every other upload here, so a rejected save
     * leaves files behind on purpose; the caller bins them with the orphans the
     * sync hands back.
     *
     * @param mixed $team the raw `team` array off the request
     *
     * @return array{team:array<int|string,mixed>,errors:array<int,string>}
     */
    protected function resolveTeamPhotos(mixed $team): array
    {
        $team  = is_array($team) ? $team : [];
        $files = $this->request->getFileMultiple('team_photo') ?? [];

        $errors    = [];
        $processor = new ListingImageProcessor();

        foreach ($files as $index => $file) {
            if (! $file || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            // A file for a row that was not submitted has nowhere to go. Skip
            // it rather than processing an image nothing will ever reference.
            if (! isset($team[$index]) || ! is_array($team[$index])) {
                continue;
            }

            $result = $processor->process(
                $file,
                rtrim(FCPATH, '/') . '/' . TeamMemberService::UPLOAD_DIR,
                'team',
                TeamMemberService::PHOTO_SIZE
            );

            if ($result['ok']) {
                $team[$index]['photo_path'] = $result['path'];
            } else {
                $errors[] = $result['error'];
            }
        }

        return ['team' => $team, 'errors' => $errors];
    }

    /**
     * The answer to a photo delete sent by the edit page's script rather than
     * by a plain form post.
     *
     * The plain post redirects back to the edit form, and that reload threw
     * away every unsaved change on it — hours edited, then a photo deleted, and
     * the hours were gone. directory.js now deletes in place and needs this
     * instead.
     *
     * The fresh CSRF token is not optional. Config\Security regenerates the
     * token on every verified POST, AJAX included, so after one in-place delete
     * every csrf_field() still on the page is dead — and the owner's next Save
     * would fail. The script writes this hash back into all of them.
     */
    protected function photoDeleteJson(bool $ok, string $message, int $status, ?int $listingId = null): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'ok'        => $ok,
            'message'   => $message,
            'remaining' => $listingId === null ? null : self::GALLERY_MAX - $this->gallerySlots($listingId),
            'slots'     => $listingId === null ? null : $this->gallerySlots($listingId),
            'csrf'      => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
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
