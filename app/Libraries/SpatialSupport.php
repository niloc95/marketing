<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * What spatial features the database in front of us actually has.
 *
 * The SRID column attribute — `POINT NOT NULL SRID 4326` — is MySQL 8.0.3 and
 * later only. MariaDB has no equivalent syntax at all, and shared cPanel hosting
 * ships MariaDB far more often than MySQL 8. Writing the migration for one and
 * deploying onto the other fails at CREATE TABLE, which on an auto-deploy means
 * the code lands and the schema does not.
 *
 * Nothing is lost by going without it here. The SRID constraint tells the
 * optimizer a column is geographic; it does not change how ST_Distance_Sphere
 * reads the point, which is x = longitude either way (see SpatialSearchTest,
 * which pins that). The spatial index is created on both paths.
 */
class SpatialSupport
{
    /** Keyed by server version string — one probe per connection, not per call. */
    private static array $cache = [];

    /**
     * True when geometry columns can carry an SRID constraint, i.e. MySQL 8.0.3+
     * and not MariaDB.
     */
    public static function supportsSridColumns(?BaseConnection $db = null): bool
    {
        $db ??= db_connect();

        try {
            $version = (string) $db->getVersion();
        } catch (\Throwable $e) {
            // Unknown server: take the path that works everywhere.
            return false;
        }

        return self::$cache[$version] ??= self::parse($version);
    }

    /**
     * MariaDB advertises itself in the version string, sometimes behind a "5.5.5-"
     * replication-protocol prefix ("5.5.5-10.11.6-MariaDB"). Check for the name
     * before trusting any number in there.
     */
    private static function parse(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }

        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $major > 8
            || ($major === 8 && ($minor > 0 || ($minor === 0 && $patch >= 3)));
    }

    /**
     * SQL expression building a point from bound longitude/latitude placeholders,
     * carrying an SRID only where the column can hold one. Mixing an SRID-4326
     * geometry into an unconstrained column is fine on MySQL, but the two-argument
     * ST_SRID() setter is not something to rely on across MariaDB versions.
     */
    public static function pointExpression(?BaseConnection $db = null): string
    {
        return self::supportsSridColumns($db)
            ? 'ST_SRID(POINT(?, ?), 4326)'
            : 'POINT(?, ?)';
    }
}
