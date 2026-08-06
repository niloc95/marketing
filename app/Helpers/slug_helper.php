<?php

use CodeIgniter\Model;

if (! function_exists('slugify')) {
    /**
     * Convert a string to a URL-safe slug.
     */
    function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

if (! function_exists('ensure_unique_slug')) {
    /**
     * Return a slug unique within $model.$field, appending -2, -3, … on collision.
     *
     * @param int|null            $ignoreId Row id to exclude (for updates).
     * @param array<int,string>   $reserved Slugs that may never be taken, even
     *                                      though no row in $model holds them.
     */
    function ensure_unique_slug(Model $model, string $field, string $base, ?int $ignoreId = null, array $reserved = []): string
    {
        $base = slugify($base);
        if ($base === '') {
            $base = 'listing';
        }
        $reserved = array_map('strtolower', $reserved);
        $slug = $base;
        $i = 2;
        while (true) {
            if (in_array($slug, $reserved, true)) {
                $slug = $base . '-' . $i;
                $i++;
                continue;
            }
            $builder = $model->where($field, $slug);
            if ($ignoreId !== null) {
                $builder = $builder->where('id !=', $ignoreId);
            }
            // Include soft-deleted rows so slugs never silently clash on restore.
            if (method_exists($builder, 'withDeleted')) {
                $builder = $builder->withDeleted();
            }
            if ((int) $builder->countAllResults() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }
}

if (! function_exists('listing_reserved_slugs')) {
    /**
     * Slugs a listing may not claim.
     *
     * /directory/{segment} resolves a category page before a profile, so a
     * business called "Hair Salon" would otherwise take /directory/hair-salon
     * and shadow the category landing page for everyone in it.
     *
     * @return array<int,string>
     */
    function listing_reserved_slugs(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $rows  = (new App\Models\DirectoryCategoryModel())->select('slug')->findAll();
        // 'province' is the literal first segment of /directory/province/{slug};
        // a category or listing holding it would shadow every province page.
        $cache = array_merge(['verify', 'categories', 'map', 'province'], array_column($rows, 'slug'));

        return $cache;
    }
}

if (! function_exists('normalise_place')) {
    /**
     * Tidy a free-text town/suburb so location data does not fragment across
     * "Cape Town", "cape town" and "  CAPE   TOWN ".
     *
     * Deliberately conservative — it does not correct spelling or map aliases,
     * so "Jhb" stays "Jhb" rather than being silently rewritten.
     */
    function normalise_place(?string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        if ($value === '') {
            return '';
        }

        // Title-case, but keep interior capitals people typed deliberately
        // (e.g. "McGregor", "KwaZulu") by only touching all-lower/all-upper words.
        return implode(' ', array_map(static function (string $word): string {
            if ($word !== mb_strtolower($word) && $word !== mb_strtoupper($word)) {
                return $word;
            }
            return mb_convert_case(mb_strtolower($word), MB_CASE_TITLE, 'UTF-8');
        }, explode(' ', $value)));
    }
}
