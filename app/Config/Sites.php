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
 *   sites.za.host        = local.webscheduler.co.za
 *   sites.app.host       = local.webscheduler.app
 *   sites.app.enabled    = false
 *   sites.app.analyticsId = G-...   .app's own GA4 property; unset = no analytics
 *   sites.app.clarityId   = ...     .app's own Clarity project
 */
class Sites extends BaseConfig
{
    public const ZA  = 'za';
    public const APP = 'app';

    /**
     * Per site settings. `countries` is the ISO codes the site shows, or null
     * for "every country the other site does not". Analytics IDs come from
     * .env (see the class docblock), wording from $appCopy.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $profiles = [
        self::ZA => [
            'countries'       => ['ZA'],
            // [lat, lng, zoom] a map opens on when nothing else says where.
            'mapView'         => [-28.8, 24.7, 5],
            'locale'          => 'en-ZA',
            'currency'        => 'ZAR',
            'paymentProvider' => 'payfast',
        ],
        self::APP => [
            'countries'       => null,
            'mapView'         => [25.0, 10.0, 2],
            'locale'          => 'en',
            'currency'        => 'USD',
            'paymentProvider' => 'paddle',
        ],
    ];

    /**
     * Wording that names South Africa on co.za, as local.webscheduler.app
     * says it. Read through site_copy(), which hands back co.za's own text
     * untouched there, so co.za keeps every word it has today. Same copy
     * rules as everywhere: discover, discovery, platform; never "directory";
     * no dashes and no hyphenated words.
     *
     * @var array<string,string>
     */
    public array $appCopy = [
        'title'           => 'Discover local businesses near you',
        'footer'          => 'Discover local. Be discovered. A discovery platform for local businesses, services, locations, professionals and opportunities. Create your free business profile.',
        'siteDescription' => 'A local business discovery and visibility platform for the United Kingdom, the United States, Australia, New Zealand, Namibia, Botswana, Kenya and Nigeria. '
            . 'Discover businesses, services, locations, professionals and opportunities near you, '
            . 'or create a free business profile: no monthly fee, no subscription, no obligation.',
        'homeDescription' => 'Discover local businesses, services, locations, professionals and opportunities near you. Create your free business profile: no monthly fee, no subscription.',
        'homeLede'        => 'The local discovery platform for businesses, services, professionals and locations near you.',
        'discoverTitle'   => 'Discover businesses near you.',
        'featuredLine'    => 'Handpicked from across the platform, and open for enquiries.',
        'where'           => ' near you',
        'discoverDescription' => 'Discover local businesses, services and professionals by category, country and city, on one local discovery platform.',
        'jobsWhere'       => 'near you',
        'companyFor'      => 'for businesses everywhere',
        'companyOffer'    => 'your area has',
    ];

    /**
     * Pages that exist on co.za only, as route path prefixes. On the app host
     * a GET for one of them is a 301 to the same path on co.za
     * (Filters\SiteResolver): our own careers and the partner programme are
     * South African (the profile setup service is sold on both, see
     * ProfileSetupService::amount()), the
     * comparison and the guides are about South Africa, recommendations and
     * province pages are South African by construction.
     *
     * @var list<string>
     */
    public array $zaOnlyPaths = [
        'careers', 'partners', 'p/', 'compare', 'resources', 'recommend',
        'directory/province/',
        // /verified explains a badge .app does not sell (Plus instead). The
        // FAQ and /company have .app versions (faq.php, company.php).
        'verified',
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

    /** Is this route path (no leading slash) one of $zaOnlyPaths? */
    public function isZaOnly(string $path): bool
    {
        $path = ltrim($path, '/');
        foreach ($this->zaOnlyPaths as $prefix) {
            if (str_ends_with($prefix, '/') ? str_starts_with($path, $prefix) : ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }

        return false;
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
