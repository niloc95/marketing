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

    /**
     * The fields that say which area an address is in, as opposed to where in
     * that area. A failed lookup after an edit that left all of these alone
     * (a street name corrected, a number added, a postal code filled in) keeps
     * the old pin: it is still in the right suburb, and very likely on the
     * right door. See keepAfterFailedLookup().
     */
    private const AREA_FIELDS = ['suburb', 'city', 'province', 'region', 'country'];

    /**
     * geocoding_status for a pin kept from before an address edit whose new
     * lookup failed. It is still shown and still sorts in "near me", but the
     * owner is asked to check it and `directory:geocode --status stale` retries
     * it. VARCHAR(16), so no migration.
     */
    public const STATUS_STALE = 'stale';

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

        // Is this address one the geocoder can be asked about? South Africa
        // and every country with a Config\Countries profile can: the lookup
        // is limited to that country and its bounding box. Any other country
        // has no box and no region list to check a match against, so a lookup
        // there would be a guess; those listings are placed by hand or not at
        // all, and the row is not marked 'failed' for a lookup never made.
        $country    = NominatimGeocoder::countryOf($address);
        $geocodable = $country === 'ZA' || config('Countries')->isSupported($country);
        $address['country_code'] = $country;

        $submitted = $this->submittedCoords($input, $geocodable ? $country : null);

        // The form re-posts the stored point on every save. On a row whose pin
        // is waiting to be checked, that is not the owner confirming it, only
        // the hidden inputs doing their job, and treating it as a fresh point
        // would quietly turn 'stale' back into 'ok'. Dragging the marker posts
        // 'manual', which does count, and an address edit clears the inputs.
        if ($submitted !== null && $submitted['precision'] !== 'manual'
            && ($existing['geocoding_status'] ?? null) === self::STATUS_STALE
            && $this->samePoint($submitted, $existing)) {
            return null;
        }

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

        if (! $geocodable) {
            // Nothing to look up, and nothing to keep: the address just
            // changed, so any pin from before it did is now wrong. Clearing is
            // what drops the listing out of "near me" — see syncPoint().
            return $this->columns(null, null, null, null);
        }

        $coords = $this->geocoder->geocodeParts($address);

        if ($coords === null) {
            return $this->keepAfterFailedLookup($address, $existing)
                ?? $this->columns(null, null, null, null);
        }

        // A lookup that only got as far as the suburb or city is no reason to
        // move a pin that was on the street, when the edit never left that
        // suburb. Same treatment as a miss.
        if (self::isCoarser((string) $coords['precision'], (string) ($existing['geocode_precision'] ?? ''))) {
            $kept = $this->keepAfterFailedLookup($address, $existing);
            if ($kept !== null) {
                return $kept;
            }
        }

        return $this->columns($coords['lat'], $coords['lng'], $coords['precision'], $coords['label'] ?? null);
    }

    /**
     * Whether a fresh lookup at $new precision says less about where the door
     * is than the stored $old one. Unknown values never count as coarser.
     */
    public static function isCoarser(string $new, string $old): bool
    {
        $rank = ['city' => 1, 'suburb' => 2, 'street' => 3, 'exact' => 4, 'manual' => 5];

        return isset($rank[$new], $rank[$old]) && $rank[$new] < $rank[$old];
    }

    /**
     * An owner who fixes a typo in their street name, on an address the
     * geocoder only half knows, used to lose a perfectly good pin: the new
     * spelling failed to resolve and the old point was cleared. If the edit
     * stayed inside the same suburb, city and province, the old pin is almost
     * certainly still right, so it is kept and flagged for a check instead.
     * The same goes for a lookup that only found the suburb or city centre
     * for an address whose pin was already on the street.
     *
     * A move to another suburb or city still clears it. A pin in the wrong
     * suburb is worse than none.
     *
     * @param array<string,mixed>      $address
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,mixed>|null
     */
    private function keepAfterFailedLookup(array $address, ?array $existing): ?array
    {
        if ($existing === null || $existing['latitude'] === null || $existing['longitude'] === null) {
            return null;
        }

        // Case and stray spaces are not a move: "ELTONHILL " is Eltonhill.
        $norm = static fn (array $row, string $field): string => mb_strtolower(trim((string) ($row[$field] ?? '')));
        foreach (self::AREA_FIELDS as $field) {
            if ($norm($address, $field) !== $norm($existing, $field)) {
                return null;
            }
        }

        $precision = (string) ($existing['geocode_precision'] ?? '');

        return $this->columns(
            (float) $existing['latitude'],
            (float) $existing['longitude'],
            in_array($precision, self::PRECISIONS, true) ? $precision : null,
            // The pin has not moved, so what the last lookup called it still
            // describes it.
            isset($existing['geocoded_address']) ? (string) $existing['geocoded_address'] : null,
            self::STATUS_STALE
        );
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
     * address moved to another area and could not be found there must drop
     * out of "near me" results rather than linger at its old position.
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

        return $this->samePoint($submitted, $existing);
    }

    /**
     * @param array{lat:float,lng:float,precision:string} $submitted
     * @param array<string,mixed>|null                    $existing
     */
    private function samePoint(array $submitted, ?array $existing): bool
    {
        if ($existing === null || $existing['latitude'] === null || $existing['longitude'] === null) {
            return false;
        }

        return abs((float) $existing['latitude'] - $submitted['lat']) < 0.0000005
            && abs((float) $existing['longitude'] - $submitted['lng']) < 0.0000005;
    }

    /**
     * @param array<string,mixed> $input
     * @param string|null $country the address's ISO country when it has a
     *                             bounding box to check against, else null
     *
     * @return array{lat:float,lng:float,precision:string}|null
     */
    private function submittedCoords(array $input, ?string $country = 'ZA'): ?array
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

        // A listing's pin must be in (roughly) its own country.
        // DirectoryListingMutationService::validate() rejects this combination
        // with a field error before any save reaches here, so by this point it
        // means a write path that does not validate. Dropping the pin is the
        // safe end state either way: a listing with no coordinates is findable
        // by name, one pinned in the wrong hemisphere is not.
        if ($country !== null && ! NominatimGeocoder::isPlausible((float) $lat, (float) $lng, $country)) {
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
     * @param string|null $status   set only for a kept pin (STATUS_STALE);
     *                              otherwise worked out from the point
     *
     * @return array<string,mixed>
     */
    private function columns(?float $lat, ?float $lng, ?string $precision, ?string $resolved, ?string $status = null): array
    {
        // A failed lookup and a hand-placed pin both leave no doubt about what
        // happened, which is the whole point of storing this: a NULL coordinate
        // alone cannot tell you whether it was never attempted, attempted and
        // unmatched, or attempted while the geocoder was down.
        $status ??= match (true) {
            $precision === 'manual'        => 'manual',
            $lat === null || $lng === null => 'failed',
            default                        => 'ok',
        };

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
