<?php

namespace App\Libraries\Geocoding;

/**
 * Mapbox first, Nominatim when Mapbox can't answer at all.
 *
 * Without this, a paused Mapbox account or a revoked token left every new or
 * edited listing without a pin until someone noticed. That happened in Sep 2026,
 * when the demo account hit its cap. Nominatim is free and keyless, so it is a
 * safe second opinion for the permanent lookups.
 *
 * It only steps in when MapboxGeocoder::failed() says the call never got an
 * answer. A clean "no match" from Mapbox is an answer, and asking Nominatim
 * then would only put its looser OpenStreetMap guesses on rows Mapbox rejected
 * on purpose.
 *
 * suggest() never falls back: Nominatim's usage policy forbids autocomplete, so
 * its suggest() is always empty anyway.
 */
final class FallbackGeocoder implements GeocoderInterface
{
    public function __construct(
        private readonly MapboxGeocoder $primary,
        private readonly GeocoderInterface $secondary,
    ) {
    }

    public function suggest(string $query, int $limit = 5): array
    {
        return $this->primary->suggest($query, $limit);
    }

    public function geocodeParts(array $parts): ?array
    {
        $coords = $this->primary->geocodeParts($parts);
        if ($coords !== null || ! $this->primary->failed()) {
            return $coords;
        }

        log_message('warning', 'FallbackGeocoder: Mapbox failed for geocode, asking Nominatim');

        return $this->secondary->geocodeParts($parts);
    }

    public function reverse(float $lat, float $lng): ?array
    {
        $row = $this->primary->reverse($lat, $lng);
        if ($row !== null || ! $this->primary->failed()) {
            return $row;
        }

        log_message('warning', 'FallbackGeocoder: Mapbox failed for reverse, asking Nominatim');

        return $this->secondary->reverse($lat, $lng);
    }
}
