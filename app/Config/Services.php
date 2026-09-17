<?php

namespace Config;

use App\Libraries\Geocoding\GeocoderInterface;
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
     * Nominatim (OpenStreetMap) — free, keyless, and the only provider this app
     * uses, by design. The mapping stack is open-source end to end.
     *
     * The indirection is not dead weight: the autocomplete endpoints,
     * ListingGeocoder and `spark directory:geocode` all resolve through here, so
     * they can never end up on different providers, and swapping in Photon or
     * Pelias later is a one-line change behind GeocoderInterface rather than a
     * hunt through four call sites.
     */
    public static function geocoder(bool $getShared = true): GeocoderInterface
    {
        if ($getShared) {
            return static::getSharedInstance('geocoder');
        }

        return new NominatimGeocoder();
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
