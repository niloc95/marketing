<?php

namespace Config;

use App\Libraries\Geocoding\FallbackGeocoder;
use App\Libraries\Geocoding\GeocoderInterface;
use App\Libraries\Geocoding\MapboxGeocoder;
use App\Libraries\Geocoding\NominatimGeocoder;
use App\Libraries\MauticClient;
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
}
