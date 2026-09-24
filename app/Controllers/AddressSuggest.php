<?php

namespace App\Controllers;

/**
 * AJAX address backend for the shared listing form and its map picker. Public
 * and unauthenticated (used during signup, before an owner/admin session
 * exists), so every action is throttled by IP.
 *
 * All three proxy to service('geocoder'): Mapbox when a token is set, which is
 * billed per request, otherwise Nominatim, which is donation-funded. Either way
 * the throttle is what stops this app becoming a way to run up the bill or
 * hammer someone else's server. Nothing here is ever called from a page render
 * or a search; coordinates are resolved once, when an address is created or
 * changed, and cached on the listing row.
 *
 * index() answers from temporary Mapbox results with no coordinates. The
 * browser then calls locate(), which is a permanent lookup, for the pin it
 * saves. With Nominatim, index() is always empty (see NominatimGeocoder::suggest).
 */
class AddressSuggest extends BaseController
{
    private const MAX_PER_MINUTE = 20;
    private const SUGGESTION_LIMIT = 5;

    public function index()
    {
        $query = trim((string) $this->request->getGet('q'));

        if (mb_strlen($query) < 3) {
            return $this->response->setJSON([]);
        }

        if (! $this->allow('address-suggest')) {
            return $this->response->setJSON([]);
        }

        return $this->response->setJSON(
            service('geocoder')->suggest($query, self::SUGGESTION_LIMIT)
        );
    }

    /**
     * Resolve the address fields as currently filled in to a single point, so
     * the form's pin picker can centre its map before the user drags.
     *
     * Deliberately runs the structured ladder rather than reusing index()'s
     * free-form search: free-form is all-or-nothing and returns nothing at all
     * for the addresses this feature exists to rescue, which would leave the
     * map with nowhere to centre. The ladder always degrades to something —
     * even if only the city — and reports how precise it managed to be.
     */
    public function locate()
    {
        if (! $this->allow('address-locate')) {
            return $this->response->setJSON([]);
        }

        $parts = [];
        foreach (['address_line', 'address_line_2', 'suburb', 'city', 'province', 'postal_code'] as $field) {
            $parts[$field] = trim((string) $this->request->getGet($field));
        }

        $coords = service('geocoder')->geocodeParts($parts);

        return $this->response->setJSON($coords ?? []);
    }

    /**
     * What address is at this point? Answers the map picker after someone drags
     * the marker.
     *
     * The result is only ever a suggestion. The pin the user placed is the
     * authoritative thing — it exists precisely because the geocoder got the
     * address wrong — so the browser offers this for them to accept or discard
     * rather than applying it.
     */
    public function reverse()
    {
        $lat = $this->request->getGet('lat');
        $lng = $this->request->getGet('lng');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return $this->response->setJSON([]);
        }

        if (! $this->allow('address-reverse')) {
            return $this->response->setJSON([]);
        }

        return $this->response->setJSON(
            service('geocoder')->reverse((float) $lat, (float) $lng) ?? []
        );
    }

    /** Per-IP, per-action rate limit. False means "over the limit". */
    private function allow(string $action): bool
    {
        return service('throttler')->check(
            $action . '-' . md5((string) $this->request->getIPAddress()),
            self::MAX_PER_MINUTE,
            MINUTE
        ) !== false;
    }
}
