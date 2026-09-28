<?php

namespace App\Services;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryHeroImageModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectorySettingModel;
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

    /**
     * Largest background video accepted. Production's PHP and Apache both cap
     * a request at 12 MB, so anything bigger never reaches this code; the
     * check here is what turns that into a readable message. A ten-to-twenty
     * second loop at 1080p compresses well under it.
     */
    public const MAX_VIDEO_BYTES = 12 * 1024 * 1024;

    /** Extension => the MIME types finfo reports for it. */
    private const VIDEO_TYPES = [
        'mp4'  => ['video/mp4', 'video/x-m4v'],
        'webm' => ['video/webm'],
    ];

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

    /**
     * Choose what plays behind the home hero: the photo rotation, an uploaded
     * video, or a YouTube video. Kept in the settings table — it is one choice
     * for the whole hero, not a slide — and the photos stay put either way:
     * the first one is the poster a video shows until it plays, and what a
     * visitor who prefers reduced motion sees instead.
     *
     * @param array<string,mixed> $input
     *
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    public function saveBackground(array $input, ?UploadedFile $file, string $by): array
    {
        $settings = new DirectorySettings();
        $current  = $settings->heroSettings();

        $mode = (string) ($input['hero_media'] ?? 'photos');
        if (! in_array($mode, ['photos', 'video', 'youtube'], true)) {
            $mode = 'photos';
        }

        $errors = [];

        $youtubeRaw = trim((string) ($input['youtube_url'] ?? ''));
        $youtubeId    = $current['youtube'];
        $youtubeStart = $current['start'];
        if ($youtubeRaw !== '') {
            $parsed = self::youtubeId($youtubeRaw);
            if ($parsed === null) {
                $errors['youtube_url'] = 'That does not look like a YouTube video link.';
            } else {
                $youtubeId    = $parsed;
                $youtubeStart = self::youtubeStart($youtubeRaw);
            }
        }

        $newVideo = '';
        if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->storeVideo($file);
            if ($stored['error'] !== '') {
                $errors['video'] = $stored['error'];
            } else {
                $newVideo = $stored['path'];
            }
        }
        $videoPath = $newVideo !== '' ? $newVideo : $current['video'];

        if ($mode === 'video' && $videoPath === '' && ! isset($errors['video'])) {
            $errors['video'] = 'Upload a video to use as the background.';
        }
        if ($mode === 'youtube' && $youtubeId === '' && ! isset($errors['youtube_url'])) {
            $errors['youtube_url'] = 'Paste the YouTube link to use as the background.';
        }

        if ($errors !== []) {
            $this->discard($newVideo);

            return $this->fail($errors, 'The hero background was not changed — see the notes below.');
        }

        $model = new DirectorySettingModel();
        try {
            $model->put(DirectorySettingModel::HERO_MEDIA, $mode, $by);
            $model->put(DirectorySettingModel::HERO_VIDEO_PATH, $videoPath, $by);
            $model->put(DirectorySettingModel::HERO_YOUTUBE_ID, $youtubeId, $by);
            $model->put(DirectorySettingModel::HERO_YOUTUBE_START, (string) $youtubeStart, $by);
        } catch (\Throwable $e) {
            log_message('error', 'Could not save the hero background: ' . $e->getMessage());
            $this->discard($newVideo);

            return $this->fail([], 'The hero background could not be saved. Please try again.');
        }

        // The replaced file is unreferenced only now the new path is stored.
        if ($newVideo !== '' && $current['video'] !== '' && $current['video'] !== $newVideo) {
            $this->discard($current['video']);
        }

        $settings->forget();

        $label = ['photos' => 'the photo rotation', 'video' => 'the uploaded video', 'youtube' => 'the YouTube video'][$mode];

        return ['ok' => true, 'errors' => [], 'message' => 'The hero now shows ' . $label . '.'];
    }

    /**
     * The 11-character video id from anything a person is likely to paste: a
     * watch, share (youtu.be), Shorts, live or embed link, YouTube's <iframe>
     * embed code, or the bare id.
     * Null for anything else, including links to other hosts.
     */
    public static function youtubeId(string $input): ?string
    {
        $input = trim($input);
        // The "Share > Embed" code YouTube offers: take the player URL out of
        // its src and read that like any other link.
        if (stripos($input, '<iframe') !== false) {
            if (preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $input, $m) !== 1) {
                return null;
            }
            $input = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input) === 1) {
            return $input;
        }

        $parts = parse_url(preg_match('#^[a-z]+://#i', $input) ? $input : 'https://' . $input);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        $host  = preg_replace('/^(www\.|m\.|music\.)/', '', $host);
        $path  = (string) ($parts['path'] ?? '');

        $candidate = null;
        if ($host === 'youtu.be') {
            $candidate = explode('/', trim($path, '/'))[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m) === 1) {
                $candidate = $m[1];
            } else {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $candidate = is_string($query['v'] ?? null) ? $query['v'] : null;
            }
        }

        return $candidate !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1 ? $candidate : null;
    }

    /**
     * The start time a YouTube link carries — ?t=15, ?t=1m20s, ?start=90, as
     * YouTube's own "Share > Start at" writes it — in seconds; 0 for none. Used
     * to skip a video's intro titles, which would otherwise sit under the
     * headline.
     */
    public static function youtubeStart(string $input): int
    {
        $input = trim($input);
        if (stripos($input, '<iframe') !== false
            && preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $input, $m) === 1) {
            $input = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }

        $parts = parse_url(preg_match('#^[a-z]+://#i', $input) ? $input : 'https://' . $input);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $raw = $query['t'] ?? $query['start'] ?? '';
        if (! is_string($raw) || $raw === '') {
            return 0;
        }

        if (ctype_digit($raw)) {
            return min((int) $raw, 86400);
        }
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $raw, $hms) === 1) {
            return min((int) ($hms[1] ?? 0) * 3600 + (int) ($hms[2] ?? 0) * 60 + (int) ($hms[3] ?? 0), 86400);
        }

        return 0;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Move an uploaded background video into the hero folder, as-is.
     *
     * Not transcoded: there is no ffmpeg on the box, and a background loop is
     * something to export small and silent before upload rather than fix up
     * here. The type is taken from the file's bytes, not the name or the
     * browser's say-so, and the stored name is random.
     *
     * @return array{path:string,error:string}
     */
    private function storeVideo(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            return ['path' => '', 'error' => in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That video is too big. Keep it under 12 MB.'
                : 'The video did not upload. Please try again.'];
        }
        if ($file->getSize() > self::MAX_VIDEO_BYTES) {
            return ['path' => '', 'error' => 'That video is too big. Keep it under 12 MB.'];
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName());
        $ext  = null;
        foreach (self::VIDEO_TYPES as $candidate => $types) {
            if (in_array($mime, $types, true)) {
                $ext = $candidate;
                break;
            }
        }
        if ($ext === null) {
            return ['path' => '', 'error' => 'Upload an MP4 or WebM video. An iPhone .mov needs exporting as MP4 first.'];
        }

        $name = 'hero-video-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir  = rtrim(FCPATH, '/') . '/' . self::UPLOAD_DIR;

        try {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            log_message('error', 'Could not store the hero video: ' . $e->getMessage());

            return ['path' => '', 'error' => 'The video could not be saved. Please try again.'];
        }

        return ['path' => self::UPLOAD_DIR . '/' . $name, 'error' => ''];
    }

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
