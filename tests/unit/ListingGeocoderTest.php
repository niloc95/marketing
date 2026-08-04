<?php

use App\Libraries\Geocoding\NominatimGeocoder;
use App\Libraries\ListingGeocoder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ListingGeocoder decides when a listing needs a lookup and which coordinates
 * win. Getting that wrong is expensive in both directions — a needless call
 * burns Nominatim's rate limit, and a skipped one leaves a listing with no map
 * at all — so the decision table is pinned here.
 *
 * The upstream geocoder is stubbed throughout: these tests are about the
 * decision, not about OpenStreetMap's coverage, and they must not depend on a
 * third-party service being reachable.
 *
 * @internal
 */
final class ListingGeocoderTest extends CIUnitTestCase
{
    /** Johannesburg — what the stubbed lookup always "finds". */
    private const LOOKUP = ['lat' => -26.1294840, 'lng' => 28.0678351, 'precision' => 'street'];

    /** Durban — stands in for a point the user picked from the dropdown. */
    private const PICKED = ['lat' => -29.8587, 'lng' => 31.0218];

    private function address(array $overrides = []): array
    {
        return array_merge([
            'address_line' => '21 Delta Road',
            'suburb'       => 'Eltonhill',
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
            'postal_code'  => '2196',
            'country'      => 'South Africa',
        ], $overrides);
    }

    private function existing(array $overrides = []): array
    {
        return array_merge($this->address(), [
            'latitude'  => '-26.1294840',
            'longitude' => '28.0678351',
        ], $overrides);
    }

    /** @param array{lat:float,lng:float,precision:string}|null $result */
    private function geocoder(?array $result, ?int &$calls = null): ListingGeocoder
    {
        $calls = 0;
        $stub  = new class ($result, $calls) extends NominatimGeocoder {
            public function __construct(private ?array $result, private ?int &$calls) {}

            public function geocodeParts(array $parts): ?array
            {
                $this->calls++;

                return $this->result;
            }
        };

        return new ListingGeocoder($stub);
    }

    public function testPickedCoordinatesAreUsedWithoutALookup(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve($this->address(), null, [
            'latitude'          => (string) self::PICKED['lat'],
            'longitude'         => (string) self::PICKED['lng'],
            'geocode_precision' => 'exact',
        ]);

        $this->assertSame(self::PICKED['lat'], $result['latitude']);
        $this->assertSame('exact', $result['geocode_precision']);
        $this->assertSame(0, $calls, 'a picked suggestion should not cost a Nominatim call');
    }

    public function testCoordinatesOutsideSouthAfricaAreRejected(): void
    {
        $g      = $this->geocoder(self::LOOKUP);
        $result = $g->resolve($this->address(), null, [
            'latitude'  => '51.5074', // London
            'longitude' => '-0.1278',
        ]);

        $this->assertSame(self::LOOKUP['lat'], $result['latitude'], 'should fall back to a real lookup');
    }

    public function testNonNumericCoordinatesAreRejected(): void
    {
        $g      = $this->geocoder(self::LOOKUP);
        $result = $g->resolve($this->address(), null, [
            'latitude'  => 'abc',
            'longitude' => '<script>',
        ]);

        $this->assertSame(self::LOOKUP['lat'], $result['latitude']);
    }

    public function testUnknownPrecisionValueFallsBackToExact(): void
    {
        $g      = $this->geocoder(null);
        $result = $g->resolve($this->address(), null, [
            'latitude'          => (string) self::PICKED['lat'],
            'longitude'         => (string) self::PICKED['lng'],
            'geocode_precision' => 'rooftop-ultra',
        ]);

        $this->assertSame('exact', $result['geocode_precision']);
    }

    public function testUnchangedAddressWithCoordinatesDoesNothing(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve($this->address(), $this->existing(), []);

        $this->assertNull($result, 'an unrelated edit must not touch the coordinates');
        $this->assertSame(0, $calls);
    }

    public function testListingMissingCoordinatesSelfHeals(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve(
            $this->address(),
            $this->existing(['latitude' => null, 'longitude' => null]),
            []
        );

        $this->assertSame(self::LOOKUP['lat'], $result['latitude']);
        $this->assertSame(1, $calls, 'a failed earlier geocode should get another chance');
    }

    public function testStaleHiddenCoordinatesAreDistrustedWhenTheAddressChanged(): void
    {
        // The hidden inputs still carry the stored point while the address text
        // has moved on — i.e. the script that should have cleared them didn't
        // run. Trusting them would pin the listing to its old location.
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve(
            $this->address(['address_line' => '45 Fort Street', 'suburb' => 'Birnam']),
            $this->existing(),
            ['latitude' => '-26.1294840', 'longitude' => '28.0678351', 'geocode_precision' => 'exact']
        );

        $this->assertSame(1, $calls, 'must re-geocode rather than keep the old pin');
        $this->assertSame(self::LOOKUP['lat'], $result['latitude']);
    }

    public function testFreshlyPickedCoordinatesWinWhenTheAddressChanged(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve(
            $this->address(['address_line' => '45 Fort Street', 'suburb' => 'Birnam']),
            $this->existing(),
            [
                'latitude'          => (string) self::PICKED['lat'],
                'longitude'         => (string) self::PICKED['lng'],
                'geocode_precision' => 'exact',
            ]
        );

        $this->assertSame(self::PICKED['lat'], $result['latitude']);
        $this->assertSame(0, $calls);
    }

    public function testUnresolvableChangedAddressClearsCoordinates(): void
    {
        $g      = $this->geocoder(null);
        $result = $g->resolve(
            $this->address(['address_line' => 'Nowhere at all', 'city' => 'Nowhereville']),
            $this->existing(),
            []
        );

        $this->assertNull($result['latitude'], 'an old pin on a new address is worse than no pin');
        $this->assertNull($result['geocode_precision']);
        $this->assertNotNull($result['geocoded_at'], 'the failed attempt should still be recorded');
    }

    public function testManualPinIsAccepted(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve($this->address(), null, [
            'latitude'          => (string) self::PICKED['lat'],
            'longitude'         => (string) self::PICKED['lng'],
            'geocode_precision' => 'manual',
        ]);

        $this->assertSame('manual', $result['geocode_precision']);
        $this->assertSame(0, $calls);
    }

    public function testManualPinSurvivesAnAddressEdit(): void
    {
        // The whole point of a hand-placed pin: OSM cannot find this suburb, so
        // the owner put the marker where the business really is. Correcting a
        // spelling in the street name afterwards must not silently recompute it.
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $manual = $this->existing([
            'latitude'          => (string) self::PICKED['lat'],
            'longitude'         => (string) self::PICKED['lng'],
            'geocode_precision' => 'manual',
        ]);

        $result = $g->resolve(
            $this->address(['suburb' => 'Elton Hill']), // fixed the spelling
            $manual,
            [
                'latitude'          => (string) self::PICKED['lat'],
                'longitude'         => (string) self::PICKED['lng'],
                'geocode_precision' => 'manual',
            ]
        );

        $this->assertSame(self::PICKED['lat'], $result['latitude'], 'the hand-placed pin must be kept');
        $this->assertSame('manual', $result['geocode_precision']);
        $this->assertSame(0, $calls, 'and must not trigger a lookup');
    }

    public function testNonManualPinStillGoesStaleOnAnAddressEdit(): void
    {
        // Same shape as above but precision 'exact' — that came from a geocoder,
        // not a person, so unchanged coordinates alongside a changed address
        // still mean the clearing script didn't run.
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve(
            $this->address(['suburb' => 'Elton Hill']),
            $this->existing(['geocode_precision' => 'exact']),
            ['latitude' => '-26.1294840', 'longitude' => '28.0678351', 'geocode_precision' => 'exact']
        );

        $this->assertSame(1, $calls);
        $this->assertSame(self::LOOKUP['lat'], $result['latitude']);
    }

    public function testCreateAlwaysGeocodes(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve($this->address(), null, []);

        $this->assertSame(1, $calls);
        $this->assertSame('street', $result['geocode_precision']);
    }

    /**
     * A NULL coordinate on its own is ambiguous — never attempted, attempted
     * and unmatched, or attempted while Nominatim was down? Each wants a
     * different response, and `directory:geocode --status=failed` depends on
     * the difference being recorded.
     */
    public function testStatusRecordsWhatActuallyHappened(): void
    {
        $ok = $this->geocoder(self::LOOKUP)->resolve($this->address(), null, []);
        $this->assertSame('ok', $ok['geocoding_status']);

        $failed = $this->geocoder(null)->resolve($this->address(), null, []);
        $this->assertSame('failed', $failed['geocoding_status']);

        $manual = $this->geocoder(self::LOOKUP)->resolve($this->address(), null, [
            'latitude'          => (string) self::PICKED['lat'],
            'longitude'         => (string) self::PICKED['lng'],
            'geocode_precision' => 'manual',
        ]);
        $this->assertSame('manual', $manual['geocoding_status']);
    }

    public function testResolvedAddressIsStoredAlongsideTheCoordinates(): void
    {
        $g      = $this->geocoder(self::LOOKUP + ['label' => '21 Delta Road, Eltonhill, Johannesburg']);
        $result = $g->resolve($this->address(), null, []);

        $this->assertSame('21 Delta Road, Eltonhill, Johannesburg', $result['geocoded_address']);
    }

    /**
     * The browser posts a point, not a name for it. Whatever the previous
     * lookup resolved to describes the old pin, not this one.
     */
    public function testAPickedPinClearsThePreviouslyResolvedAddress(): void
    {
        $g      = $this->geocoder(self::LOOKUP);
        $result = $g->resolve($this->address(), null, [
            'latitude'          => (string) self::PICKED['lat'],
            'longitude'         => (string) self::PICKED['lng'],
            'geocode_precision' => 'exact',
        ]);

        $this->assertNull($result['geocoded_address']);
    }

    /** A unit number changes nothing about where the building is. */
    public function testEditingAddressLineTwoDoesNotTriggerALookup(): void
    {
        $g      = $this->geocoder(self::LOOKUP, $calls);
        $result = $g->resolve(
            $this->address(['address_line_2' => 'Unit 4B']),
            $this->existing(['address_line_2' => '']),
            []
        );

        $this->assertNull($result, 'a floor or unit number is not a new location');
        $this->assertSame(0, $calls);
    }

    public function testPlausibilityBoundsCoverSouthAfricaOnly(): void
    {
        $this->assertTrue(NominatimGeocoder::isPlausible(-26.2041, 28.0473));  // Johannesburg
        $this->assertTrue(NominatimGeocoder::isPlausible(-33.9249, 18.4241));  // Cape Town
        $this->assertFalse(NominatimGeocoder::isPlausible(51.5074, -0.1278));  // London
        $this->assertFalse(NominatimGeocoder::isPlausible(0.0, 0.0));          // null island
    }
}
