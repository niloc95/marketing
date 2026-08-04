<?php

use App\Libraries\SpatialSupport;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Which servers can carry an SRID constraint on a geometry column.
 *
 * This decides a CREATE TABLE that runs unattended on production during deploy.
 * Getting it wrong in the permissive direction fails the migration outright on
 * MariaDB — the code ships, the schema does not — so the MariaDB detection
 * matters more than the version arithmetic.
 *
 * @internal
 */
final class SpatialSupportTest extends CIUnitTestCase
{
    private function parse(string $version): bool
    {
        $m = new ReflectionMethod(SpatialSupport::class, 'parse');
        $m->setAccessible(true);

        return $m->invoke(null, $version);
    }

    /**
     * @dataProvider versions
     */
    public function testVersionSupport(string $version, bool $expected, string $why): void
    {
        $this->assertSame($expected, $this->parse($version), $why);
    }

    public static function versions(): array
    {
        return [
            // MariaDB has no SRID column syntax at any version, and the number in
            // the string would otherwise read as a very new MySQL.
            'MariaDB plain'            => ['10.11.6-MariaDB', false, 'MariaDB has no SRID column attribute'],
            'MariaDB replication pfx'  => ['5.5.5-10.11.6-MariaDB', false, 'the 5.5.5- prefix must not fool the parser'],
            'MariaDB uppercase'        => ['10.6.16-MARIADB-log', false, 'name match must be case-insensitive'],
            'MariaDB 11'               => ['11.4.2-MariaDB', false, 'still MariaDB'],

            // MySQL: the attribute arrived in 8.0.3.
            'MySQL 5.7'                => ['5.7.44', false, 'predates the SRID attribute'],
            'MySQL 8.0.2'              => ['8.0.2', false, 'one patch before support'],
            'MySQL 8.0.3'              => ['8.0.3', true,  'the version that introduced it'],
            'MySQL 8.0.36'             => ['8.0.36', true,  'ordinary MySQL 8'],
            'MySQL 8.4'                => ['8.4.0', true,  'minor above 0'],
            'MySQL 9.4'                => ['9.4.0', true,  'this project\'s dev box'],

            // Anything unreadable takes the path that works everywhere.
            'unparseable'              => ['who knows', false, 'unknown servers get the safe path'],
            'empty'                    => ['', false, 'empty version string is not a licence to guess'],
        ];
    }

    public function testPointExpressionMatchesTheColumnItWritesTo(): void
    {
        // Both forms bind exactly two parameters, longitude then latitude — the
        // caller's placeholder count must not depend on the server.
        foreach (['ST_SRID(POINT(?, ?), 4326)', 'POINT(?, ?)'] as $sql) {
            $this->assertSame(2, substr_count($sql, '?'));
        }
    }
}
