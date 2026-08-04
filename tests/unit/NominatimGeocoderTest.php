<?php

use App\Libraries\Geocoding\NominatimGeocoder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The ladder's looser steps drop the city so a typo in it can't sink an
 * otherwise findable street — which leaves them free to match a same-named
 * street anywhere in the province. Real example: "1 Main Street" searched
 * across Gauteng resolves to Marshalltown, Johannesburg, ~55km from the Menlo
 * Park, Pretoria one. Province and bounding-box checks cannot tell those apart,
 * so a listing's own suburb/city is used as a distance anchor instead.
 *
 * Nominatim is stubbed throughout via the request() seam — these tests are
 * about the decision logic and must not depend on a third-party service.
 *
 * @internal
 */
final class NominatimGeocoderTest extends CIUnitTestCase
{
    /** Roughly Menlo Park, Pretoria — the listing's actual locality. */
    private const PRETORIA = ['lat' => '-25.7745196', 'lon' => '28.2468387'];

    /** Roughly Marshalltown, Johannesburg — same street name, wrong city. */
    private const JOHANNESBURG = ['lat' => '-26.2068925', 'lon' => '28.0411477'];

    /**
     * A geocoder whose upstream answers come from a callable, keyed on the
     * params of each ladder step.
     */
    private function stub(callable $respond): NominatimGeocoder
    {
        return new class ($respond) extends NominatimGeocoder {
            /** @var callable */
            private $respond;

            public function __construct(callable $respond)
            {
                $this->respond = $respond;
            }

            protected function request(array $params): ?array
            {
                return ($this->respond)($params);
            }
        };
    }

    /** A geocoder whose /reverse answers come from a callable. */
    private function reverseStub(callable $respond): NominatimGeocoder
    {
        return new class ($respond) extends NominatimGeocoder {
            /** @var callable */
            private $respond;

            public function __construct(callable $respond)
            {
                $this->respond = $respond;
            }

            protected function reverseRequest(float $lat, float $lng): ?array
            {
                return ($this->respond)($lat, $lng);
            }
        };
    }

    /** Every test uses a distinct street so the shared cache can't leak between them. */
    private function address(string $street): array
    {
        return [
            'address_line' => $street,
            'suburb'       => 'Menlo Park',
            'city'         => 'Pretoria',
            'province'     => 'Gauteng',
            'postal_code'  => '0081',
        ];
    }

    /** @param array<string,string> $point */
    private function hit(array $point, array $extra = []): array
    {
        return array_merge([
            'lat'          => $point['lat'],
            'lon'          => $point['lon'],
            'display_name' => 'stub',
            'address'      => ['state' => 'Gauteng'],
        ], $extra);
    }

    public function testStreetMatchFarFromTheLocalityIsRejected(): void
    {
        $street = '1 Test Reject Street ' . uniqid();

        $geocoder = $this->stub(function (array $params) {
            // Any street query returns the Johannesburg one; the locality anchor
            // (a city-only query) correctly returns Pretoria.
            if (isset($params['street'])) {
                return $this->hit(self::JOHANNESBURG);
            }

            return $this->hit(self::PRETORIA);
        });

        $coords = $geocoder->geocodeParts($this->address($street));

        $this->assertNotNull($coords);
        $this->assertSame('suburb', $coords['precision'], 'should fall through past the bad street match');
        $this->assertEqualsWithDelta(-25.77, $coords['lat'], 0.1, 'should land in Pretoria, not Johannesburg');
    }

    public function testStreetMatchNearTheLocalityIsAccepted(): void
    {
        $street = '1 Test Accept Street ' . uniqid();

        $geocoder = $this->stub(function (array $params) {
            if (isset($params['street'])) {
                // A few hundred metres from the anchor — a normal street match.
                return $this->hit(['lat' => '-25.7760000', 'lon' => '28.2480000']);
            }

            return $this->hit(self::PRETORIA);
        });

        $coords = $geocoder->geocodeParts($this->address($street));

        $this->assertSame('street', $coords['precision']);
        $this->assertEqualsWithDelta(-25.776, $coords['lat'], 0.001);
    }

    public function testHouseNumberMatchIsReportedAsExact(): void
    {
        $street = '1 Test Exact Street ' . uniqid();

        $geocoder = $this->stub(function (array $params) {
            if (isset($params['street'])) {
                return $this->hit(
                    ['lat' => '-25.7750000', 'lon' => '28.2470000'],
                    ['address' => ['state' => 'Gauteng', 'house_number' => '1']]
                );
            }

            return $this->hit(self::PRETORIA);
        });

        $this->assertSame('exact', $geocoder->geocodeParts($this->address($street))['precision']);
    }

    public function testNoAnchorMeansNoVeto(): void
    {
        // With no suburb and no city there is nothing to anchor against, so the
        // gate must stay out of the way rather than reject everything.
        $geocoder = $this->stub(fn (array $params) => isset($params['street'])
            ? $this->hit(self::JOHANNESBURG)
            : null);

        $coords = $geocoder->geocodeParts([
            'address_line' => '1 Test Anchorless Street ' . uniqid(),
            'suburb'       => '',
            'city'         => '',
            'province'     => 'Gauteng',
            'postal_code'  => '',
        ]);

        $this->assertNotNull($coords);
        $this->assertSame('street', $coords['precision']);
    }

    public function testUnresolvableAddressReturnsNull(): void
    {
        $geocoder = $this->stub(static fn (array $params) => null);

        $this->assertNull($geocoder->geocodeParts($this->address('1 Test Nothing Street ' . uniqid())));
    }

    public function testResultOutsideSouthAfricaIsRejected(): void
    {
        $geocoder = $this->stub(function (array $params) {
            return isset($params['street'])
                ? $this->hit(['lat' => '51.5074', 'lon' => '-0.1278']) // London
                : $this->hit(self::PRETORIA);
        });

        $coords = $geocoder->geocodeParts($this->address('1 Test London Street ' . uniqid()));

        $this->assertSame('suburb', $coords['precision']);
    }

    public function testProvinceMismatchIsRejected(): void
    {
        $geocoder = $this->stub(function (array $params) {
            if (isset($params['street'])) {
                return $this->hit(
                    ['lat' => '-33.9249', 'lon' => '18.4241'],
                    ['address' => ['state' => 'Western Cape']]
                );
            }

            return $this->hit(self::PRETORIA);
        });

        $coords = $geocoder->geocodeParts($this->address('1 Test Province Street ' . uniqid()));

        $this->assertSame('suburb', $coords['precision']);
    }

    /**
     * The address a match resolved to is stored so a wrong pin is diagnosable
     * by reading the row, rather than by re-running the lookup and hoping it
     * fails the same way.
     */
    public function testAMatchReportsWhatItResolvedTo(): void
    {
        $geocoder = $this->stub(fn () => $this->hit(self::PRETORIA, [
            'display_name' => '1 Label Street, Menlo Park, Pretoria, 0081, South Africa',
            'address'      => ['state' => 'Gauteng', 'road' => 'Label Street', 'house_number' => '1'],
        ]));

        $coords = $geocoder->geocodeParts($this->address('1 Test Label Street ' . uniqid()));

        $this->assertSame('1 Label Street, Menlo Park, Pretoria, 0081, South Africa', $coords['label']);
    }

    // --------------------------------------------------------------- reverse

    public function testReverseReturnsTheAddressAtAPoint(): void
    {
        $geocoder = $this->reverseStub(fn () => [
            'lat'          => '-25.7745196',
            'lon'          => '28.2468387',
            'display_name' => '12 Duncan Street, Hatfield, Pretoria, 0083, South Africa',
            'address'      => [
                'house_number' => '12',
                'road'         => 'Duncan Street',
                'suburb'       => 'Hatfield',
                'city'         => 'Pretoria',
                'state'        => 'Gauteng',
                'postcode'     => '0083',
            ],
        ]);

        $result = $geocoder->reverse(-25.7745196, 28.2468387);

        $this->assertSame('12 Duncan Street', $result['address_line']);
        $this->assertSame('Hatfield', $result['suburb']);
        $this->assertSame('Pretoria', $result['city']);
        $this->assertSame('Gauteng', $result['province']);
        $this->assertSame('0083', $result['postal_code']);
    }

    /**
     * The pin the user dragged is the truth, not whatever centre OSM reports
     * for the feature it happened to match. Returning OSM's point would let an
     * "accept this address" click quietly move the marker off the door.
     */
    public function testReverseKeepsTheExactPointItWasAskedAbout(): void
    {
        $geocoder = $this->reverseStub(fn () => [
            // OSM answers with the centre of the building, ~40m away.
            'lat'          => '-25.7749000',
            'lon'          => '28.2472000',
            'display_name' => 'Somewhere, Pretoria',
            'address'      => ['road' => 'Duncan Street', 'city' => 'Pretoria', 'state' => 'Gauteng'],
        ]);

        $result = $geocoder->reverse(-25.7745196, 28.2468387);

        $this->assertSame(-25.7745196, $result['lat']);
        $this->assertSame(28.2468387, $result['lng']);
    }

    /** Never spends a request on a point that cannot be a South African address. */
    public function testReverseRefusesPointsOutsideSouthAfricaWithoutCallingOut(): void
    {
        $calls    = 0;
        $geocoder = $this->reverseStub(static function () use (&$calls) {
            $calls++;

            return null;
        });

        $this->assertNull($geocoder->reverse(51.5074, -0.1278)); // London
        $this->assertSame(0, $calls);
    }

    public function testReverseReturnsNullWhenNothingIsThere(): void
    {
        $geocoder = $this->reverseStub(static fn () => null);

        $this->assertNull($geocoder->reverse(-30.1234567, 25.1234567));
    }
}
