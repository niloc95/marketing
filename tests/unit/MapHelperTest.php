<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The map helpers turn a listing row into something a map or a navigation app
 * can use.
 *
 * The rule running through all of them: a coordinate is only worth trusting
 * when someone or something actually pinpointed the place. A 'street' match is
 * a point somewhere along the road — OpenStreetMap maps no house numbers on
 * plenty of South African streets, and Fort Street in Birnam is bare segments
 * spanning 636m — so treating it as a doorstep sends customers to the wrong end
 * of the road. That distinction is what these tests exist to hold.
 *
 * @internal
 */
final class MapHelperTest extends CIUnitTestCase
{
    private function listing(array $overrides = []): array
    {
        return array_merge([
            'address_line'      => '21 Delta Road',
            'address_line_2'    => '',
            'suburb'            => 'Eltonhill',
            'city'              => 'Johannesburg',
            'province'          => 'Gauteng',
            'postal_code'       => '2196',
            'country'           => 'South Africa',
            'latitude'          => '-26.1294840',
            'longitude'         => '28.0678351',
            'geocode_precision' => 'street',
        ], $overrides);
    }

    // -------------------------------------------------------------- map_point

    public function testPointCarriesTheCoordinatesAndAZoom(): void
    {
        $point = map_point($this->listing());

        $this->assertEqualsWithDelta(-26.1294840, $point['lat'], 0.0000001);
        $this->assertEqualsWithDelta(28.0678351, $point['lng'], 0.0000001);
        $this->assertIsInt($point['zoom']);
    }

    /**
     * Unlike the old Google embed, which could map a listing from address text
     * alone, an interactive map needs a real position. A listing that never
     * geocoded shows no map — and geocoding_status is how those get found and
     * fixed.
     */
    public function testNoPointWithoutCoordinates(): void
    {
        $this->assertNull(map_point($this->listing(['latitude' => null, 'longitude' => null])));
    }

    public function testNullIslandIsTreatedAsMissing(): void
    {
        $this->assertNull(map_point($this->listing(['latitude' => '0', 'longitude' => '0'])));
    }

    /**
     * Showing a suburb-level guess at doorstep zoom reads as a precise pin in
     * the wrong place. The zoom has to admit how much we actually know.
     */
    public function testZoomTracksPrecision(): void
    {
        $exact  = map_point($this->listing(['geocode_precision' => 'exact']))['zoom'];
        $street = map_point($this->listing(['geocode_precision' => 'street']))['zoom'];
        $suburb = map_point($this->listing(['geocode_precision' => 'suburb']))['zoom'];
        $city   = map_point($this->listing(['geocode_precision' => 'city']))['zoom'];

        $this->assertGreaterThan($street, $exact);
        $this->assertGreaterThan($suburb, $street);
        $this->assertGreaterThan($city, $suburb);
    }

    public function testUnknownPrecisionGetsAMiddleGroundZoom(): void
    {
        $legacy = map_point($this->listing(['geocode_precision' => null]))['zoom'];

        $this->assertLessThan(map_point($this->listing(['geocode_precision' => 'exact']))['zoom'], $legacy);
        $this->assertGreaterThan(map_point($this->listing(['geocode_precision' => 'city']))['zoom'], $legacy);
    }

    public function testOnlyImpreciseResultsAreLabelledApproximate(): void
    {
        $this->assertTrue(map_point($this->listing(['geocode_precision' => 'city']))['approximate']);
        $this->assertTrue(map_point($this->listing(['geocode_precision' => 'street']))['approximate']);
        $this->assertFalse(map_point($this->listing(['geocode_precision' => 'exact']))['approximate']);
        $this->assertFalse(map_point($this->listing(['geocode_precision' => null]))['approximate']);
    }

    public function testHandPlacedPinIsNeverCalledApproximate(): void
    {
        $point = map_point($this->listing(['geocode_precision' => 'manual']));

        $this->assertFalse($point['approximate'], 'a human placed it — it is the best data we have');
    }

    public function testHandPlacedPinGetsTheTightestZoom(): void
    {
        $this->assertSame(
            map_point($this->listing(['geocode_precision' => 'exact']))['zoom'],
            map_point($this->listing(['geocode_precision' => 'manual']))['zoom']
        );
    }

    // ------------------------------------------------------- map_address_text

    public function testAddressTextJoinsTheParts(): void
    {
        $this->assertSame(
            '21 Delta Road, Eltonhill, Johannesburg, Gauteng, 2196',
            map_address_text($this->listing())
        );
    }

    public function testAddressTextIncludesLineTwoWhenPresent(): void
    {
        $text = map_address_text($this->listing(['address_line_2' => 'Unit 4B']));

        $this->assertStringContainsString('21 Delta Road, Unit 4B, Eltonhill', $text);
    }

    public function testAddressTextSkipsEmptyParts(): void
    {
        $this->assertSame(
            '21 Delta Road, Johannesburg',
            map_address_text($this->listing([
                'suburb' => '', 'province' => '', 'postal_code' => '',
            ]))
        );
    }

    public function testAddressTextAddsCountryOnlyWhenAsked(): void
    {
        $this->assertStringNotContainsString('South Africa', map_address_text($this->listing()));
        $this->assertStringContainsString('South Africa', map_address_text($this->listing(), true));
    }

    // -------------------------------------------------------- map_destination

    public function testDirectionsUseTheRoutingSchemeNotSearch(): void
    {
        // /maps/search/ only drops a pin — the visitor still has to tap
        // "Directions". /maps/dir/ opens routing straight away, and on a phone
        // the OS hands it to whichever maps app is installed.
        $url = map_directions_url($this->listing());

        $this->assertStringContainsString('/maps/dir/?api=1&destination=', $url);
        $this->assertStringNotContainsString('/maps/search/', $url);
    }

    public function testDirectionsUseCoordinatesOnlyForPinpointedListings(): void
    {
        foreach (['manual', 'exact'] as $precision) {
            $this->assertStringContainsString(
                rawurlencode('-26.1294840,28.0678351'),
                map_directions_url($this->listing(['geocode_precision' => $precision])),
                "precision '{$precision}' should route to the stored point"
            );
        }
    }

    public function testDirectionsPreferAddressTextWhenThePinIsOnlyApproximate(): void
    {
        // A street/suburb/city match is a centroid. Handing over the text lets
        // the navigation app apply its own geocoder, which for house numbers is
        // usually better than whatever produced our loose pin.
        foreach (['street', 'suburb', 'city', null] as $precision) {
            $url = map_directions_url($this->listing(['geocode_precision' => $precision]));

            $this->assertStringContainsString(rawurlencode('21 Delta Road'), $url);
            $this->assertStringNotContainsString('26.1294840', $url);
        }
    }

    public function testApproximatePinStillUsedWhenThereIsNoAddressToFallBackOn(): void
    {
        $url = map_directions_url($this->listing([
            'geocode_precision' => 'city',
            'address_line' => '', 'suburb' => '', 'city' => '',
            'province' => '', 'postal_code' => '', 'country' => '',
        ]));

        $this->assertStringContainsString(rawurlencode('-26.1294840,28.0678351'), $url);
    }

    public function testDirectionsFallBackToAddressTextWithoutCoordinates(): void
    {
        $url = map_directions_url($this->listing(['latitude' => null, 'longitude' => null]));

        $this->assertStringContainsString(rawurlencode('21 Delta Road'), $url);
        $this->assertStringContainsString(rawurlencode('Johannesburg'), $url);
    }

    public function testDirectionsAreEmptyWithNothingToNavigateTo(): void
    {
        $this->assertSame('', map_directions_url([
            'address_line' => '', 'suburb' => '', 'city' => '',
            'province' => '', 'postal_code' => '',
            'latitude' => null, 'longitude' => null,
        ]));
    }

    // ----------------------------------------------------------- map_waze_url

    public function testWazeNavigatesToAPinpointedListingByCoordinates(): void
    {
        $url = map_waze_url($this->listing(['geocode_precision' => 'exact']));

        $this->assertSame('https://waze.com/ul?ll=' . rawurlencode('-26.1294840,28.0678351') . '&navigate=yes', $url);
    }

    public function testWazeGetsTheAddressTextWhenThePinIsOnlyApproximate(): void
    {
        $url = map_waze_url($this->listing(['geocode_precision' => 'street']));

        $this->assertStringStartsWith('https://waze.com/ul?q=', $url);
        $this->assertStringContainsString(rawurlencode('21 Delta Road'), $url);
        $this->assertStringNotContainsString('ll=', $url);
        $this->assertStringEndsWith('&navigate=yes', $url);
    }

    public function testWazeIsEmptyWithNothingToNavigateTo(): void
    {
        $this->assertSame('', map_waze_url([
            'address_line' => '', 'suburb' => '', 'city' => '',
            'province' => '', 'postal_code' => '',
            'latitude' => null, 'longitude' => null,
        ]));
    }
}
