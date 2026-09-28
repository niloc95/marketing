<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The admin-editable settings key/value store. Deliberately thin — all the
 * meaning lives in App\Services\DirectorySettings, which owns the fallback
 * chain, the caching and the validation.
 */
class DirectorySettingModel extends Model
{
    protected $table         = 'xs_directory_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'value', 'updated_by'];

    /** Names this store knows about. Anything else is refused by put(). */
    public const BADGE_PRICE   = 'verified_badge_price';
    public const BADGE_ENABLED = 'verified_badge_enabled';

    /**
     * The International Listing plan — what a business outside South Africa
     * pays monthly to stay published. Its own rows rather than a reuse of the
     * badge's, because the two prices are independent and switching one off
     * must not switch the other off with it.
     */
    public const INTERNATIONAL_PRICE   = 'international_listing_price';
    public const INTERNATIONAL_ENABLED = 'international_listing_enabled';

    /** Home hero background: 'photos' (the rotation), 'video' or 'youtube'. */
    public const HERO_MEDIA      = 'hero_media';
    public const HERO_VIDEO_PATH = 'hero_video_path';
    public const HERO_YOUTUBE_ID = 'hero_youtube_id';
    /** Seconds into the YouTube video to start (and loop back to). */
    public const HERO_YOUTUBE_START = 'hero_youtube_start';

    public const KNOWN = [
        self::BADGE_PRICE,
        self::BADGE_ENABLED,
        self::INTERNATIONAL_PRICE,
        self::INTERNATIONAL_ENABLED,
        self::HERO_MEDIA,
        self::HERO_VIDEO_PATH,
        self::HERO_YOUTUBE_ID,
        self::HERO_YOUTUBE_START,
    ];

    /**
     * Every stored setting, keyed by name.
     *
     * One query for the whole table rather than one per key: there are a
     * handful of rows, the caller caches the result, and a per-key API would
     * quietly become N queries per page render the moment a second setting
     * appeared on a public page.
     *
     * @return array<string,array{value:?string,updated_by:?string,updated_at:?string}>
     */
    public function allSettings(): array
    {
        $out = [];
        foreach ($this->findAll() as $row) {
            $out[(string) $row['name']] = [
                'value'      => $row['value'],
                'updated_by' => $row['updated_by'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Insert or update one setting. Returns false for a name this store does
     * not recognise, so a crafted POST cannot invent settings rows.
     */
    public function put(string $name, string $value, string $by): bool
    {
        if (! in_array($name, self::KNOWN, true)) {
            return false;
        }

        $existing = $this->where('name', $name)->first();

        if (is_array($existing)) {
            return (bool) $this->update((int) $existing['id'], ['value' => $value, 'updated_by' => $by]);
        }

        return (bool) $this->insert(['name' => $name, 'value' => $value, 'updated_by' => $by]);
    }
}
