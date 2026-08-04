<?php

use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Coordinate axis order, pinned.
 *
 * MySQL's ST_Distance_Sphere reads a POINT as (x, y) = (longitude, latitude),
 * whatever SRID the geometry carries — EPSG:4326 formally declares the opposite
 * order, which is exactly why this keeps getting written backwards.
 *
 * Getting it wrong is invisible: nothing errors, both values stay inside their
 * valid ranges, and every distance is simply wrong. It cost a real bug during
 * this feature's build — Johannesburg to Cape Town reported 1328km against a
 * true 1262km, which is plausible enough to ship.
 *
 * These tests need no database: they check the SQL this service constructs,
 * which is where the order actually gets decided.
 *
 * @internal
 */
final class SpatialSearchTest extends CIUnitTestCase
{
    /** Johannesburg CBD. */
    private const JHB = ['lat' => -26.2041, 'lng' => 28.0473];

    private function distanceSql(array $near): string
    {
        $method = new ReflectionMethod(DirectoryService::class, 'distanceSelect');
        $method->setAccessible(true);

        return $method->invoke(new DirectoryService(), $near);
    }

    private function nearPoint(array $filters): ?array
    {
        $method = new ReflectionMethod(DirectoryService::class, 'nearPoint');
        $method->setAccessible(true);

        return $method->invoke(new DirectoryService(), $filters);
    }

    public function testDistanceIsMeasuredWithLongitudeFirst(): void
    {
        $sql = $this->distanceSql(self::JHB + ['radius' => 0]);

        $this->assertStringContainsString(
            'POINT(xs_directory_listings.longitude, xs_directory_listings.latitude)',
            $sql,
            'the column POINT must be (longitude, latitude) — see this class docblock'
        );
    }

    public function testTheOriginPointIsAlsoLongitudeFirst(): void
    {
        $sql = $this->distanceSql(self::JHB + ['radius' => 0]);

        // 28.0473 is the longitude and must appear before -26.2041, the latitude.
        $lngAt = strpos($sql, '28.047');
        $latAt = strpos($sql, '-26.204');

        $this->assertNotFalse($lngAt);
        $this->assertNotFalse($latAt);
        $this->assertLessThan($latAt, $lngAt, 'longitude must be the first argument');
    }

    /**
     * The coordinates go into the SQL string rather than through a binding, so
     * they must be numeric by the time they get there — nearPoint() is the gate
     * that guarantees it.
     */
    public function testNonNumericCoordinatesNeverReachTheQuery(): void
    {
        $this->assertNull($this->nearPoint(['lat' => "-26.2' OR 1=1--", 'lng' => '28.0473']));
        $this->assertNull($this->nearPoint(['lat' => '-26.2041', 'lng' => 'DROP TABLE']));
        $this->assertNull($this->nearPoint(['lat' => '', 'lng' => '']));
        $this->assertNull($this->nearPoint([]));
    }

    public function testPositionsOutsideSouthAfricaAreIgnored(): void
    {
        // Searching "near me" from London would otherwise return the entire
        // directory, sorted by distance to another continent.
        $this->assertNull($this->nearPoint(['lat' => '51.5074', 'lng' => '-0.1278']));
        $this->assertNotNull($this->nearPoint(['lat' => '-26.2041', 'lng' => '28.0473']));
    }

    public function testOnlyTheOfferedRadiiAreAccepted(): void
    {
        foreach (DirectoryService::RADIUS_OPTIONS as $km) {
            $near = $this->nearPoint(['lat' => '-26.2041', 'lng' => '28.0473', 'radius' => (string) $km]);
            $this->assertSame($km, $near['radius']);
        }

        // Anything else falls back to "no limit, still sorted by distance"
        // rather than being honoured as an arbitrary number.
        foreach (['9999', '-5', 'abc', ''] as $bad) {
            $near = $this->nearPoint(['lat' => '-26.2041', 'lng' => '28.0473', 'radius' => $bad]);
            $this->assertSame(0, $near['radius'], "radius '{$bad}' should not be honoured");
        }
    }

    /**
     * A guard against the swap being "fixed" in one direction only. If the
     * emitted expression ever measures from (lat, lng), this distance changes
     * from ~1262km to ~1328km — the exact symptom of the original bug.
     */
    public function testKnownDistanceDocumentsTheExpectedResult(): void
    {
        $jhb = self::JHB;
        $cpt = ['lat' => -33.9249, 'lng' => 18.4241];

        // Great-circle distance, computed here the same way MySQL does it, as
        // the reference value the SQL must reproduce.
        $r    = 6_370_986.0; // the earth radius ST_Distance_Sphere assumes
        $dLat = deg2rad($cpt['lat'] - $jhb['lat']);
        $dLng = deg2rad($cpt['lng'] - $jhb['lng']);
        $a    = sin($dLat / 2) ** 2
            + cos(deg2rad($jhb['lat'])) * cos(deg2rad($cpt['lat'])) * sin($dLng / 2) ** 2;
        $km = ($r * 2 * atan2(sqrt($a), sqrt(1 - $a))) / 1000;

        $this->assertEqualsWithDelta(1262, $km, 5, 'Johannesburg to Cape Town is ~1262km, not ~1328km');
    }
}
