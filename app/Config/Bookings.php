<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Online bookings, the rebuild of WebScheduler Bookings inside Local
 * (docs/bookings/, skill `bookings`).
 *
 * Everything is dark until `bookings.enabled = true` in .env: every /business
 * route answers 404 and /manage shows no offer. A profile then still needs its
 * own switch (xs_directory_listings.bookings_enabled), which only
 * BookingSetupService writes.
 */
class Bookings extends BaseConfig
{
    /** The global switch (`bookings.enabled`). */
    public bool $enabled = false;

    /**
     * Sites that may use bookings (Config\Sites keys). co.za first, decision
     * D2 in docs/bookings/decisions.md; .app shows nothing until it is added.
     *
     * @var list<string>
     */
    public array $sites = ['za'];

    /** A signed in session ends after this long without a request (Bookings: 2 hours). */
    public int $idleSeconds = 7200;

    /** Sign in attempts per IP (Bookings' lockout: 5 per 15 minutes). */
    public int $ipAttempts = 5;

    public int $ipWindowSeconds = 900;

    /**
     * Failed attempts per account, from any address, before the account waits.
     * Stops slow guessing spread across many IPs.
     */
    public int $accountAttempts = 10;

    public int $accountWindowSeconds = 3600;

    public int $passwordMinLength = 10;

    /** Password reset link lifetime. */
    public int $resetSeconds = 3600;

    /** Emailed sign in link lifetime. */
    public int $signInLinkSeconds = 900;

    /**
     * Per business settings and their defaults (docs/bookings/settings.md).
     * A key not listed here cannot be stored. Each value is the default, and
     * `$settingChoices` limits what may replace it.
     *
     * @var array<string,string>
     */
    public array $settingDefaults = [
        'localization.time_format' => '24h',
        'localization.first_day'   => 'monday',
    ];

    /** @var array<string,list<string>> */
    public array $settingChoices = [
        'localization.time_format' => ['24h', '12h'],
        'localization.first_day'   => ['monday', 'sunday'],
    ];

    public function enabled(): bool
    {
        $env = env('bookings.enabled');
        if ($env === null || $env === '') {
            return $this->enabled;
        }

        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether bookings may be used on a site (a Config\Sites key). */
    public function siteAllowed(string $siteKey): bool
    {
        return $this->enabled() && in_array($siteKey, $this->sites, true);
    }
}
