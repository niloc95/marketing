<?php

if (! function_exists('map_directions_url')) {
    /**
     * Deep link that opens the visitor's own navigation app in directions mode,
     * with this listing as the destination.
     *
     * This is the one place the app deliberately hands off to a commercial
     * service, and it is not a dependency: it is a plain URL, no API, no key,
     * no request from this server. On a phone the OS intercepts it and opens
     * whichever maps app is installed — Google Maps, Waze, Apple Maps — which is
     * exactly what someone wants when they tap "Get directions". Rendering our
     * own route with Leaflet would be strictly worse: no live traffic, no voice,
     * no handover to the device's navigation.
     *
     * Uses the `/maps/dir/` scheme rather than `/maps/search/`. Search only
     * drops a pin, leaving the visitor to tap "Directions" themselves; dir goes
     * straight to routing.
     *
     * Coordinates are used only when they are actually trustworthy — see
     * map_destination().
     *
     * @param array<string,mixed> $listing
     *
     * @return string '' when there is nothing to navigate to.
     */
    function map_directions_url(array $listing): string
    {
        $destination = map_destination($listing);

        return $destination === ''
            ? ''
            : 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($destination);
    }
}

if (! function_exists('map_destination')) {
    /**
     * What to hand the visitor's navigation app: coordinates, or address text.
     *
     * Coordinates only win when someone or something actually pinpointed the
     * place — a hand-dragged pin ('manual') or a match that carried a house
     * number ('exact'). Anything looser is a street, suburb or city centroid,
     * and routing to that throws away a good address in favour of a bad point.
     * OpenStreetMap has no house numbers on plenty of South African streets:
     * Fort Street in Birnam is mapped as bare segments spanning 636m, so its
     * "street-level" coordinate can sit ~600m from the actual door and sends
     * people to the wrong end of the road. Handing over the text instead lets
     * the navigation app apply its own geocoder, which for house numbers is
     * usually better than the one that produced our loose pin.
     *
     * @param array<string,mixed> $listing
     *
     * @return string '' when the listing has neither usable coordinates nor an address.
     */
    function map_destination(array $listing): string
    {
        $lat       = $listing['latitude'] ?? null;
        $lng       = $listing['longitude'] ?? null;
        $precision = $listing['geocode_precision'] ?? null;

        $hasCoords = $lat !== null && $lng !== null && (float) $lat !== 0.0 && (float) $lng !== 0.0;
        $pinpointed = in_array($precision, ['manual', 'exact'], true);

        $address = map_address_text($listing);

        if ($hasCoords && ($pinpointed || $address === '')) {
            return $lat . ',' . $lng;
        }

        return $address !== '' ? $address : ($hasCoords ? $lat . ',' . $lng : '');
    }
}

if (! function_exists('map_address_text')) {
    /**
     * The listing's address as one human-readable line.
     *
     * One definition, used by the directions link, the map popup and the
     * geocoder's own query assembly, so they can never disagree about what this
     * business's address is.
     *
     * @param array<string,mixed> $listing
     */
    function map_address_text(array $listing, bool $withCountry = false): string
    {
        $parts = [
            trim((string) ($listing['address_line'] ?? '')),
            trim((string) ($listing['address_line_2'] ?? '')),
            trim((string) ($listing['suburb'] ?? '')),
            trim((string) ($listing['city'] ?? '')),
            trim((string) ($listing['province'] ?? '')),
            trim((string) ($listing['postal_code'] ?? '')),
        ];

        if ($withCountry) {
            $parts[] = trim((string) ($listing['country'] ?? ''));
        }

        return trim(implode(', ', array_filter($parts)));
    }
}

if (! function_exists('map_point')) {
    /**
     * Everything a Leaflet map needs to show this listing, or null if there is
     * nothing worth drawing.
     *
     * Replaces the old iframe embed. The map is now a real Leaflet map over
     * OpenStreetMap tiles, so this returns *data* rather than a third party's
     * URL — which also means the profile map, the search results map and the
     * form's picker can all be fed from one definition.
     *
     * Coordinates are mandatory here, unlike the old Google embed which could
     * work from address text alone. A listing that never geocoded shows no map;
     * `spark directory:geocode` and the owner's own pin picker are how that gets
     * fixed, and geocoding_status makes those listings findable.
     *
     * @param array<string,mixed> $listing
     *
     * @return array{lat:float,lng:float,zoom:int,approximate:bool,label:string}|null
     */
    function map_point(array $listing): ?array
    {
        $lat = $listing['latitude'] ?? null;
        $lng = $listing['longitude'] ?? null;

        if ($lat === null || $lng === null || (float) $lat === 0.0 && (float) $lng === 0.0) {
            return null;
        }

        // Zoom to the accuracy we actually achieved — showing a suburb-level
        // guess at doorstep zoom reads as a precise pin in the wrong place.
        // NULL precision means the row predates that column; keep a
        // middle-ground zoom.
        $precision = $listing['geocode_precision'] ?? null;
        $zoom      = [
            'manual' => 17,
            'exact'  => 17,
            'street' => 16,
            'suburb' => 14,
            'city'   => 12,
        ][$precision] ?? 15;

        return [
            'lat'  => (float) $lat,
            'lng'  => (float) $lng,
            'zoom' => $zoom,
            // A pin someone placed by hand is the most accurate thing we have —
            // never label it a guess.
            'approximate' => $precision !== null && ! in_array($precision, ['exact', 'manual'], true),
            'label'       => map_address_text($listing),
        ];
    }
}
