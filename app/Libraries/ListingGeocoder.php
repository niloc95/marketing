<?php

namespace App\Libraries;

use App\Libraries\Geocoding\GeocoderInterface;
use App\Libraries\Geocoding\NominatimGeocoder;

/**
 * Decides *whether* a listing needs geocoding and *where* its pin comes from,
 * so the owner form and the admin form can't drift apart on the policy. The
 * raw lookup work lives behind GeocoderInterface — Mapbox or Nominatim,
 * depending on configuration; this is the surrounding bookkeeping, and it is
 * the same either way.
 *
 * Three sources of coordinates, in order of preference:
 *
 * 1. Coordinates posted by the browser: a pin the user dragged, or the point
 *    /address-locate returned right after they picked a suggestion (a
 *    permanent Mapbox lookup, so it may be stored). Either way there's nothing
 *    to re-derive and no second API call to spend.
 * 2. The structured lookup ladder, for addresses typed by hand (or by anyone
 *    whose suburb OSM has never heard of, which in South Africa is common).
 * 3. Nothing — coordinates are cleared rather than left stale, because an old
 *    pin on a new address is worse than no pin at all.
 */
final class ListingGeocoder
{
    /**
     * Address components that, when changed, invalidate an existing pin.
     *
     * address_line_2 is deliberately absent: a unit or floor number changes
     * nothing about where the building is, and re-geocoding on it would spend a
     * lookup to arrive back at the same point.
     */
    private const ADDRESS_FIELDS = ['address_line', 'suburb', 'city', 'province', 'region', 'postal_code', 'country'];

    /**
     * 'manual' is a pin the owner or an admin dragged into place themselves.
     * It ranks above everything the geocoder can produce: OpenStreetMap simply
     * has no record of many South African suburbs, so a human who knows where
     * the business actually is beats any lookup, and their pin must survive
     * later edits rather than being silently recomputed.
     */
    private const PRECISIONS = ['manual', 'exact', 'street', 'suburb', 'city'];

    private GeocoderInterface $geocoder;

    public function __construct(?GeocoderInterface $geocoder = null)
    {
        $this->geocoder = $geocoder ?? service('geocoder');
    }

    /**
     * @param array<string,mixed>      $address  the address as it will be saved
     * @param array<string,mixed>|null $existing the row as it is now, or null when creating
     * @param array<string,mixed>      $input    the raw submitted form data
     *
     * @return array<string,mixed>|null columns to merge into the save, or null
     *                                  to leave the stored coordinates alone
     */
    public function resolve(array $address, ?array $existing, array $input = []): ?array
    {
        $addressChanged = $existing === null;
        if (! $addressChanged) {
            foreach (self::ADDRESS_FIELDS as $field) {
                if ((string) ($address[$field] ?? '') !== (string) ($existing[$field] ?? '')) {
                    $addressChanged = true;
                    break;
                }
            }
        }

        // A listing whose previous geocode failed (Nominatim down or
        // rate-limited at save time) would otherwise never get a second chance
        // short of its owner editing the address away and back.
        $missingCoords = $existing !== null
            && ($existing['latitude'] === null || $existing['longitude'] === null);

        // Is this address one the geocoder can even be asked about? Nominatim
        // is called with countrycodes=za at every call site, and the whole
        // accept/reject ladder — the SA bounding box, the suburb anchor
        // distance, the province checks — is built around South African
        // addresses. A foreign address therefore cannot resolve: the lookup
        // returns nothing and the row is marked 'failed', which is a lie about
        // what happened and costs an upstream call per save to produce.
        //
        // So a foreign listing is placed by hand or not at all. Generalising
        // the geocoder is a project of its own.
        $isLocal = config('Countries')->isLocal((string) ($address['country'] ?? ''));

        $submitted = $this->submittedCoords($input, $isLocal);
        if ($submitted !== null && ! $this->isStale($submitted, $existing, $addressChanged)) {
            return $this->columns(
                $submitted['lat'],
                $submitted['lng'],
                $submitted['precision'],
                // The browser posts a point, not a name for it. Whatever the
                // previous lookup resolved to no longer describes this pin.
                null
            );
        }

        if (! $addressChanged && ! $missingCoords) {
            return null;
        }

        if (! $isLocal) {
            // Nothing to look up, and nothing to keep: the address just
            // changed, so any pin from before it did is now wrong. Clearing is
            // what drops the listing out of "near me" — see syncPoint().
            return $this->columns(null, null, null, null);
        }

        $coords = $this->geocoder->geocodeParts($address);

        return $coords === null
            ? $this->columns(null, null, null, null)
            : $this->columns($coords['lat'], $coords['lng'], $coords['precision'], $coords['label'] ?? null);
    }

    /**
     * Mirror a listing's coordinates into the spatial index.
     *
     * Separate from columns() because it is a write to a different table, and
     * has to happen after the listing row exists — on create there is no id to
     * key on until the insert returns. Callers pass the columns() output back in
     * once they have one.
     *
     * Deleting on null coordinates is the important half: a listing whose
     * address changed to something unresolvable must drop out of "near me"
     * results rather than linger at its old position.
     *
     * @param array<string,mixed> $columns the array columns() returned
     */
    public function syncPoint(int $listingId, array $columns): void
    {
        $db  = db_connect();
        $lat = $columns['latitude'] ?? null;
        $lng = $columns['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            $db->table('directory_listing_points')->where('listing_id', $listingId)->delete();

            return;
        }

        // POINT(longitude, latitude) — x then y. EPSG:4326 formally declares the
        // opposite axis order, so this looks wrong and is not: MySQL's
        // ST_Distance_Sphere reads x as longitude whatever the SRID says. Swap
        // them and nothing errors, no coordinate leaves its valid range, and
        // every distance is quietly wrong.
        // The point expression has to match how the column was created — the
        // migration only applies an SRID constraint where the server supports
        // one. See App\Libraries\SpatialSupport.
        $db->query(
            'INSERT INTO ' . $db->prefixTable('directory_listing_points') . ' (listing_id, location)
             VALUES (?, ' . SpatialSupport::pointExpression($db) . ')
             ON DUPLICATE KEY UPDATE location = VALUES(location)',
            [$listingId, (float) $lng, (float) $lat]
        );
    }

    /**
     * The hidden coordinate inputs are re-rendered with the listing's stored
     * values on every edit form, and the browser clears them whenever an
     * address field is touched. So coordinates that arrive *unchanged*
     * alongside a *changed* address mean the clearing never happened — a
     * broken or disabled script — and must not be trusted.
     *
     * @param array{lat:float,lng:float,precision:string} $submitted
     * @param array<string,mixed>|null                    $existing
     */
    private function isStale(array $submitted, ?array $existing, bool $addressChanged): bool
    {
        if (! $addressChanged || $existing === null) {
            return false;
        }

        // A hand-placed pin arriving unchanged is not evidence of broken
        // scripting — it is someone who corrected their location once and has
        // no reason to touch it again while fixing a typo in the street name.
        if ($submitted['precision'] === 'manual') {
            return false;
        }
        if ($existing['latitude'] === null || $existing['longitude'] === null) {
            return false;
        }

        return abs((float) $existing['latitude'] - $submitted['lat']) < 0.0000005
            && abs((float) $existing['longitude'] - $submitted['lng']) < 0.0000005;
    }

    /**
     * @param array<string,mixed> $input
     * @param bool $isLocal whether the address claims to be in South Africa,
     *                      which is what makes the bounding box applicable
     *
     * @return array{lat:float,lng:float,precision:string,place_id:string|null}|null
     */
    private function submittedCoords(array $input, bool $isLocal = true): ?array
    {
        $lat = trim((string) ($input['latitude'] ?? ''));
        $lng = trim((string) ($input['longitude'] ?? ''));
        if ($lat === '' || $lng === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        // Never trust browser-supplied coordinates without a sanity check —
        // this is a public, unauthenticated form. The check is still a real one
        // for a foreign address; it is only the South-Africa-shaped half that
        // stops applying.
        if (abs((float) $lat) > 90.0 || abs((float) $lng) > 180.0) {
            return null;
        }

        // A South African listing's pin must be in (roughly) South Africa.
        // DirectoryListingMutationService::validate() rejects this combination
        // with a field error before any save reaches here, so by this point it
        // means a write path that does not validate. Dropping the pin is the
        // safe end state either way: a listing with no coordinates is findable
        // by name, one pinned in the wrong hemisphere is not.
        if ($isLocal && ! NominatimGeocoder::isPlausible((float) $lat, (float) $lng)) {
            return null;
        }

        $precision = trim((string) ($input['geocode_precision'] ?? ''));
        $precision = in_array($precision, self::PRECISIONS, true) ? $precision : 'exact';

        return [
            'lat'       => (float) $lat,
            'lng'       => (float) $lng,
            'precision' => $precision,
        ];
    }

    /**
     * @param string|null $resolved the provider's own rendering of the address
     *                              it matched, or null when the point came from
     *                              the browser rather than a lookup
     *
     * @return array<string,mixed>
     */
    private function columns(?float $lat, ?float $lng, ?string $precision, ?string $resolved): array
    {
        // A failed lookup and a hand-placed pin both leave no doubt about what
        // happened, which is the whole point of storing this: a NULL coordinate
        // alone cannot tell you whether it was never attempted, attempted and
        // unmatched, or attempted while the geocoder was down.
        if ($precision === 'manual') {
            $status = 'manual';
        } elseif ($lat === null || $lng === null) {
            $status = 'failed';
        } else {
            $status = 'ok';
        }

        return [
            'latitude'          => $lat,
            'longitude'         => $lng,
            'geocode_precision' => $precision,
            'geocoding_status'  => $status,
            // Always written, never merely left alone: a resolved address
            // belonging to the previous pin is worse than none, for the same
            // reason stale coordinates are.
            'geocoded_address'  => $resolved,
            // Recorded even for a failure, so "we tried and got nothing" is
            // distinguishable from "we never tried".
            'geocoded_at'       => date('Y-m-d H:i:s'),
        ];
    }
}
