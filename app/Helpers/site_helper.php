<?php

use App\Services\SiteContext;
use Config\Sites;

/**
 * Absolute URLs on a named site, and on the site a profile belongs to.
 *
 * base_url() already follows the request's host (Config\App lists the app
 * host in allowedHostnames), so a page linking to itself needs nothing new.
 * These are for the other cases: an email a cron job sends about a Nairobi
 * profile, PayFast's notify URL (always co.za), a link from one site to the
 * other.
 *
 * For the site the request is on, site_url_for() IS base_url(), character for
 * character. For the other site it is base_url() with the host swapped, so
 * the scheme, port and index page match whatever this install uses.
 */

if (! function_exists('site')) {
    function site(): SiteContext
    {
        return service('site');
    }
}

if (! function_exists('site_url_for')) {
    function site_url_for(string $siteKey, string $path = ''): string
    {
        $url = base_url($path);

        if ($siteKey === site()->key()) {
            return $url;
        }

        $current = (string) parse_url($url, PHP_URL_HOST);
        $target  = config(Sites::class)->host($siteKey);

        if ($current === '' || $target === '' || $current === $target) {
            return $url;
        }

        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)' . preg_quote($current, '#') . '#i', '${1}' . $target, $url, 1);
    }
}

if (! function_exists('row_site_url')) {
    /**
     * A path on the site that owns this row (a listing, branch, job post).
     * What every email about a profile should link with.
     *
     * @param array<string,mixed> $row
     */
    function row_site_url(array $row, string $path = ''): string
    {
        return site_url_for(site()->forRow($row), $path);
    }
}

if (! function_exists('listing_url')) {
    /**
     * A profile's public page, on the site it belongs to.
     *
     * @param array<string,mixed> $row
     */
    function listing_url(array $row): string
    {
        return row_site_url($row, 'directory/' . ($row['slug'] ?? ''));
    }
}

if (! function_exists('listing_site_url')) {
    /**
     * A path on the site that owns a listing, given the row or just its id.
     * For the email builders that only hold an id (manage links minted from
     * cron). One indexed lookup per call, deliberately uncached: a country
     * change must show in the very next email. A missing listing is za.
     *
     * @param array<string,mixed>|int $listing
     */
    function listing_site_url(array|int $listing, string $path = ''): string
    {
        if (is_array($listing) && array_key_exists('country_code', $listing)) {
            return row_site_url($listing, $path);
        }

        $id   = (int) (is_array($listing) ? ($listing['id'] ?? 0) : $listing);
        $code = null;
        if ($id > 0 && site()->scoping()) {
            $row  = db_connect()->table('xs_directory_listings')->select('country_code')->where('id', $id)->get()->getRowArray();
            $code = $row['country_code'] ?? null;
        }

        return row_site_url(['country_code' => $code], $path);
    }
}

if (! function_exists('site_cache_key')) {
    /**
     * A cache key for content that differs per site (the sitemap, the header
     * categories, the discovery vocabulary). co.za keeps the exact key it has
     * always used, so a release does not cold start its cache; .app gets its
     * own copy.
     */
    function site_cache_key(string $key): string
    {
        return site()->isApp() ? $key . '_' . site()->key() : $key;
    }
}
