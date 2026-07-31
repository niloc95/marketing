<?php

namespace App\Libraries;

use App\Services\DirectoryService;

/**
 * Free geocoding via OpenStreetMap's Nominatim — no API key, no billing
 * account. Their usage policy requires a descriptive User-Agent and roughly
 * one request per second. `geocode()` only runs once per listing save (and
 * only when the address actually changed on an edit); `suggest()` backs the
 * interactive address-autocomplete endpoint, which is debounced client-side
 * and throttled server-side (see AddressSuggest::index()) to stay within
 * that policy too. Never throws — callers must always get a usable result
 * (null or []) even if Nominatim is slow, down, or returns nothing useful.
 */
class AddressGeocoder
{
    private const ENDPOINT      = 'https://nominatim.openstreetmap.org/search';
    private const TIMEOUT       = 5;
    private const MIN_QUERY_LEN = 3;

    /**
     * @return array{lat:float,lng:float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        try {
            $client = service('curlrequest', [
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'http_errors'     => false,
            ]);

            $response = $client->get(self::ENDPOINT, [
                'query'   => ['format' => 'json', 'limit' => 1, 'q' => $address],
                'headers' => ['User-Agent' => $this->userAgent()],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $results = json_decode($response->getBody(), true);
            if (! is_array($results) || $results === [] || ! isset($results[0]['lat'], $results[0]['lon'])) {
                return null;
            }

            return ['lat' => (float) $results[0]['lat'], 'lng' => (float) $results[0]['lon']];
        } catch (\Throwable $e) {
            log_message('error', 'AddressGeocoder: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Structured suggestions for interactive autocomplete — South Africa
     * only, matching this directory's scope.
     *
     * @return list<array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float}>
     */
    public function suggest(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LEN) {
            return [];
        }

        try {
            $client = service('curlrequest', [
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'http_errors'     => false,
            ]);

            $response = $client->get(self::ENDPOINT, [
                'query' => [
                    'format'        => 'json',
                    'addressdetails' => 1,
                    'countrycodes'  => 'za',
                    'limit'         => max(1, min($limit, 10)),
                    'q'             => $query,
                ],
                'headers' => ['User-Agent' => $this->userAgent()],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $results = json_decode($response->getBody(), true);
            if (! is_array($results)) {
                return [];
            }

            $suggestions = [];
            foreach ($results as $result) {
                $mapped = $this->mapSuggestion(is_array($result) ? $result : []);
                if ($mapped !== null) {
                    $suggestions[] = $mapped;
                }
            }
            return $suggestions;
        } catch (\Throwable $e) {
            log_message('error', 'AddressGeocoder: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array<string,mixed> $result One raw Nominatim /search result.
     * @return array{label:string,address_line:string,suburb:string,city:string,province:string,postal_code:string,lat:float,lng:float}|null
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

        $state    = trim((string) ($addr['state'] ?? ''));
        $province = '';
        foreach (DirectoryService::SA_PROVINCES as $known) {
            if (strcasecmp($known, $state) === 0) {
                $province = $known;
                break;
            }
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
        ];
    }

    private function userAgent(): string
    {
        $site  = config('Directory')->siteName();
        $email = config('Directory')->adminEmail();
        return trim($site . '/1.0' . ($email !== '' ? ' (contact: ' . $email . ')' : ''));
    }
}
