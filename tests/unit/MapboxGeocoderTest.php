<?php

use App\Libraries\Geocoding\FallbackGeocoder;
use App\Libraries\Geocoding\MapboxGeocoder;
use App\Libraries\Geocoding\NominatimGeocoder;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * Mapbox's terms split its geocoding in two: temporary results may be shown but
 * never stored or cached, and anything we keep must come from a request made
 * with permanent=true. These tests pin that split, so a refactor can't quietly
 * start saving temporary coordinates or paying permanent rates for keystrokes.
 *
 * Mapbox is stubbed throughout via the request() seam, which records every call.
 *
 * @internal
 */
final class MapboxGeocoderTest extends CIUnitTestCase
{
    /** @var list<array{endpoint:string,query:array<string,mixed>}> */
    private array $calls = [];

    protected function tearDown(): void
    {
        unset($_ENV['directory.mapboxToken'], $_SERVER['directory.mapboxToken']);
        Services::reset();
        parent::tearDown();
    }

    /**
     * A geocoder that answers every call with $respond($endpoint, $query).
     * A null answer stands for a call that failed (non-200 or transport error).
     */
    private function stub(callable $respond): MapboxGeocoder
    {
        $calls = &$this->calls;

        return new class ('pk.test-token', $respond, $calls) extends MapboxGeocoder {
            /** @var callable */
            private $respond;

            /** @var array<int,mixed> */
            private array $calls;

            public function __construct(string $token, callable $respond, array &$calls)
            {
                parent::__construct($token);
                $this->respond = $respond;
                $this->calls   = &$calls;
            }

            protected function request(string $endpoint, array $query, string $context): array
            {
                $this->calls[] = ['endpoint' => $endpoint, 'query' => $query];

                $features = ($this->respond)($endpoint, $query);
                if ($features === null) {
                    $this->failed = true;

                    return [];
                }

                return $features;
            }
        };
    }

    /**
     * A v6 address feature. Each test uses a distinct street so the shared
     * cache can't leak an answer between tests.
     *
     * @param array<string,mixed> $props merged over the defaults
     */
    private function address(string $street, array $props = [], string $province = 'Western Cape'): array
    {
        return [
            'type'       => 'Feature',
            'geometry'   => ['type' => 'Point', 'coordinates' => [18.4241, -33.9249]],
            'properties' => array_replace([
                'feature_type' => 'address',
                'name'         => $street,
                'full_address' => $street . ', Gardens, Cape Town, 8001, South Africa',
                'coordinates'  => ['latitude' => -33.9249, 'longitude' => 18.4241, 'accuracy' => 'rooftop'],
                'context'      => [
                    'locality' => ['name' => 'Gardens'],
                    'place'    => ['name' => 'Cape Town'],
                    'postcode' => ['name' => '8001'],
                    'region'   => ['name' => $province],
                    'country'  => ['name' => 'South Africa', 'country_code' => 'ZA'],
                ],
            ], $props),
        ];
    }

    /** @return array<string,string> */
    private function parts(string $street): array
    {
        return [
            'address_line' => $street,
            'suburb'       => 'Gardens',
            'city'         => 'Cape Town',
            'province'     => 'Western Cape',
            'postal_code'  => '8001',
        ];
    }

    // ------------------------------------------------------------ temporary

    public function testSuggestionsCarryNoCoordinates(): void
    {
        $rows = $this->stub(fn () => [$this->address('12 Suggest Street')])->suggest('12 Sugg');

        $this->assertCount(1, $rows);
        $this->assertSame('12 Suggest Street', $rows[0]['address_line']);
        $this->assertSame('Gardens', $rows[0]['suburb']);
        $this->assertSame('Cape Town', $rows[0]['city']);
        $this->assertSame('Western Cape', $rows[0]['province']);
        $this->assertArrayNotHasKey('lat', $rows[0], 'a temporary coordinate must never reach the form');
        $this->assertArrayNotHasKey('lng', $rows[0]);
    }

    public function testSuggestIsATemporaryRequest(): void
    {
        $this->stub(fn () => [])->suggest('12 Temporary Street');

        $this->assertCount(1, $this->calls);
        $this->assertSame('false', $this->calls[0]['query']['permanent']);
        $this->assertSame('za', $this->calls[0]['query']['country']);
    }

    public function testSuggestIsNotCached(): void
    {
        $geocoder = $this->stub(fn () => [$this->address('12 Uncached Street')]);
        $geocoder->suggest('12 Uncached');
        $geocoder->suggest('12 Uncached');

        $this->assertCount(2, $this->calls, 'temporary results may not be cached');
    }

    public function testShortQueriesAreNotSent(): void
    {
        $this->assertSame([], $this->stub(fn () => [])->suggest('12'));
        $this->assertSame([], $this->calls);
    }

    // ------------------------------------------------------------ permanent

    public function testGeocodePartsIsPermanentAndStructured(): void
    {
        $coords = $this->stub(fn () => [$this->address('14 Structured Street')])
            ->geocodeParts($this->parts('14 Structured Street'));

        $this->assertNotNull($coords);
        $this->assertSame(-33.9249, $coords['lat']);
        $this->assertSame(18.4241, $coords['lng']);
        $this->assertSame('exact', $coords['precision']);
        $this->assertStringContainsString('14 Structured Street', $coords['label']);

        $query = $this->calls[0]['query'];
        $this->assertSame('true', $query['permanent']);
        $this->assertSame('14', $query['address_number']);
        $this->assertSame('Structured Street', $query['street']);
        $this->assertSame('Gardens', $query['locality']);
        $this->assertSame('Cape Town', $query['place']);
        $this->assertArrayNotHasKey('q', $query, 'structured input and q cannot be combined');
    }

    public function testEveryLookupInTheLadderIsPermanent(): void
    {
        $this->stub(fn () => [])->geocodeParts($this->parts('16 Nowhere Street'));

        $this->assertCount(3, $this->calls, 'structured, then free text, then the locality alone');
        foreach ($this->calls as $call) {
            $this->assertSame('true', $call['query']['permanent']);
        }
    }

    public function testFreeTextIsTriedWhenStructuredFindsNothing(): void
    {
        $coords = $this->stub(function (string $endpoint, array $query) {
            return isset($query['q']) ? [$this->address('18 Fallback Street')] : [];
        })->geocodeParts($this->parts('18 Fallback Street'));

        $this->assertNotNull($coords);
        $this->assertStringContainsString('18 Fallback Street', $this->calls[1]['query']['q']);
    }

    public function testPermanentResultsAreCached(): void
    {
        $geocoder = $this->stub(fn () => [$this->address('20 Cached Street')]);
        $geocoder->geocodeParts($this->parts('20 Cached Street'));
        $geocoder->geocodeParts($this->parts('20 Cached Street'));

        $this->assertCount(1, $this->calls);
    }

    public function testReverseIsPermanentAndKeepsTheDraggedPoint(): void
    {
        $row = $this->stub(fn () => [$this->address('22 Reverse Street')])->reverse(-33.9300001, 18.4200001);

        $this->assertNotNull($row);
        $this->assertSame('22 Reverse Street', $row['address_line']);
        $this->assertSame(-33.9300001, $row['lat']);
        $this->assertSame(18.4200001, $row['lng']);
        $this->assertSame('true', $this->calls[0]['query']['permanent']);
        $this->assertStringContainsString('/reverse', $this->calls[0]['endpoint']);
    }

    public function testReverseRefusesPointsOutsideSouthAfricaWithoutCallingOut(): void
    {
        $this->assertNull($this->stub(fn () => [])->reverse(51.5, -0.12));
        $this->assertSame([], $this->calls);
    }

    // ------------------------------------------------------------- precision

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function precisionCases(): array
    {
        return [
            'rooftop address'      => [['coordinates' => ['latitude' => -33.9249, 'longitude' => 18.4241, 'accuracy' => 'rooftop']], 'exact'],
            'interpolated address' => [['coordinates' => ['latitude' => -33.9249, 'longitude' => 18.4241, 'accuracy' => 'interpolated']], 'street'],
            'street'               => [['feature_type' => 'street'], 'street'],
            'locality'             => [['feature_type' => 'locality', 'name' => 'Gardens'], 'suburb'],
            'place'                => [['feature_type' => 'place', 'name' => 'Cape Town'], 'city'],
        ];
    }

    /**
     * @dataProvider precisionCases
     *
     * @param array<string,mixed> $props
     */
    public function testPrecisionFollowsWhatMapboxMatched(array $props, string $expected): void
    {
        $street = 'Precision ' . $expected . ' ' . md5(serialize($props));
        $coords = $this->stub(fn () => [$this->address($street, $props)])->geocodeParts($this->parts($street));

        $this->assertNotNull($coords);
        $this->assertSame($expected, $coords['precision']);
    }

    // ------------------------------------------------------------- rejection

    public function testProvinceMismatchIsRejected(): void
    {
        $coords = $this->stub(fn () => [$this->address('24 Wrong Province Road', [], 'Gauteng')])
            ->geocodeParts($this->parts('24 Wrong Province Road'));

        $this->assertNull($coords);
    }

    public function testResultOutsideSouthAfricaIsRejected(): void
    {
        $coords = $this->stub(fn () => [$this->address('26 Abroad Road', [
            'coordinates' => ['latitude' => 51.5, 'longitude' => -0.12, 'accuracy' => 'rooftop'],
        ])])->geocodeParts($this->parts('26 Abroad Road'));

        $this->assertNull($coords);
    }

    public function testAMismatchedCityIsRejected(): void
    {
        $coords = $this->stub(fn () => [$this->address('28 Other City Road', [
            'match_code' => ['confidence' => 'medium', 'place' => 'mismatched', 'region' => 'matched'],
        ])])->geocodeParts($this->parts('28 Other City Road'));

        $this->assertNull($coords);
    }

    public function testALowConfidenceMatchIsRejected(): void
    {
        $coords = $this->stub(fn () => [$this->address('30 Unsure Road', [
            'match_code' => ['confidence' => 'low'],
        ])])->geocodeParts($this->parts('30 Unsure Road'));

        $this->assertNull($coords);
    }

    /** @return array<string,array{0:array<string,string>,1:string}> */
    public static function foreignAddresses(): array
    {
        // Both seen on production, 25 Sep 2026: a South Africa-only search
        // settles for the nearest-looking local street.
        return [
            'Lisbon'    => [['address_line' => 'Av. D. João II, 50, 4º Piso', 'city' => 'Lisboa'], 'Avenue D'],
            'Hyderabad' => [['address_line' => 'Dilshuknagar', 'city' => 'Hyderabad'], 'Road Za'],
        ];
    }

    /**
     * @dataProvider foreignAddresses
     *
     * @param array<string,string> $parts
     */
    public function testAStreetSharingNoNameWithTheAddressIsRejected(array $parts, string $matched): void
    {
        $feature = $this->address($matched, ['feature_type' => 'street'], 'Eastern Cape');

        $this->assertNull($this->stub(fn () => [$feature])->geocodeParts($parts));
    }

    public function testASpellingVariantOfTheStreetIsAccepted(): void
    {
        $coords = $this->stub(fn () => [$this->address('38 Voortrekkerweg')])
            ->geocodeParts($this->parts('38 Voortrekker Road'));

        $this->assertNotNull($coords);
    }

    // -------------------------------------------------------------- failure

    public function testAFailedCallIsReportedAndNotCachedAsNoMatch(): void
    {
        $down     = true;
        $geocoder = $this->stub(function () use (&$down) {
            return $down ? null : [$this->address('32 Outage Street')];
        });

        $this->assertNull($geocoder->geocodeParts($this->parts('32 Outage Street')));
        $this->assertTrue($geocoder->failed());

        $down = false;
        $this->assertNotNull(
            $geocoder->geocodeParts($this->parts('32 Outage Street')),
            'an outage must not be remembered as "no such address"'
        );
        $this->assertFalse($geocoder->failed());
    }

    public function testANoMatchIsNotAFailure(): void
    {
        $geocoder = $this->stub(fn () => []);

        $this->assertNull($geocoder->geocodeParts($this->parts('34 Unmapped Street')));
        $this->assertFalse($geocoder->failed());
    }

    public function testAFailedReverseIsReportedAndNotCached(): void
    {
        $down     = true;
        $geocoder = $this->stub(function () use (&$down) {
            return $down ? null : [$this->address('36 Reverse Outage Street')];
        });

        $this->assertNull($geocoder->reverse(-33.9311111, 18.4211111));
        $this->assertTrue($geocoder->failed());

        $down = false;
        $this->assertNotNull($geocoder->reverse(-33.9311111, 18.4211111));
    }

    // --------------------------------------------------------------- wiring

    public function testTheTokenPicksTheProvider(): void
    {
        $_ENV['directory.mapboxToken'] = $_SERVER['directory.mapboxToken'] = 'pk.test-token';
        $this->assertInstanceOf(FallbackGeocoder::class, Services::geocoder(false));

        $_ENV['directory.mapboxToken'] = $_SERVER['directory.mapboxToken'] = '';
        $config              = config('Directory');
        $config->mapboxToken = '';
        $this->assertInstanceOf(NominatimGeocoder::class, Services::geocoder(false));
    }

    public function testNominatimOffersNoAutocomplete(): void
    {
        $this->assertSame([], (new NominatimGeocoder())->suggest('1 Adderley Street, Cape Town'));
    }
}
