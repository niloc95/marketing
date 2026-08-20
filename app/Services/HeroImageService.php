<?php

namespace App\Services;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryHeroImageModel;
use App\Models\DirectoryListingPhotoModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * The home page hero rotation: reads for the public page, writes for the admin
 * screen.
 *
 * Shaped like DirectorySettings — one cached read of a whole small table, every
 * write drops the entry, and every read failure degrades to an empty array
 * rather than propagating. That posture is the point. The hero is decoration
 * layered over a search form that works without it, so a database or cache
 * problem must cost the photographs and nothing else; with no slides the
 * section falls back to the gradient it had before this feature existed.
 */
class HeroImageService
{
    /** One entry for the whole rotation — a handful of rows, read on every home hit. */
    private const CACHE_KEY = 'directory_hero_slides';

    /**
     * An hour. Like the settings cache the number barely matters, because the
     * admin screen drops the entry on every write; it is a backstop, not a
     * freshness policy.
     */
    private const CACHE_TTL = 3600;

    /**
     * Where admin uploads land. Deliberately NOT public/assets/hero/, which
     * holds the committed seed photographs — those are tracked in git and ship
     * with the deploy bundle, these are per-environment content. Keeping them
     * apart is what lets .gitignore and the build script treat them differently.
     */
    private const UPLOAD_DIR = 'assets/hero/uploads';

    /** Long edge of the two renditions written for each upload. */
    private const SIZE_LG = 1600;
    private const SIZE_SM = 800;

    /**
     * More than this and the rotation stops being a rotation — every extra
     * photo is bytes a visitor may never see, and nobody watches eight.
     */
    public const MAX_SLIDES = 8;

    private DirectoryHeroImageModel $model;

    /** Read once per request even when the cache is unavailable. */
    private ?array $loaded = null;

    public function __construct()
    {
        $this->model = new DirectoryHeroImageModel();
    }

    // ------------------------------------------------------------------ reads

    /**
     * The active rotation for the home page, cached.
     *
     * @return array<int,array<string,mixed>>
     */
    public function slides(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $cached = cache()->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->loaded = $cached;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Hero cache unavailable, reading through: ' . $e->getMessage());
        }

        try {
            $rows = $this->model->activeOrdered();
        } catch (\Throwable $e) {
            log_message('error', 'Could not read hero images, falling back to the gradient: ' . $e->getMessage());

            return $this->loaded = [];
        }

        try {
            cache()->save(self::CACHE_KEY, $rows, self::CACHE_TTL);
        } catch (\Throwable $e) {
            log_message('warning', 'Could not cache hero images: ' . $e->getMessage());
        }

        return $this->loaded = $rows;
    }

    /**
     * Every row, active or not, for the admin screen. Uncached — the operator
     * who just saved must see what they saved.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->model->allOrdered();
    }

    public function forget(): void
    {
        $this->loaded = null;

        try {
            cache()->delete(self::CACHE_KEY);
        } catch (\Throwable $e) {
            log_message('warning', 'Could not clear the hero cache: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------- writes

    /**
     * Add or update one hero photo.
     *
     * @param array<string,mixed> $input
     *
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    public function save(?int $id, array $input, ?UploadedFile $file): array
    {
        $existing = $id === null ? null : $this->model->find($id);
        if ($id !== null && $existing === null) {
            return $this->fail([], 'That hero photo no longer exists.');
        }

        if ($id === null && $this->model->countAllResults() >= self::MAX_SLIDES) {
            return $this->fail([], sprintf(
                'The rotation is full at %d photos. Delete one before adding another.',
                self::MAX_SLIDES
            ));
        }

        $errors     = [];
        $categoryId = $this->resolveCategoryId($input['category_id'] ?? null, $errors);
        $creditUrl  = trim((string) ($input['credit_url'] ?? ''));
        if ($creditUrl !== '' && ! preg_match('#^https://#i', $creditUrl)) {
            $errors['credit_url'] = 'The credit link must start with https://.';
        }

        // Uploads are processed before the validation verdict so a rejected save
        // does not also silently throw away the file. Anything written here is
        // discarded again below if the row does not go in.
        $uploaded = ['path' => '', 'path_sm' => '', 'width' => null, 'height' => null, 'error' => ''];
        if ($file !== null && $file->isValid()) {
            $uploaded = $this->processUpload($file);
            if ($uploaded['error'] !== '') {
                $errors['photo'] = $uploaded['error'];
            }
        } elseif ($id === null) {
            $errors['photo'] = 'Choose a photo to add.';
        }

        if ($errors !== []) {
            $this->discard($uploaded['path'], $uploaded['path_sm']);

            return $this->fail($errors, 'That photo could not be saved — see the notes below.');
        }

        $data = [
            'caption'     => trim((string) ($input['caption'] ?? '')) ?: null,
            'category_id' => $categoryId,
            'credit'      => trim((string) ($input['credit'] ?? '')) ?: null,
            'credit_url'  => $creditUrl ?: null,
            'sort_order'  => (int) ($input['sort_order'] ?? 0),
            'is_active'   => empty($input['is_active']) ? 0 : 1,
        ];

        if ($uploaded['path'] !== '') {
            $data['path']    = $uploaded['path'];
            $data['path_sm'] = $uploaded['path_sm'] ?: null;
            $data['width']   = $uploaded['width'];
            $data['height']  = $uploaded['height'];
        }

        try {
            if ($id === null) {
                $this->model->insert($data);
            } else {
                $this->model->update($id, $data);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Could not save a hero image: ' . $e->getMessage());
            $this->discard($uploaded['path'], $uploaded['path_sm']);

            return $this->fail([], 'That photo could not be saved. Please try again.');
        }

        // Only once the row is safely stored: the replaced files are now
        // unreferenced. Same order the listing services use — orphan a file
        // rather than risk a row pointing at one that is gone.
        if ($uploaded['path'] !== '' && $existing !== null) {
            $this->discard((string) ($existing['path'] ?? ''), (string) ($existing['path_sm'] ?? ''));
        }

        $this->forget();

        return [
            'ok'      => true,
            'errors'  => [],
            'message' => $id === null ? 'Hero photo added.' : 'Hero photo updated.',
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function delete(int $id): array
    {
        $row = $this->model->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'That hero photo no longer exists.'];
        }

        try {
            $this->model->deleteWithFiles($row);
        } catch (\Throwable $e) {
            log_message('error', 'Could not delete a hero image: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'That photo could not be deleted. Please try again.'];
        }

        $this->forget();

        return ['ok' => true, 'message' => 'Hero photo deleted.'];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Write the two renditions for one upload.
     *
     * ListingImageProcessor is called twice on the same UploadedFile, which
     * works because its resize path reads the temp file with file_get_contents
     * and never moves it. The one branch that does move is the gif passthrough,
     * so gif is refused above rather than half-processed here — an animated
     * background is not something this hero wants anyway.
     *
     * A failed second pass is not a failed upload. The large rendition is what
     * the page needs; losing the small one costs a phone some bytes, and that
     * is not worth rejecting the operator's photo over.
     *
     * @return array{path:string,path_sm:string,width:?int,height:?int,error:string}
     */
    private function processUpload(UploadedFile $file): array
    {
        $none = ['path' => '', 'path_sm' => '', 'width' => null, 'height' => null];

        if (strtolower($file->getClientExtension()) === 'gif') {
            return $none + ['error' => 'GIFs are not supported here — save the frame you want as a JPEG or PNG.'];
        }

        $dest      = rtrim(FCPATH, '/') . '/' . self::UPLOAD_DIR;
        $processor = new ListingImageProcessor();

        $large = $processor->process($file, $dest, 'hero', self::SIZE_LG);
        if (! $large['ok']) {
            return $none + ['error' => $large['error']];
        }

        $small = $processor->process($file, $dest, 'hero-sm', self::SIZE_SM);
        if (! $small['ok']) {
            log_message('warning', 'Hero small rendition failed, serving one size: ' . $small['error']);
        }

        return [
            'path'    => $large['path'],
            'path_sm' => $small['ok'] ? $small['path'] : '',
            'width'   => $large['width'],
            'height'  => $large['height'],
            'error'   => '',
        ];
    }

    /**
     * A category id that really exists, or null.
     *
     * @param array<string,string> $errors
     */
    private function resolveCategoryId(mixed $raw, array &$errors): ?int
    {
        $id = (int) $raw;
        if ($id <= 0) {
            return null;
        }

        $exists = (new DirectoryCategoryModel())->find($id);
        if ($exists === null) {
            $errors['category_id'] = 'That category no longer exists.';

            return null;
        }

        return $id;
    }

    /** Remove files that ended up belonging to nothing. */
    private function discard(string ...$paths): void
    {
        $files = new DirectoryListingPhotoModel();
        foreach ($paths as $path) {
            if ($path !== '') {
                $files->deleteFileAt($path);
            }
        }
    }

    /**
     * @param array<string,string> $errors
     *
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    private function fail(array $errors, string $message): array
    {
        return ['ok' => false, 'errors' => $errors, 'message' => $message];
    }
}
