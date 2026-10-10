<?php

namespace Config;

use App\Libraries\Geocoding\FallbackGeocoder;
use App\Libraries\Geocoding\GeocoderInterface;
use App\Libraries\Geocoding\MapboxGeocoder;
use App\Libraries\Geocoding\NominatimGeocoder;
use App\Libraries\DomainChecker;
use App\Libraries\MauticClient;
use App\Services\Description\DescriptionDraftService;
use App\Services\Description\DescriptionWriter;
use App\Services\Description\WorkersAiWriter;
use App\Services\DirectoryService;
use App\Services\Search\QueryInterpreter;
use App\Services\Search\RuleBasedInterpreter;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Whoever answers this app's address lookups.
     *
     * Mapbox when `directory.mapboxToken` is set, which gives the form its
     * address typeahead, with Nominatim behind it for pins whenever Mapbox
     * can't be reached (see FallbackGeocoder). Otherwise Nominatim
     * (OpenStreetMap) alone, free and keyless, for single lookups only, since
     * its usage policy forbids autocomplete.
     * Either way this is lookup only: maps stay on Leaflet/CARTO, and "near me"
     * stays a query against our own spatial index.
     *
     * The indirection is not dead weight: the autocomplete endpoints,
     * ListingGeocoder and `spark directory:geocode` all resolve through here, so
     * they can never end up on different providers.
     */
    public static function geocoder(bool $getShared = true): GeocoderInterface
    {
        if ($getShared) {
            return static::getSharedInstance('geocoder');
        }

        $token = config('Directory')->mapboxToken();

        return $token !== ''
            ? new FallbackGeocoder(new MapboxGeocoder($token), new NominatimGeocoder())
            : new NominatimGeocoder();
    }

    /**
     * Whatever reads a typed search into category, place and keywords.
     *
     * The rules today (free, local). A model-backed interpreter replaces this
     * line, not the search: DirectoryService only ever sees the SearchIntent.
     *
     * Never shared, whatever $getShared says. The interpreter holds a snapshot
     * of the vocabulary — the categories and places that exist — and a shared
     * instance would keep yesterday's for the life of the process (and across
     * tests, which reset the cache but not shared services). Building one is
     * cheap: searchVocabulary() is cached.
     */
    public static function queryInterpreter(bool $getShared = false): QueryInterpreter
    {
        return new RuleBasedInterpreter((new DirectoryService())->searchVocabulary(), config('Search'));
    }

    /**
     * Whatever drafts a description for "Help me write this": Cloudflare
     * Workers AI over the templates when its credentials are set, the
     * templates alone otherwise. See DescriptionWriter.
     */
    public static function descriptionWriter(bool $getShared = true): DescriptionWriter
    {
        if ($getShared) {
            return static::getSharedInstance('descriptionWriter');
        }

        $config = config('Directory');
        $ai     = $config->workersAi();
        if ($ai['token'] === '') {
            return new DescriptionDraftService();
        }

        return new WorkersAiWriter($ai['account'], $ai['token'], $config->workersAiModel, $config->aiDraftsPerDay);
    }

    /**
     * The Mautic API client for the listing-owner marketing sync. A service so
     * tests can swap in a recording fake with Services::injectMock('mautic').
     */
    public static function mautic(bool $getShared = true): MauticClient
    {
        if ($getShared) {
            return static::getSharedInstance('mautic');
        }

        return new MauticClient();
    }

    /**
     * The DNS check behind "we couldn't find that website". Answers yes to
     * everything under the testing environment, so the suite never makes a
     * network call; a test that wants a "no" injects its own.
     */
    public static function domainChecker(bool $getShared = true): DomainChecker
    {
        if ($getShared) {
            return static::getSharedInstance('domainChecker');
        }

        if (ENVIRONMENT === 'testing') {
            return new class () extends DomainChecker {
                public function exists(string $host): bool
                {
                    return true;
                }
            };
        }

        return new DomainChecker();
    }
}
