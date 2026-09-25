<?php

namespace App\Libraries\Geocoding;

use App\Services\DirectoryService;

/**
 * Address lookups via the Mapbox Geocoding API (v6), used whenever
 * `directory.mapboxToken` is set. See Services::geocoder().
 *
 * Mapbox is for lookup only. Maps are still drawn with Leaflet on CARTO's
 * OpenStreetMap tiles, and "near me" is still a query against our own spatial
 * index, so none of that costs a request.
 *
 * **Temporary vs permanent is a licensing line, not a tuning knob.** Mapbox's
 * terms forbid storing or caching results from ordinary (temporary) requests.
 * Anything we keep has to come from a request made with `permanent=true`,
 * which is billed separately. So the two kinds of call are kept strictly apart:
 *
 *  - suggest() is temporary. It runs on keystrokes, is never cached, and its
 *    rows deliberately carry **no coordinates**. A temporary point therefore
 *    has no way into the hidden latitude/longitude inputs, and so none into
 *    the database. The browser asks /address-locate for the pin once a row
 *    is picked.
 *  - geocodeParts() and reverse() are permanent. Their results are what end up
 *    on the listing row (coordinates, geocoded_address, or address text the
 *    owner accepts after dragging the pin), so they may be cached like
 *    Nominatim's.
 *
 * Never throws, like every GeocoderInterface implementation. The access token
 * travels as a query parameter, so nothing logged here may include the URL,
 * and transport error messages are scrubbed of it before logging.
 */
class MapboxGeocoder implements GeocoderInterface
{
    private const FORWARD_ENDPOINT = 'https://api.mapbox.com/search/geocode/v6/forward';
    private const REVERSE_ENDPOINT = 'https://api.mapbox.com/search/geocode/v6/reverse';

    /**
     * Shorter than Nominatim's 8s. Mapbox is a paid CDN-backed service, so a
     * slow answer means something is wrong, and suggest() sits under
     * someone's typing.
     */
    private const TIMEOUT = 5;

    private const MIN_QUERY_LEN = 3;

    /** One retry on a 429, a 5xx or a transport failure. No rate spacing is needed. */
    private const MAX_ATTEMPTS = 2;

    /** Same as NominatimGeocoder: addresses don't move. Permanent results only. */
    private const CACHE_TTL = 30 * DAY;

    /**
     * Feature types a suggestion or lookup may resolve to. Region and country
     * are left out: "somewhere in Gauteng" is not a location for a business,
     * and a pin on the province's centroid would be worse than none.
     */
    private const TYPES = 'address,street,neighborhood,locality,place,postcode';

    /**
     * Whether a call in the latest lookup never got an answer: a non-200 or a
     * transport error, as opposed to a 200 with no match. Reset by each public
     * method, set by request(). FallbackGeocoder reads it through failed() to
     * decide whether a null means "no such address" or "Mapbox is down".
     */
    protected bool $failed = false;

    public function __construct(private readonly string $token)
    {
    }

    /**
     * True when the latest suggest(), geocodeParts() or reverse() came back
     * empty because Mapbox couldn't be reached or refused, e.g. a paused
     * account or a revoked token, rather than because nothing matched.
     */
    public function failed(): bool
    {
        return $this->failed;
    }

    /**
     * Temporary lookups for the typeahead. Components only, no coordinates:
     * see the class docblock for why.
     *
     * @return list<array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,precision:string}>
     */
    public function suggest(string $query, int $limit = 5): array
    {
        $this->failed = false;
        $query        = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LEN) {
            return [];
        }

        $features = $this->request(self::FORWARD_ENDPOINT, [
            'q'            => $query,
            'country'      => 'za',
            'autocomplete' => 'true',
            'limit'        => max(1, min($limit, 10)),
            'language'     => 'en',
            'types'        => self::TYPES,
            'permanent'    => 'false',
        ], 'suggest');

        $suggestions = [];
        foreach ($features as $feature) {
            $mapped = $this->mapFeature($feature);
            if ($mapped === null) {
                continue;
            }
            // The whole point: a temporary coordinate must never reach the form.
            unset($mapped['lat'], $mapped['lng']);
            $suggestions[] = $mapped;
        }

        return $suggestions;
    }

    /**
     * Permanent lookup of already-separated address fields.
     *
     * Structured input first, because it lets Mapbox weigh each component on
     * its own, and a suburb it doesn't know is then a partial match rather
     * than a miss. Then the same fields as one free-text query, which handles
     * a whole address pasted into the one "Address" field. Last, the locality
     * alone, so a street nobody has mapped still lands in the right suburb.
     *
     * @param array<string,mixed> $parts
     *
     * @return array{lat:float,lng:float,precision:string,label?:string}|null
     */
    public function geocodeParts(array $parts): ?array
    {
        $this->failed = false;
        $street   = $this->clean($parts['address_line'] ?? '');
        $suburb   = $this->clean($parts['suburb'] ?? '');
        $city     = $this->clean($parts['city'] ?? '');
        $province = $this->clean($parts['province'] ?? '');
        $postal   = $this->clean($parts['postal_code'] ?? '');

        if ($street === '' && $suburb === '' && $city === '' && $postal === '') {
            return null;
        }

        // Prefixed differently from Nominatim's keys so switching provider
        // doesn't serve one provider's cached answer as the other's.
        $cacheKey = 'mapbox_geocode_' . md5(strtolower(implode('|', [$street, $suburb, $city, $province, $postal])));
        $cached   = cache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'none') {
            return null;
        }

        $base = [
            'country'      => 'za',
            'autocomplete' => 'false',
            'limit'        => 1,
            'language'     => 'en',
            'types'        => self::TYPES,
            'permanent'    => 'true',
        ];

        $attempts = [];

        if ($street !== '') {
            // "12 Long Street, Unit 4" → number 12, street "Long Street".
            $head = trim(explode(',', $street)[0]);
            $structured = preg_match('/^(\d+[A-Za-z]?)\s+(.+)$/', $head, $m)
                ? ['address_number' => $m[1], 'street' => $m[2]]
                : ['street' => $head];
            $attempts[] = $structured + array_filter([
                'locality' => $suburb,
                'place'    => $city,
                'region'   => $province,
                'postcode' => $postal,
            ], static fn ($v) => $v !== '');
        }

        $full = implode(', ', array_filter([$street, $suburb, $city, $province, $postal], static fn ($v) => $v !== ''));
        $attempts[] = ['q' => $full];

        $locality = implode(', ', array_filter([$suburb, $city, $province], static fn ($v) => $v !== ''));
        if ($street !== '' && $locality !== '') {
            $attempts[] = ['q' => $locality];
        }

        foreach ($attempts as $params) {
            $features = $this->request(self::FORWARD_ENDPOINT, $params + $base, 'geocode');
            $feature  = $features[0] ?? null;
            if ($feature === null) {
                continue;
            }

            $mapped = $this->mapFeature($feature);
            if ($mapped === null || ! $this->accept($mapped, $feature, $province)) {
                continue;
            }

            $coords = [
                'lat'       => $mapped['lat'],
                'lng'       => $mapped['lng'],
                'precision' => $mapped['precision'],
                'label'     => $mapped['label'],
            ];
            cache()->save($cacheKey, $coords, self::CACHE_TTL);

            return $coords;
        }

        // Only a real "no match" is remembered. A lookup that failed must not
        // hide the address for an hour once Mapbox is back.
        if (! $this->failed) {
            cache()->save($cacheKey, 'none', HOUR);
        }

        return null;
    }

    /**
     * Permanent, because the address text it returns is saved if the owner
     * accepts it after dragging the pin.
     *
     * @return array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float,precision:string}|null
     */
    public function reverse(float $lat, float $lng): ?array
    {
        $this->failed = false;
        if (! NominatimGeocoder::isPlausible($lat, $lng)) {
            return null;
        }

        $cacheKey = 'mapbox_reverse_' . md5(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''));
        $cached   = cache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'none') {
            return null;
        }

        $features = $this->request(self::REVERSE_ENDPOINT, [
            'latitude'  => number_format($lat, 7, '.', ''),
            'longitude' => number_format($lng, 7, '.', ''),
            'country'   => 'za',
            'language'  => 'en',
            'types'     => self::TYPES,
            'permanent' => 'true',
        ], 'reverse');

        $mapped = isset($features[0]) ? $this->mapFeature($features[0]) : null;
        if ($mapped === null) {
            if (! $this->failed) {
                cache()->save($cacheKey, 'none', HOUR);
            }

            return null;
        }

        // The dragged point is the truth, not the feature's own centre.
        $mapped['lat'] = $lat;
        $mapped['lng'] = $lng;

        cache()->save($cacheKey, $mapped, self::CACHE_TTL);

        return $mapped;
    }

    /**
     * One API call. Returns the response's features, [] on any failure (logged,
     * and recorded in $failed so callers can tell it from an empty answer).
     *
     * Protected as a test seam, like NominatimGeocoder::request(): every call
     * to Mapbox goes through here, so overriding it lets the tests see exactly
     * what was asked, including whether it was permanent.
     *
     * @param array<string,mixed> $query without the access token
     *
     * @return list<array<string,mixed>>
     */
    protected function request(string $endpoint, array $query, string $context): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = service('curlrequest', [
                    'timeout'         => self::TIMEOUT,
                    'connect_timeout' => self::TIMEOUT,
                    'http_errors'     => false,
                ], null, null, false)->get($endpoint, [
                    'query' => $query + ['access_token' => $this->token],
                ]);

                $status = $response->getStatusCode();
                if ($status === 200) {
                    $decoded  = json_decode($response->getBody(), true);
                    $features = is_array($decoded) && is_array($decoded['features'] ?? null) ? $decoded['features'] : [];

                    return array_values(array_filter($features, 'is_array'));
                }

                $retryable = $status === 429 || $status >= 500;
                log_message(
                    $retryable ? 'warning' : 'error',
                    'MapboxGeocoder: HTTP ' . $status . ' for ' . $context . ' (attempt ' . $attempt . ')'
                );
                if (! $retryable) {
                    // 401/403 is a bad or URL-restricted token, or a paused
                    // account. Retrying can't fix it.
                    $this->failed = true;

                    return [];
                }
            } catch (\Throwable $e) {
                log_message('warning', 'MapboxGeocoder: '
                    . str_replace($this->token, '[token]', $e->getMessage())
                    . ' for ' . $context . ' (attempt ' . $attempt . ')');
            }
        }

        $this->failed = true;

        return [];
    }

    /**
     * Should we believe this match? Structured input can quietly settle for a
     * same-named street in another city, and Mapbox says so in match_code.
     *
     * @param array{lat:float,lng:float,province:string,precision:string} $mapped
     * @param array<string,mixed>                                         $feature
     */
    private function accept(array $mapped, array $feature, string $province): bool
    {
        if (! NominatimGeocoder::isPlausible($mapped['lat'], $mapped['lng'])) {
            return false;
        }

        if ($province !== '' && $mapped['province'] !== '' && strcasecmp($mapped['province'], $province) !== 0) {
            return false;
        }

        $match = $feature['properties']['match_code'] ?? null;
        if (is_array($match)) {
            if (($match['confidence'] ?? '') === 'low') {
                return false;
            }
            foreach (['region', 'place'] as $component) {
                if (($match[$component] ?? '') === 'mismatched') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * One GeoJSON feature → the row shape GeocoderInterface returns.
     *
     * @param array<string,mixed> $feature
     *
     * @return array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float,precision:string}|null
     */
    private function mapFeature(array $feature): ?array
    {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $type  = (string) ($props['feature_type'] ?? '');
        $name  = trim((string) ($props['name'] ?? ''));

        // A feature is not listed in its own context. Adding it makes "which
        // locality is this?" the same lookup for a suburb as for an address in it.
        $context = is_array($props['context'] ?? null) ? $props['context'] : [];
        if ($type !== '' && ! isset($context[$type])) {
            $context[$type] = ['name' => $name];
        }
        $part = static fn (string $key): string => trim((string) ($context[$key]['name'] ?? ''));

        $addressLine = in_array($type, ['address', 'street'], true) ? $name : '';
        $suburb      = $part('locality') !== '' ? $part('locality') : $part('neighborhood');
        $city        = $part('place');

        if ($addressLine === '' && $suburb === '' && $city === '') {
            return null;
        }

        $coords = is_array($props['coordinates'] ?? null) ? $props['coordinates'] : [];
        $point  = is_array($feature['geometry']['coordinates'] ?? null) ? $feature['geometry']['coordinates'] : [];
        $lat    = (float) ($coords['latitude'] ?? $point[1] ?? 0);
        $lng    = (float) ($coords['longitude'] ?? $point[0] ?? 0);

        return [
            'label'        => trim((string) ($props['full_address'] ?? $props['place_formatted'] ?? $name)),
            'address_line' => $addressLine,
            'suburb'       => $suburb,
            'city'         => $city,
            'province'     => DirectoryService::normaliseProvince($part('region')),
            'postal_code'  => $part('postcode'),
            'lat'          => $lat,
            'lng'          => $lng,
            'precision'    => $this->precision($type, (string) ($coords['accuracy'] ?? '')),
        ];
    }

    /**
     * Mapbox's feature type and accuracy → our exact|street|suburb|city scale,
     * which decides map zoom and the "approximate pin" warning.
     */
    private function precision(string $type, string $accuracy): string
    {
        return match ($type) {
            'address'                 => in_array($accuracy, ['rooftop', 'parcel', 'point'], true) ? 'exact' : 'street',
            'street'                  => 'street',
            'neighborhood', 'locality' => 'suburb',
            default                   => 'city',
        };
    }

    /** @param mixed $v */
    private function clean($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
