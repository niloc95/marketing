<?php

use App\Libraries\Geocoding\FallbackGeocoder;
use App\Libraries\Geocoding\GeocoderInterface;
use App\Libraries\Geocoding\MapboxGeocoder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Nominatim answers only when Mapbox couldn't: a paused account, a revoked
 * token, an outage. A clean "no match" from Mapbox stays a no match.
 *
 * @internal
 */
final class FallbackGeocoderTest extends CIUnitTestCase
{
    /** @var list<string> which secondary methods were called */
    private array $secondaryCalls = [];

    /** Mapbox whose every request fails ($down) or finds nothing. */
    private function mapbox(bool $down): MapboxGeocoder
    {
        return new class ('pk.test-token', $down) extends MapboxGeocoder {
            public function __construct(string $token, private readonly bool $down)
            {
                parent::__construct($token);
            }

            protected function request(string $endpoint, array $query, string $context): array
            {
                if ($this->down) {
                    $this->failed = true;
                }

                return [];
            }
        };
    }

    private function nominatim(): GeocoderInterface
    {
        $calls = &$this->secondaryCalls;

        return new class ($calls) implements GeocoderInterface {
            /** @var array<int,string> */
            private array $calls;

            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function suggest(string $query, int $limit = 5): array
            {
                $this->calls[] = 'suggest';

                return [];
            }

            public function geocodeParts(array $parts): ?array
            {
                $this->calls[] = 'geocodeParts';

                return ['lat' => -33.9249, 'lng' => 18.4241, 'precision' => 'street'];
            }

            public function reverse(float $lat, float $lng): ?array
            {
                $this->calls[] = 'reverse';

                return [
                    'label' => 'Nominatim', 'address_line' => '', 'suburb' => 'Gardens', 'city' => 'Cape Town',
                    'province' => 'Western Cape', 'postal_code' => '8001', 'lat' => $lat, 'lng' => $lng, 'precision' => 'suburb',
                ];
            }
        };
    }

    /** @return array<string,string> distinct per test so the shared cache can't leak */
    private function parts(string $street): array
    {
        return ['address_line' => $street, 'suburb' => 'Gardens', 'city' => 'Cape Town', 'province' => 'Western Cape'];
    }

    public function testGeocodeFallsBackWhenMapboxFailed(): void
    {
        $coords = (new FallbackGeocoder($this->mapbox(true), $this->nominatim()))
            ->geocodeParts($this->parts('40 Fallback Road'));

        $this->assertSame('street', $coords['precision'] ?? null);
        $this->assertSame(['geocodeParts'], $this->secondaryCalls);
    }

    public function testGeocodeDoesNotFallBackOnANoMatch(): void
    {
        $coords = (new FallbackGeocoder($this->mapbox(false), $this->nominatim()))
            ->geocodeParts($this->parts('42 Nomatch Road'));

        $this->assertNull($coords);
        $this->assertSame([], $this->secondaryCalls);
    }

    public function testReverseFallsBackWhenMapboxFailed(): void
    {
        $row = (new FallbackGeocoder($this->mapbox(true), $this->nominatim()))->reverse(-33.9322222, 18.4222222);

        $this->assertSame('Nominatim', $row['label'] ?? null);
        $this->assertSame(['reverse'], $this->secondaryCalls);
    }

    public function testReverseDoesNotFallBackOnANoMatch(): void
    {
        $row = (new FallbackGeocoder($this->mapbox(false), $this->nominatim()))->reverse(-33.9333333, 18.4233333);

        $this->assertNull($row);
        $this->assertSame([], $this->secondaryCalls);
    }

    public function testSuggestNeverAsksTheSecondary(): void
    {
        $rows = (new FallbackGeocoder($this->mapbox(true), $this->nominatim()))->suggest('44 Typeahead Road');

        $this->assertSame([], $rows);
        $this->assertSame([], $this->secondaryCalls);
    }
}
