<?php

namespace App\Services\Bookings;

use DateTimeZone;

/**
 * A business's own clock and currency, from its profile: the profile's time
 * zone if it has one, else its country's (and region's) zone from
 * Config\Countries, as hours_context() does; its prices in its country's
 * currency. Since bookings came to local.webscheduler.app (decision D2,
 * 5 Nov 2026) a business may be anywhere, so nothing in bookings assumes
 * Johannesburg or rand.
 */
final class BusinessLocale
{
    /** @param array<string,mixed> $listing */
    public static function timezoneName(array $listing): string
    {
        $tz = trim((string) ($listing['timezone'] ?? ''));
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return config('Countries')->timezone(
            (string) ($listing['country_code'] ?? 'ZA') ?: 'ZA',
            (string) ($listing['region'] ?? $listing['province'] ?? ''),
        );
    }

    /** @param array<string,mixed> $listing */
    public static function zone(array $listing): DateTimeZone
    {
        return new DateTimeZone(self::timezoneName($listing));
    }

    /**
     * ISO 4217 code for the business's prices: its country's currency, US
     * dollars for a country outside the registry abroad, rand at home.
     *
     * @param array<string,mixed> $listing
     */
    public static function currency(array $listing): string
    {
        $code    = strtoupper(trim((string) ($listing['country_code'] ?? ''))) ?: 'ZA';
        $profile = config('Countries')->profile($code);

        return (string) ($profile['currency'] ?? ($code === 'ZA' ? 'ZAR' : 'USD'));
    }
}
