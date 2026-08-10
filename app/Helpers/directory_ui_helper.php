<?php

if (! function_exists('category_group_emoji')) {
    /**
     * The icon for a category group.
     *
     * Categories carry no icon column, so the whole icon system is this map.
     * It must cover every group DirectoryCategoriesSeeder creates — anything
     * missing falls through to the generic folder and looks like an oversight
     * on the homepage tiles.
     */
    function category_group_emoji(?string $group): string
    {
        static $map = [
            'Health & Medical'      => '🩺',
            'Beauty & Wellness'     => '💆',
            'Hair'                  => '💇',
            'Motoring'              => '🚗',
            'Legal & Financial'     => '⚖️',
            'Home & Trades'         => '🔧',
            'Professional Services' => '💼',
            'Fitness & Sport'       => '🏋️',
            'Education & Training'  => '🎓',
            'Events & Hospitality'  => '🎉',
            'Pets & Animals'        => '🐾',
            'Retail & Other'        => '🛍️',
        ];

        return $map[(string) $group] ?? '📁';
    }
}

if (! function_exists('safe_external_url')) {
    /**
     * A stored URL that is safe to put in an href, or '' if it isn't.
     *
     * esc($url, 'attr') escapes the HTML but not the scheme, so a stored
     * "javascript:…" survives it and runs on click. Writes are normalised by
     * DirectoryListingMutationService::normaliseUrl(), but rows predating that
     * — or written by any future path that forgets — still reach the template,
     * so the check is repeated here where the value actually becomes a link.
     */
    function safe_external_url(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
    }
}

if (! function_exists('listing_image_url')) {
    /**
     * Resolve a stored image path to something an <img src> can use.
     *
     * Uploaded images are stored FCPATH-relative ("assets/listings/x.webp");
     * imported ones can carry an absolute URL. Returns '' for no image so
     * callers can branch on a falsy value.
     */
    function listing_image_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        return preg_match('#^https?://#i', $path) === 1 ? $path : base_url($path);
    }
}

if (! function_exists('listing_is_verified_business')) {
    /**
     * Does this listing currently carry the paid Verified Business badge?
     *
     * The whole test, deliberately: a date comparison against a column already
     * present on every listing row the public queries select. No join, no extra
     * query, and it works identically on a search card, a profile page and the
     * admin table.
     *
     * Evaluating it at render time rather than trusting a stored boolean is what
     * makes the badge honest. If the nightly sweep is wedged, or a subscription
     * cancels between two page loads, the badge stops appearing on the day it
     * was paid to — we are never displaying a claim we are no longer being paid
     * to make. The sweep's job is to catch the database up with what visitors
     * can already see, not the other way round.
     *
     * String comparison is safe here because the column is a DATE: MySQL hands
     * back 'YYYY-MM-DD', which sorts lexicographically exactly as it sorts
     * chronologically.
     *
     * @param array<string,mixed> $listing
     */
    function listing_is_verified_business(array $listing): bool
    {
        $until = trim((string) ($listing['verified_until'] ?? ''));

        return $until !== '' && $until >= date('Y-m-d');
    }
}
