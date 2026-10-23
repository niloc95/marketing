<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The two sites one install serves.
 *
 *  - za:  local.webscheduler.co.za, South African profiles only.
 *  - app: local.webscheduler.app, every other country.
 *
 * One codebase, one database. The request's host picks the site
 * (App\Services\SiteContext); a profile's country_code picks the site it
 * belongs to. The visitor's location never decides either.
 *
 * Until sites.app.enabled is true the app site does not exist as far as the
 * app is concerned: its host 302s to co.za (Filters\SiteResolver), every
 * country belongs to za, and co.za keeps serving everything it serves today,
 * foreign International profiles included. Flipping the flag back is the
 * rollback.
 *
 * Hosts come from .env so dev can use za.webscheduler.test and
 * app.webscheduler.test. The za host defaults to app.baseURL's host, which is
 * what keeps base_url() and site_url_for('za') identical on co.za.
 *
 *   sites.za.host     = local.webscheduler.co.za
 *   sites.app.host    = local.webscheduler.app
 *   sites.app.enabled = false
 */
class Sites extends BaseConfig
{
    public const ZA  = 'za';
    public const APP = 'app';

    /**
     * Per site settings. `countries` is the ISO codes the site shows, or null
     * for "every country the other site does not". Values left null fall back
     * to Config\Directory, so co.za keeps reading exactly what it reads today.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $profiles = [
        self::ZA => [
            'countries'       => ['ZA'],
            'locale'          => 'en-ZA',
            'currency'        => 'ZAR',
            'paymentProvider' => 'payfast',
            'siteName'        => null,
            'analyticsId'     => null,
            'clarityId'       => null,
        ],
        self::APP => [
            'countries'       => null,
            'locale'          => 'en',
            'currency'        => 'USD',
            'paymentProvider' => 'paddle',
            'siteName'        => null,
            'analyticsId'     => null,
            'clarityId'       => null,
        ],
    ];

    /** The app site's default host; .env sites.app.host overrides it. */
    public string $appHost = 'local.webscheduler.app';

    public function host(string $key): string
    {
        if ($key === self::APP) {
            return strtolower(trim((string) env('sites.app.host', $this->appHost)));
        }

        $host = trim((string) env('sites.za.host', ''));
        if ($host === '') {
            $host = (string) parse_url((string) config(App::class)->baseURL, PHP_URL_HOST);
        }

        return strtolower($host);
    }

    /**
     * Is local.webscheduler.app live? Read from .env, never the database, so
     * a release can be rolled back with one line and a reload.
     */
    public function appEnabled(): bool
    {
        return filter_var(env('sites.app.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->profiles);
    }

    /** @return array<string,mixed> */
    public function profile(string $key): array
    {
        return $this->profiles[$key] ?? $this->profiles[self::ZA];
    }
}
