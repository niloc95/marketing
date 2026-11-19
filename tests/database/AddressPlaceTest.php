<?php

use App\Libraries\Geocoding\NominatimGeocoder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;

/**
 * GET /address-place, the "Find it by name" box above the address fields
 * (owner, 19 Nov 2026, after Netcare N17 Hospital was filed in the wrong town).
 * The lookup rules are pinned in tests/unit/NominatimGeocoderTest.php; this
 * checks the endpoint and that the signup form offers the box.
 *
 * @internal
 */
final class AddressPlaceTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    /** @var list<array{string,string}> */
    public static array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        self::$asked = [];
        Services::injectMock('placeFinder', new class () extends NominatimGeocoder {
            public function findPlaces(string $name, string $country = 'ZA', int $limit = 5): array
            {
                AddressPlaceTest::$asked[] = [$name, $country];

                return [['name' => 'Netcare N17 Hospital', 'label' => '7 Tonk Meter Way, Selection Park, Springs',
                    'address_line' => '7 Tonk Meter Way', 'suburb' => 'Selection Park', 'city' => 'Springs',
                    'province' => 'Gauteng', 'postal_code' => '1559', 'lat' => -26.2714586, 'lng' => 28.4277715,
                    'precision' => 'exact']];
            }
        });
    }

    protected function tearDown(): void
    {
        Services::resetSingle('placeFinder');
        parent::tearDown();
    }

    public function testFindsAPlaceByName(): void
    {
        $result = $this->get('address-place', ['q' => 'Netcare N17 Hospital']);

        $result->assertStatus(200);
        $rows = json_decode($result->getJSON(), true);
        $this->assertSame('Springs', $rows[0]['city']);
        $this->assertSame('exact', $rows[0]['precision']);
        $this->assertSame([['Netcare N17 Hospital', 'ZA']], self::$asked);
    }

    public function testShortQueriesAreNotLookedUp(): void
    {
        $result = $this->get('address-place', ['q' => 'N1']);

        $this->assertSame([], json_decode($result->getJSON(), true));
        $this->assertSame([], self::$asked);
    }

    public function testTheCountryFollowsTheForm(): void
    {
        $this->get('address-place', ['q' => 'Princess Marina Hospital', 'country' => 'Botswana']);

        $this->assertSame([['Princess Marina Hospital', 'BW']], self::$asked);
    }

    public function testTheSignupFormOffersTheFinderWithItsCredit(): void
    {
        $html = (string) $this->get('add-profile')->getBody();

        $this->assertStringContainsString('data-place-finder', $html);
        $this->assertStringContainsString('data-place-url="' . base_url('address-place') . '"', $html);
        $this->assertStringContainsString('OpenStreetMap', $html);
        // The name box only drives the script; it must never post.
        $this->assertMatchesRegularExpression('/<input[^>]*data-place-query[^>]*>/', $html);
        preg_match('/<input[^>]*data-place-query[^>]*>/', $html, $m);
        $this->assertStringNotContainsString('name=', $m[0]);
        $this->assertStringContainsString('data-place-name-target', $html);
    }
}
