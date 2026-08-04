<?php

namespace App\Libraries\Geocoding;

use App\Services\DirectoryService;

/**
 * Free geocoding via OpenStreetMap's Nominatim — no API key, no billing
 * account. Never throws — callers must always get a usable result (null or
 * []) even if Nominatim is slow, down, or returns nothing useful. Non-200
 * responses are logged (not just thrown exceptions) so a rate-limit or block
 * from Nominatim is diagnosable after the fact.
 *
 * `geocodeParts()` deliberately does NOT use Nominatim's free-form `q=`
 * search. Free-form matching is all-or-nothing: a single token OSM doesn't
 * recognise ("Extension 2", a suburb missing from OSM's thin South African
 * coverage, or a plain typo like "Johanesburg") returns ZERO results rather
 * than a nearest match — which is how listings ended up with NULL
 * coordinates and therefore no map on their public page. The structured
 * endpoint instead ignores components it can't match, so it degrades to a
 * street- or city-level hit rather than failing outright.
 *
 * That leniency cuts both ways: asked for `street=Delta Road` +
 * `postalcode=2196` Nominatim will confidently return a Delta Road in Cape
 * Town, 1200km from the Johannesburg postal code it was given. Every
 * candidate is therefore run past accept() before it is trusted, and the
 * precision actually achieved is reported back so the caller can zoom the
 * map to match and label it approximate.
 */
class NominatimGeocoder implements GeocoderInterface
{
    private const ENDPOINT         = 'https://nominatim.openstreetmap.org/search';
    private const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';
    private const TIMEOUT          = 8;
    private const MIN_QUERY_LEN    = 3;

    /**
     * How many times one lookup may be attempted before giving up.
     *
     * Three, not more: this runs inside a web request during signup, and each
     * attempt already costs at least the 1.1s of rate-limit spacing below plus
     * the backoff. A fourth try would risk the page timing out to rescue a
     * service that is evidently down, and `spark directory:geocode` exists to
     * pick those listings up later anyway.
     */
    private const MAX_ATTEMPTS = 3;

    /** Multiplied by the attempt number — 0.5s, then 1s. Microseconds. */
    private const RETRY_BACKOFF_US = 500_000;

    /** Nominatim asks for at most ~1 request/second. Microseconds between calls. */
    private const MIN_INTERVAL_US = 1_100_000;

    /** Successful lookups are stable for a long time; addresses don't move. */
    private const CACHE_TTL = 30 * DAY;

    /** Rough bounding box for South Africa: [minLat, maxLat, minLng, maxLng]. */
    private const SA_BBOX = [-35.0, -22.0, 16.0, 33.5];

    /**
     * How far a street-level match may sit from the listing's own suburb or
     * city before we stop believing it.
     *
     * The looser ladder steps drop the city (a typo in it shouldn't sink an
     * otherwise findable street), which without this leaves them free to match
     * a same-named street anywhere in the province: "1 Main Street" in Gauteng
     * resolves to Marshalltown, Johannesburg, some 55km from the Menlo Park,
     * Pretoria one. Province and country checks can't catch that.
     *
     * Deliberately generous rather than tight. It only has to separate towns,
     * and a suburb anchor can legitimately sit well away from the far edge of a
     * large city's street grid — a small radius would reject good matches.
     */
    private const MAX_ANCHOR_DISTANCE_M = 30_000;

    /** @var float Unix timestamp (float seconds) of the last upstream call in this process. */
    private static float $lastRequestAt = 0.0;

    /**
     * Resolve a listing's address fields to coordinates, trying progressively
     * looser queries until one is accepted.
     *
     * @param array<string,mixed> $parts address_line, suburb, city, province, postal_code
     *
     * @return array{lat:float,lng:float,precision:string}|null
     *   precision is one of exact|street|suburb|city.
     */
    public function geocodeParts(array $parts): ?array
    {
        $street   = $this->clean($parts['address_line'] ?? '');
        $suburb   = $this->clean($parts['suburb'] ?? '');
        $city     = $this->clean($parts['city'] ?? '');
        $province = $this->clean($parts['province'] ?? '');
        $postal   = $this->clean($parts['postal_code'] ?? '');

        if ($street === '' && $suburb === '' && $city === '' && $postal === '') {
            return null;
        }

        $cacheKey = 'geocode_' . md5(strtolower(implode('|', [$street, $suburb, $city, $province, $postal])));
        $cached   = cache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'none') {
            return null;
        }

        // People routinely paste their whole address into the one "Address"
        // field ("21 Delta Road, Extension 2, Eltonhill"). Nominatim's street
        // parameter wants only the number and road, and the trailing detail is
        // enough to sink an otherwise findable street — so the ladder retries
        // with just the first comma-separated segment before giving up on
        // street level. Empty when there's nothing to trim, which skips those
        // steps entirely.
        $streetHead = trim(explode(',', $street)[0]);
        if ($streetHead === $street) {
            $streetHead = '';
        }

        // Loosest last. Dropping the postal code is deliberate rather than a
        // last resort: South African postcodes in OSM are unreliable (the
        // correct Delta Road comes back tagged 2116 against an entered 2196),
        // so a postal mismatch must not veto an otherwise-good street hit.
        // Steps that name the suburb come before the province-wide ones: a
        // mistyped city ("Johanesburg") should degrade to the listing's own
        // suburb, not to every street of that name in the province.
        $ladder = [
            ['params' => ['street' => $street, 'city' => $city, 'state' => $province, 'postalcode' => $postal], 'needs' => $street, 'precision' => 'street'],
            ['params' => ['street' => $street, 'city' => $city, 'state' => $province], 'needs' => $street, 'precision' => 'street'],
            ['params' => ['street' => $street, 'city' => $suburb, 'state' => $province], 'needs' => $street, 'precision' => 'street'],
            ['params' => ['street' => $streetHead, 'city' => $city, 'state' => $province, 'postalcode' => $postal], 'needs' => $streetHead, 'precision' => 'street'],
            ['params' => ['street' => $streetHead, 'city' => $city, 'state' => $province], 'needs' => $streetHead, 'precision' => 'street'],
            ['params' => ['street' => $streetHead, 'city' => $suburb, 'state' => $province], 'needs' => $streetHead, 'precision' => 'street'],
            ['params' => ['street' => $street, 'state' => $province], 'needs' => $street, 'precision' => 'street'],
            ['params' => ['street' => $streetHead, 'state' => $province], 'needs' => $streetHead, 'precision' => 'street'],
            ['params' => ['city' => $suburb, 'state' => $province], 'needs' => $suburb, 'precision' => 'suburb'],
            ['params' => ['city' => $city, 'state' => $province], 'needs' => $city, 'precision' => 'city'],
            ['params' => ['postalcode' => $postal, 'state' => $province], 'needs' => $postal, 'precision' => 'city'],
        ];

        // Where this listing's own suburb (or failing that, its city) sits. Any
        // street match has to land near it. Resolved lazily so listings without
        // a street never pay for it, and memoised for the loop below.
        $anchor      = null;
        $anchorTried = false;

        $seen = [];
        foreach ($ladder as $step) {
            if ($step['needs'] === '') {
                continue;
            }
            $params = array_filter($step['params'], static fn ($v) => $v !== '');
            if ($params === []) {
                continue;
            }
            // Successive steps collapse to the same query once an optional
            // component was empty — don't spend a second call on a repeat.
            $signature = serialize($params);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $result = $this->request($params);
            if ($result === null || ! $this->accept($result, $province)) {
                continue;
            }

            if ($step['precision'] === 'street') {
                if (! $anchorTried) {
                    $anchorTried = true;
                    $anchor      = $this->localityAnchor($suburb, $city, $province);
                }
                if (! $this->nearAnchor($result, $anchor)) {
                    log_message('info', 'NominatimGeocoder: rejected a street match too far from '
                        . ($suburb !== '' ? $suburb : $city) . ' — ' . ($result['display_name'] ?? ''));

                    continue;
                }
            }

            $precision = $step['precision'];
            if ($precision === 'street' && $this->hasHouseNumber($result)) {
                $precision = 'exact';
            }

            $coords = [
                'lat'       => (float) $result['lat'],
                'lng'       => (float) $result['lon'],
                'precision' => $precision,
                // What OSM thinks is at that point. Stored as geocoded_address
                // so a wrong pin is diagnosable by reading the row, instead of
                // by re-running the lookup and hoping it fails the same way.
                'label'     => trim((string) ($result['display_name'] ?? '')),
            ];
            cache()->save($cacheKey, $coords, self::CACHE_TTL);

            return $coords;
        }

        // Remember the miss too, but briefly — OSM data improves, and a
        // rate-limited run shouldn't poison the result for a month.
        cache()->save($cacheKey, 'none', HOUR);

        return null;
    }

    /**
     * Are these coordinates plausibly a South African address? Used to vet
     * lat/lng posted by the browser (captured when the user picked an
     * autocomplete suggestion) before they are trusted in place of a lookup.
     */
    public static function isPlausible(float $lat, float $lng): bool
    {
        [$minLat, $maxLat, $minLng, $maxLng] = self::SA_BBOX;

        return $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
    }

    /**
     * Structured suggestions for interactive autocomplete — South Africa
     * only, matching this directory's scope. Free-form `q=` is the right call
     * here (unlike geocodeParts): the user is mid-typing, so there are no
     * discrete components to map onto structured parameters yet.
     *
     * @return list<array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float,precision:string}>
     */
    public function suggest(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LEN) {
            return [];
        }

        $results = $this->call([
            'format'         => 'json',
            'addressdetails' => 1,
            'countrycodes'   => 'za',
            'limit'          => max(1, min($limit, 10)),
            'q'              => $query,
        ], 'suggest("' . $query . '")');

        if ($results === null) {
            return [];
        }

        $suggestions = [];
        foreach (array_values($results) as $result) {
            $mapped = $this->mapSuggestion(is_array($result) ? $result : []);
            if ($mapped !== null) {
                $suggestions[] = $mapped;
            }
        }

        return $suggestions;
    }

    /**
     * The address at a point, for when someone drags the map marker.
     *
     * Deliberately does NOT overwrite anything on its own. A dragged pin is the
     * most trustworthy thing this app has — it is a human saying "the geocoder
     * is wrong, the business is here" — so the address OSM reports back is only
     * ever a suggestion the user accepts or discards. Silently rewriting their
     * address from it would be the geocoder overruling the correction it just
     * received.
     *
     * @return array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float,precision:string}|null
     */
    public function reverse(float $lat, float $lng): ?array
    {
        if (! self::isPlausible($lat, $lng)) {
            return null;
        }

        // 7dp is roughly a centimetre — far finer than anyone can drag, so this
        // is what the marker writes and what the cache key can safely round to.
        $cacheKey = 'reverse_' . md5(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''));
        $cached   = cache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'none') {
            return null;
        }

        $result = $this->reverseRequest($lat, $lng);
        $mapped = $result === null ? null : $this->mapSuggestion($result);

        if ($mapped === null) {
            cache()->save($cacheKey, 'none', HOUR);

            return null;
        }

        // The point the user dragged to is the truth here, not whatever centre
        // OSM reports for the feature it matched.
        $mapped['lat'] = $lat;
        $mapped['lng'] = $lng;

        cache()->save($cacheKey, $mapped, self::CACHE_TTL);

        return $mapped;
    }

    /**
     * One structured lookup. Returns the first raw Nominatim result, or null.
     *
     * Protected rather than private purely as a test seam: it is the single
     * point where this class talks to Nominatim, so overriding it lets the
     * ladder and the distance gate be tested against canned results instead of
     * a live third-party service.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>|null
     */
    protected function request(array $params): ?array
    {
        $results = $this->call($params + [
            'format'         => 'json',
            'addressdetails' => 1,
            'countrycodes'   => 'za',
            'limit'          => 1,
        ], 'geocode(' . http_build_query($params) . ')');

        if ($results === null || $results === []) {
            return null;
        }

        $first = array_values($results)[0] ?? null;

        return is_array($first) && isset($first['lat'], $first['lon']) ? $first : null;
    }

    /**
     * One reverse lookup. Returns the raw Nominatim result, or null.
     *
     * Protected for the same reason request() is — a test seam, so the mapping
     * and the bounding-box rules can be exercised without a live service.
     *
     * @return array<string,mixed>|null
     */
    protected function reverseRequest(float $lat, float $lng): ?array
    {
        $result = $this->call([
            'format'         => 'json',
            'addressdetails' => 1,
            'lat'            => number_format($lat, 7, '.', ''),
            'lon'            => number_format($lng, 7, '.', ''),
            // 18 is building level. Asking for less returns a suburb centroid,
            // which is useless as a suggestion for a pin someone placed on a
            // specific door.
            'zoom'           => 18,
        ], sprintf('reverse(%.7f, %.7f)', $lat, $lng), self::REVERSE_ENDPOINT);

        // /reverse answers with a single object, not the list /search returns.
        // A point in the middle of the ocean comes back as
        // {"error": "Unable to geocode"} with a 200, so a missing lat is the
        // real "nothing here" signal rather than the status code.
        return is_array($result) && isset($result['lat'], $result['lon']) ? $result : null;
    }

    /**
     * Shared HTTP plumbing: rate-limit spacing, User-Agent, retry, error logging.
     *
     * Retries only what is worth retrying — a timeout, a dropped connection, a
     * 5xx, or a 429. Those are Nominatim being momentarily unavailable, and
     * giving up on the first one is how a listing ends up permanently
     * un-geocoded because of a two-second blip during signup.
     *
     * A 200 with an empty list is NOT retried. That is a real answer — OSM
     * genuinely has no record of the address — and asking again can only
     * produce the same nothing while spending someone else's donated capacity.
     *
     * Returns the decoded body as-is rather than normalising it: /search answers
     * with a list, /reverse with a single object, and flattening both into the
     * same shape here would lose the reverse result's keys.
     *
     * @param array<string,mixed> $query
     *
     * @return array<mixed>|null null on any failure (logged); [] on a valid empty response.
     */
    private function call(array $query, string $context, string $endpoint = self::ENDPOINT): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $outcome = $this->attempt($query, $context, $endpoint, $attempt);

            if ($outcome['done']) {
                return $outcome['result'];
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                // Linear backoff on top of the 1.1s spacing respectRateLimit()
                // already enforces. Nominatim's own guidance is to back off, not
                // to hammer, and this runs inside a web request — a long
                // exponential wait would just time the page out instead.
                usleep(self::RETRY_BACKOFF_US * $attempt);
            }
        }

        log_message('error', 'NominatimGeocoder: gave up after ' . self::MAX_ATTEMPTS . ' attempts for ' . $context);

        return null;
    }

    /**
     * One HTTP attempt.
     *
     * @param array<string,mixed> $query
     *
     * @return array{done:bool,result:array<mixed>|null}
     *   done=false means "retryable failure, try again"; done=true means this is
     *   the answer, successful or not.
     */
    private function attempt(array $query, string $context, string $endpoint, int $attempt): array
    {
        $this->respectRateLimit();

        try {
            $client = service('curlrequest', [
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'http_errors'     => false,
            ]);

            $response = $client->get($endpoint, [
                'query'   => $query,
                'headers' => ['User-Agent' => $this->userAgent()],
            ]);

            $status = $response->getStatusCode();

            if ($status !== 200) {
                $retryable = $status === 429 || $status >= 500;
                log_message(
                    $retryable ? 'warning' : 'error',
                    'NominatimGeocoder: Nominatim returned HTTP ' . $status . ' for ' . $context
                        . ($retryable ? ' (attempt ' . $attempt . ')' : '')
                );

                return ['done' => ! $retryable, 'result' => null];
            }

            $decoded = json_decode($response->getBody(), true);
            if (! is_array($decoded)) {
                // Nominatim serves an HTML throttle page rather than JSON when
                // it decides a client is abusing the service — so this is a
                // rate limit wearing a disguise, and worth backing off from.
                log_message('warning', 'NominatimGeocoder: non-JSON response from Nominatim for ' . $context
                    . ' (attempt ' . $attempt . ')');

                return ['done' => false, 'result' => null];
            }

            return ['done' => true, 'result' => $decoded];
        } catch (\Throwable $e) {
            // Timeout, DNS, dropped connection — transport, not an answer.
            log_message('warning', 'NominatimGeocoder: ' . $e->getMessage() . ' for ' . $context
                . ' (attempt ' . $attempt . ')');

            return ['done' => false, 'result' => null];
        }
    }

    /**
     * Would this candidate be a lie? The structured endpoint silently drops
     * components it can't match, so a query can come back with a confident
     * result from the wrong end of the country.
     *
     * @param array<string,mixed> $result
     */
    private function accept(array $result, string $province): bool
    {
        $lat = (float) $result['lat'];
        $lng = (float) $result['lon'];

        [$minLat, $maxLat, $minLng, $maxLng] = self::SA_BBOX;
        if ($lat < $minLat || $lat > $maxLat || $lng < $minLng || $lng > $maxLng) {
            return false;
        }

        if ($province === '') {
            return true;
        }

        $addr  = is_array($result['address'] ?? null) ? $result['address'] : [];
        $state = trim((string) ($addr['state'] ?? ''));
        if ($state === '') {
            return true; // Nothing to contradict — the bbox check already passed.
        }

        return strcasecmp($state, $province) === 0;
    }

    /**
     * Roughly where this listing's locality is, used to sanity-check street
     * matches. Prefers the suburb — it is far more specific than the city, and
     * it is also the field most likely to be spelled correctly, since the city
     * is the one people fat-finger.
     *
     * Returns null when there is no locality to anchor on, or when the anchor
     * itself can't be resolved; callers treat that as "no opinion" and fall
     * back to the province/bbox checks alone rather than rejecting everything.
     *
     * @return array{0:float,1:float}|null
     */
    private function localityAnchor(string $suburb, string $city, string $province): ?array
    {
        foreach ([$suburb, $city] as $locality) {
            if ($locality === '') {
                continue;
            }

            $key    = 'geocode_anchor_' . md5(strtolower($locality . '|' . $province));
            $cached = cache($key);
            if (is_array($cached)) {
                return $cached;
            }
            if ($cached === 'none') {
                continue;
            }

            $params = array_filter(['city' => $locality, 'state' => $province], static fn ($v) => $v !== '');
            $result = $this->request($params);

            if ($result !== null && $this->accept($result, $province)) {
                $anchor = [(float) $result['lat'], (float) $result['lon']];
                cache()->save($key, $anchor, self::CACHE_TTL);

                return $anchor;
            }

            cache()->save($key, 'none', HOUR);
        }

        return null;
    }

    /**
     * @param array<string,mixed>          $result
     * @param array{0:float,1:float}|null  $anchor
     */
    private function nearAnchor(array $result, ?array $anchor): bool
    {
        if ($anchor === null) {
            return true; // nothing to compare against — don't invent a veto
        }

        return $this->distanceMetres(
            (float) $result['lat'],
            (float) $result['lon'],
            $anchor[0],
            $anchor[1]
        ) <= self::MAX_ANCHOR_DISTANCE_M;
    }

    /** Great-circle distance in metres (haversine). */
    private function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6_371_000;
        $p1    = deg2rad($lat1);
        $p2    = deg2rad($lat2);
        $dp    = deg2rad($lat2 - $lat1);
        $dl    = deg2rad($lng2 - $lng1);

        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($a)));
    }

    /** @param array<string,mixed> $result */
    private function hasHouseNumber(array $result): bool
    {
        $addr = is_array($result['address'] ?? null) ? $result['address'] : [];

        return trim((string) ($addr['house_number'] ?? '')) !== '';
    }

    /**
     * Nominatim's usage policy allows roughly one request per second. Sleeping
     * here rather than at the call sites keeps the backfill command honest
     * without every caller having to remember.
     */
    private function respectRateLimit(): void
    {
        $elapsed = (microtime(true) - self::$lastRequestAt) * 1_000_000;
        if (self::$lastRequestAt > 0.0 && $elapsed < self::MIN_INTERVAL_US) {
            usleep((int) (self::MIN_INTERVAL_US - $elapsed));
        }
        self::$lastRequestAt = microtime(true);
    }

    /**
     * @param array<string,mixed> $result One raw Nominatim /search result.
     *
     * @return array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float,precision:string}|null
     *   null when the result carries no usable road/suburb/city info.
     */
    private function mapSuggestion(array $result): ?array
    {
        $addr = is_array($result['address'] ?? null) ? $result['address'] : [];

        $road        = trim((string) ($addr['road'] ?? ''));
        $houseNumber = trim((string) ($addr['house_number'] ?? ''));
        $addressLine = trim($houseNumber !== '' ? $houseNumber . ' ' . $road : $road);

        $suburb = trim((string) ($addr['suburb'] ?? $addr['neighbourhood'] ?? ''));
        $city   = trim((string) ($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? ''));

        if ($addressLine === '' && $suburb === '' && $city === '') {
            return null;
        }

        $province = DirectoryService::normaliseProvince((string) ($addr['state'] ?? ''));

        // How specific is the thing the user would be picking? A suggestion for
        // a whole road is a point somewhere along it, not a doorstep — saying so
        // keeps the map from zooming to street-corner detail on a street-wide
        // guess.
        if ($houseNumber !== '') {
            $precision = 'exact';
        } elseif ($road !== '') {
            $precision = 'street';
        } elseif ($suburb !== '') {
            $precision = 'suburb';
        } else {
            $precision = 'city';
        }

        return [
            'label'        => (string) ($result['display_name'] ?? $addressLine),
            'address_line' => $addressLine,
            'suburb'       => $suburb,
            'city'         => $city,
            'province'     => $province,
            'postal_code'  => trim((string) ($addr['postcode'] ?? '')),
            'lat'          => (float) ($result['lat'] ?? 0),
            'lng'          => (float) ($result['lon'] ?? 0),
            'precision'    => $precision,
        ];
    }

    /** @param mixed $v */
    private function clean($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private function userAgent(): string
    {
        $site  = config('Directory')->siteName();
        $email = config('Directory')->adminEmail();

        return trim($site . '/1.0' . ($email !== '' ? ' (contact: ' . $email . ')' : ''));
    }
}
