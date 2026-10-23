<?php

namespace App\Services;

use Config\Sites;

/**
 * Which site this request is on, and which site a country belongs to.
 *
 * Built once per request by Services::site() from the Host header. Anything
 * that is not the app host is za: CLI runs, cron, the legacy listing. host,
 * an IP address, an unknown name. That default is what keeps every email a
 * cron job sends, and every page on co.za, exactly as it was.
 *
 * While sites.app.enabled is false, forCountry() answers za for every
 * country, so a foreign owner's manage link keeps pointing at co.za instead
 * of at a host that only redirects.
 */
class SiteContext
{
    private Sites $sites;
    private string $key;

    public function __construct(?string $host = null, ?Sites $sites = null)
    {
        $this->sites = $sites ?? config(Sites::class);
        $host        = strtolower((string) preg_replace('/:\d+$/', '', trim((string) $host)));
        $this->key   = ($host !== '' && $host === $this->sites->host(Sites::APP)) ? Sites::APP : Sites::ZA;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isApp(): bool
    {
        return $this->key === Sites::APP;
    }

    public function appEnabled(): bool
    {
        return $this->sites->appEnabled();
    }

    /** @return array<string,mixed> */
    public function profile(): array
    {
        return $this->sites->profile($this->key);
    }

    /**
     * The site a country's profiles are served on. NULL and empty codes are
     * South African, as everywhere else.
     */
    public function forCountry(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        if (! $this->sites->appEnabled() || $code === '' || $code === 'ZA') {
            return Sites::ZA;
        }

        return Sites::APP;
    }

    /** @param array<string,mixed> $row a listing, branch, job post... */
    public function forRow(array $row): string
    {
        return $this->forCountry(isset($row['country_code']) ? (string) $row['country_code'] : null);
    }

    /** Does this site show profiles from this country? */
    public function ownsCountry(?string $code): bool
    {
        return $this->forCountry($code) === $this->key;
    }

    /**
     * Should public queries be filtered by site at all? Not until the app
     * site is live: co.za shows every country today and must keep doing so.
     */
    public function scoping(): bool
    {
        return $this->sites->appEnabled();
    }

    /**
     * A raw SQL condition limiting a country_code column to this site's
     * countries, or '' while there is nothing to limit (see scoping()).
     * NULL codes are South African, as everywhere else.
     *
     * $column is code supplied ("l.country_code"), never user input.
     */
    public function scopeSql(string $column): string
    {
        if (! $this->scoping()) {
            return '';
        }

        return $this->key === Sites::APP
            ? "({$column} IS NOT NULL AND {$column} <> 'ZA')"
            : "({$column} IS NULL OR {$column} = 'ZA')";
    }

    public function host(?string $key = null): string
    {
        return $this->sites->host($key ?? $this->key);
    }
}
