<?php

namespace App\Services;

use App\Models\DirectoryListingPhotoModel;

/**
 * One profile video per listing, a Verified Business perk.
 *
 * The owner pastes a YouTube or Vimeo link; the profile shows it as the first
 * tile of the photo strip and plays it in the lightbox. Nothing is uploaded or
 * streamed from our server.
 *
 * Two halves, split around the save's transaction like ListingGeocoder:
 *
 *  - prepare() does the network work before the transaction opens: it asks the
 *    provider's oEmbed endpoint whether the video exists and may be embedded,
 *    takes its title, and copies its thumbnail to our own disk. A five second
 *    timeout has no business holding a row lock.
 *  - apply() runs inside the transaction (from syncChildRows()) and only
 *    writes the columns.
 *
 * The badge gate is the same test as TeamMemberService::canManage(), checked
 * against the STORED row on save and again on render, and for the same
 * reason: a lapsed badge hides the video and keeps it, so renewing brings it
 * straight back.
 */
class ListingVideoService
{
    public const THUMB_DIR = 'assets/listings/video';

    /** Wide enough for the gallery's double tile on a large screen. */
    public const THUMB_WIDTH = 1280;

    /** A thumbnail is a JPEG of a few hundred KB; anything near this is not one. */
    private const THUMB_MAX_BYTES = 3_000_000;

    /** Where a thumbnail may be fetched from. Never a host taken from input. */
    private const THUMB_HOSTS = ['i.ytimg.com', 'i.vimeocdn.com'];

    public const COLUMNS = ['video_provider', 'video_id', 'video_hash', 'video_title', 'video_thumb_path'];

    /** @var callable(string):?array{status:int,body:string} */
    private $fetch;

    /**
     * @param (callable(string):?array{status:int,body:string})|null $fetch
     *   GET a URL; null on a network failure. Injected by the tests.
     */
    public function __construct(?callable $fetch = null)
    {
        helper(['video', 'directory_ui']);
        $this->fetch = $fetch ?? static function (string $url): ?array {
            try {
                $response = service('curlrequest', [
                    'timeout'         => 5,
                    'connect_timeout' => 3,
                    'http_errors'     => false,
                    'allow_redirects' => false,
                ], null, null, false)->get($url, ['headers' => ['User-Agent' => 'WebSchedulerLocal/1.0']]);

                return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
            } catch (\Throwable $e) {
                log_message('warning', 'Video lookup failed: {msg}', ['msg' => $e->getMessage()]);

                return null;
            }
        };
    }

    /** @param array<string,mixed> $listing */
    public function canManage(array $listing): bool
    {
        return listing_is_verified_business($listing);
    }

    /**
     * Resolve the posted video_url, before the save's transaction.
     *
     * Null when there is nothing to do: the field was not posted, the stored
     * row has no live badge, or the link and its playback choices are what is
     * already saved.
     *
     * @param array<string,mixed> $listing the STORED row
     * @param array<string,mixed> $input
     *
     * @return array{errors:array<string,string>,columns:array<string,int|string|null>,new_file:?string,old_file:?string}|null
     */
    public function prepare(array $listing, array $input): ?array
    {
        if (! array_key_exists('video_url', $input) || ! $this->canManage($listing)) {
            return null;
        }

        $raw     = is_string($input['video_url']) ? trim($input['video_url']) : '';
        $oldFile = ($listing['video_thumb_path'] ?? '') !== '' ? (string) $listing['video_thumb_path'] : null;

        // The two playback boxes. An unticked checkbox posts nothing, so the
        // video_settings marker tells "switched off" from "this form never
        // showed the boxes"; without it the stored choices stand.
        $settings = array_key_exists('video_settings', $input)
            ? ['video_autoplay' => empty($input['video_autoplay']) ? 0 : 1, 'video_sound' => empty($input['video_sound']) ? 0 : 1]
            : ['video_autoplay' => (int) ($listing['video_autoplay'] ?? 0), 'video_sound' => (int) ($listing['video_sound'] ?? 0)];

        if ($raw === '') {
            if (($listing['video_id'] ?? '') === '') {
                return null;
            }

            return [
                'errors'   => [],
                'columns'  => array_fill_keys(self::COLUMNS, null) + ['video_autoplay' => 0, 'video_sound' => 0],
                'new_file' => null,
                'old_file' => $oldFile,
            ];
        }

        $video = video_parse($raw);
        if ($video === null) {
            return $this->error('Paste a link to a YouTube or Vimeo video.');
        }

        // The video already saved: no lookup, and only the playback choices
        // can have changed.
        if ($video['provider'] === ($listing['video_provider'] ?? null)
            && $video['id'] === ($listing['video_id'] ?? null)
            && $video['hash'] === (string) ($listing['video_hash'] ?? '')) {
            if ($settings['video_autoplay'] === (int) ($listing['video_autoplay'] ?? 0)
                && $settings['video_sound'] === (int) ($listing['video_sound'] ?? 0)) {
                return null;
            }

            return ['errors' => [], 'columns' => $settings, 'new_file' => null, 'old_file' => null];
        }

        $meta = $this->oembed($video);
        if ($meta === false) {
            return $this->error('This video is private or cannot be shown on other websites. Check its sharing settings, or use another video.');
        }

        // $meta null is a network failure: save the link anyway, without a
        // title or thumbnail. A provider having a bad minute must not stop an
        // owner saving their profile; the tile falls back to a plain design.
        $newFile = $meta !== null && $meta['thumbnail'] !== '' ? $this->storeThumbnail($meta['thumbnail']) : null;

        return [
            'errors'  => [],
            'columns' => [
                'video_provider'   => $video['provider'],
                'video_id'         => $video['id'],
                'video_hash'       => $video['hash'] !== '' ? $video['hash'] : null,
                'video_title'      => $meta !== null && $meta['title'] !== '' ? mb_substr($meta['title'], 0, 200) : null,
                'video_thumb_path' => $newFile,
            ] + $settings,
            'new_file' => $newFile,
            'old_file' => $oldFile,
        ];
    }

    /**
     * Write a prepared video, inside the save's transaction.
     *
     * @param array{columns:array<string,?string>} $prepared
     */
    public function apply(int $listingId, array $prepared): void
    {
        db_connect()->table('xs_directory_listings')->where('id', $listingId)->update($prepared['columns']);
    }

    /**
     * What the profile renders, or null. The badge is checked here as well as
     * by the caller, so no path can render a video for an unverified listing.
     *
     * @param array<string,mixed> $listing
     *
     * Two player URLs: `tile` plays silently on a loop in the photo strip
     * (only when the owner chose autoplay), `embed` plays in the lightbox,
     * with sound when the owner chose it.
     *
     * @return array{provider:string,title:string,thumb:string,embed:string,tile:string,watch:string,autoplay:bool,sound:bool}|null
     */
    public function forProfile(array $listing): ?array
    {
        $provider = (string) ($listing['video_provider'] ?? '');
        $id       = (string) ($listing['video_id'] ?? '');
        if (! $this->canManage($listing) || ! in_array($provider, ['youtube', 'vimeo'], true) || $id === '') {
            return null;
        }
        $hash     = (string) ($listing['video_hash'] ?? '');
        $autoplay = ! empty($listing['video_autoplay']);
        $sound    = ! empty($listing['video_sound']);
        // YouTube only answers postMessage from the page it was told about.
        $origin = rtrim((string) preg_replace('#^(https?://[^/]+).*$#', '$1', base_url()), '/');

        return [
            'provider' => $provider,
            'title'    => (string) ($listing['video_title'] ?? ''),
            'thumb'    => (string) ($listing['video_thumb_path'] ?? ''),
            'embed'    => video_embed_url($provider, $id, $hash, ['autoplay' => true, 'muted' => ! $sound, 'origin' => $origin]),
            'tile'     => $autoplay ? video_embed_url($provider, $id, $hash, ['autoplay' => true, 'muted' => true, 'loop' => true, 'origin' => $origin]) : '',
            'watch'    => video_watch_url($provider, $id, $hash),
            'autoplay' => $autoplay,
            'sound'    => $sound,
        ];
    }

    /**
     * The link as the form shows it back: the canonical page, not the paste.
     *
     * @param array<string,mixed> $listing
     */
    public static function formValue(array $listing): string
    {
        helper('video');
        $provider = (string) ($listing['video_provider'] ?? '');
        $id       = (string) ($listing['video_id'] ?? '');

        return $provider !== '' && $id !== '' ? video_watch_url($provider, $id, (string) ($listing['video_hash'] ?? '')) : '';
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{errors:array<string,string>,columns:array<string,?string>,new_file:null,old_file:null} */
    private function error(string $message): array
    {
        return ['errors' => ['video_url' => $message], 'columns' => [], 'new_file' => null, 'old_file' => null];
    }

    /**
     * Ask the provider about the video.
     *
     * @param array{provider:string,id:string,hash:string} $video
     *
     * @return array{title:string,thumbnail:string}|false|null
     *   false: the provider says it cannot be embedded (private, removed,
     *   embedding switched off). null: we could not find out.
     */
    private function oembed(array $video): array|false|null
    {
        $watch = video_watch_url($video['provider'], $video['id'], $video['hash']);
        $url   = $video['provider'] === 'youtube'
            ? 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($watch)
            // domain: a video whose owner allowed embedding on our site only is
            // reported as missing unless Vimeo is told which site is asking.
            : 'https://vimeo.com/api/oembed.json?width=' . self::THUMB_WIDTH
                . '&domain=' . rawurlencode((string) parse_url(base_url(), PHP_URL_HOST))
                . '&url=' . rawurlencode($watch);

        $response = ($this->fetch)($url);
        if ($response === null || $response['status'] >= 500 || $response['status'] === 429) {
            return null;
        }
        if (in_array($response['status'], [400, 401, 403, 404], true)) {
            return false;
        }
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || ! is_array($data)) {
            return null;
        }

        $thumbnail = is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : '';
        if ($video['provider'] === 'youtube') {
            // oEmbed's hqdefault is 4:3 with black bars baked in. maxresdefault
            // is a clean 16:9 when the uploader's video is HD; mqdefault is the
            // clean 16:9 every video has. storeThumbnail() tries them in order.
            $thumbnail = 'https://i.ytimg.com/vi/' . rawurlencode($video['id']) . '/maxresdefault.jpg';
        }

        return ['title' => is_string($data['title'] ?? null) ? trim($data['title']) : '', 'thumbnail' => $thumbnail];
    }

    /**
     * Copy a thumbnail to our disk as WebP, or null if that is not possible.
     * Re-encoding through GD means what we serve is an image we made, whatever
     * the provider sent.
     */
    private function storeThumbnail(string $url): ?string
    {
        $candidates = [$url];
        if (preg_match('#^https://i\.ytimg\.com/vi/([^/]+)/maxresdefault\.jpg$#', $url, $m) === 1) {
            $candidates[] = 'https://i.ytimg.com/vi/' . $m[1] . '/mqdefault.jpg';
        }

        foreach ($candidates as $candidate) {
            if (parse_url($candidate, PHP_URL_SCHEME) !== 'https'
                || ! in_array(parse_url($candidate, PHP_URL_HOST), self::THUMB_HOSTS, true)) {
                continue;
            }
            $response = ($this->fetch)($candidate);
            if ($response === null || $response['status'] !== 200
                || $response['body'] === '' || strlen($response['body']) > self::THUMB_MAX_BYTES) {
                continue;
            }

            $path = $this->writeWebp($response['body']);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function writeWebp(string $bytes): ?string
    {
        if (! function_exists('imagewebp') || @getimagesizefromstring($bytes) === false) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        if (imagesx($image) > self::THUMB_WIDTH) {
            $scaled = imagescale($image, self::THUMB_WIDTH);
            imagedestroy($image);
            if ($scaled === false) {
                return null;
            }
            $image = $scaled;
        }

        $dir = FCPATH . self::THUMB_DIR;
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            imagedestroy($image);
            log_message('error', 'Could not create the video thumbnail directory');

            return null;
        }

        $name    = 'video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.webp';
        $written = @imagewebp($image, $dir . '/' . $name, 82);
        imagedestroy($image);

        if (! $written || ! is_file($dir . '/' . $name) || filesize($dir . '/' . $name) === 0) {
            @unlink($dir . '/' . $name);

            return null;
        }

        return self::THUMB_DIR . '/' . $name;
    }

    /** Delete a thumbnail file, through the photo model's upload-root guard. */
    public function deleteFile(?string $path): void
    {
        if ($path !== null && $path !== '') {
            (new DirectoryListingPhotoModel())->deleteFileAt($path);
        }
    }
}
