<?php

namespace App\Libraries\Geocoding;

/**
 * The address lookups the listing form and the mapping module need, independent
 * of who answers them.
 *
 * Two providers: MapboxGeocoder when `directory.mapboxToken` is set (wrapped in
 * FallbackGeocoder, so Nominatim answers when Mapbox can't), and
 * NominatimGeocoder (OpenStreetMap, free and keyless) otherwise. Choosing
 * between them happens in Services::geocoder() and nowhere else.
 *
 * Implementations must never throw. Every method has a "nothing useful" return
 * value ([] or null) and callers rely on it: these run behind a public,
 * unauthenticated form, and a third party being slow or down must not turn into
 * a 500 on someone's signup.
 *
 * Nothing here is ever called on a page load or during a search. Coordinates are
 * resolved once, when an address is created or changed, and cached on the
 * listing row — reads never touch a geocoder.
 */
interface GeocoderInterface
{
    /**
     * Suggestions for a partly-typed address, South Africa only.
     *
     * These rows are what the browser renders in the autocomplete dropdown, and
     * picking one fills the address fields. lat/lng are optional. Mapbox's
     * suggestions are temporary results that may not be stored, so they omit
     * them, and the browser asks /address-locate (geocodeParts) for the pin
     * after the pick instead. An empty list is also a valid answer from a
     * provider that doesn't offer autocomplete at all (Nominatim).
     *
     * @return list<array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat?:float,lng?:float,precision:string}>
     */
    public function suggest(string $query, int $limit = 5): array;

    /**
     * Resolve already-separated address fields to a single point, for addresses
     * typed by hand rather than picked from the dropdown.
     *
     * @param array<string,mixed> $parts address_line, address_line_2, suburb, city, province, postal_code
     *
     * @return array{lat:float,lng:float,precision:string,label?:string}|null
     *   precision is one of exact|street|suburb|city; label is the provider's
     *   own normalised rendering of the address, stored as geocoded_address so
     *   a human can see what the coordinates actually resolved to.
     */
    public function geocodeParts(array $parts): ?array;

    /**
     * The address at a point — the inverse of geocodeParts().
     *
     * Used when someone drags the map marker: the pin is authoritative, but the
     * address text beside it is now probably wrong, so we offer them what OSM
     * says is there and let them accept or discard it.
     *
     * @return array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float,precision:string}|null
     *   null when the point resolves to nothing, or falls outside South Africa.
     */
    public function reverse(float $lat, float $lng): ?array;
}
