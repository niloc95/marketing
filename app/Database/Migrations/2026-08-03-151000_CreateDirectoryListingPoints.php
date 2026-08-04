<?php

namespace App\Database\Migrations;

use App\Libraries\SpatialSupport;
use CodeIgniter\Database\Migration;

/**
 * Spatial index for "businesses near me".
 *
 * A side table rather than a column on directory_listings, for one hard reason:
 * MySQL cannot put a SPATIAL INDEX on a nullable column, and plenty of listings
 * have no coordinates at all — OpenStreetMap has never heard of a fair number of
 * real South African suburbs, so geocoding genuinely fails. Keeping the point in
 * its own table lets `location` be honestly NOT NULL, with a row existing only
 * for listings that actually have a position. The alternative, a sentinel like
 * POINT(0,0), would put every ungeocoded business in the Atlantic and require
 * every query to remember to exclude it.
 *
 * `latitude`/`longitude` on the listing stay the readable source of truth. This
 * is the index, written alongside them by ListingGeocoder and deleted when the
 * coordinates are cleared.
 *
 * Two things to know before touching this:
 *
 * 1. Points are stored as POINT(longitude, latitude) — x then y — which is what
 *    ST_Distance_Sphere reads regardless of SRID. EPSG:4326 formally declares
 *    the opposite axis order, so this looks wrong and is not; MySQL's spherical
 *    distance functions take x as longitude either way. Swap them and every
 *    distance is silently wrong: nothing errors, coordinates stay in range, and
 *    Johannesburg to Cape Town simply reports 1328km instead of 1262km. There
 *    is a test pinning a known distance for exactly this reason.
 * 2. Forge has no spatial or SRID support, hence the raw SQL, matching how the
 *    FULLTEXT index and the manage-token indexes are already created.
 *
 * Keyed on listing_id alone today, which is what makes one listing = one point.
 * Multiple locations per business, when it comes, is a change to this primary
 * key and nothing else.
 */
class CreateDirectoryListingPoints extends Migration
{
    public function up(): void
    {
        $table    = $this->db->prefixTable('directory_listing_points');
        $listings = $this->db->prefixTable('directory_listings');

        // `SRID 4326` on a column is MySQL 8.0.3+ syntax and does not exist in
        // MariaDB, which is what most cPanel hosting runs. Deploys are automatic,
        // so a version-specific CREATE TABLE would ship the code and fail the
        // schema. The constraint is an optimizer hint, not a correctness one:
        // ST_Distance_Sphere reads x as longitude with or without it, and the
        // spatial index is created either way. See App\Libraries\SpatialSupport.
        $srid  = SpatialSupport::supportsSridColumns($this->db);
        $point = $srid ? 'POINT NOT NULL SRID 4326' : 'POINT NOT NULL';

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `listing_id` INT(11) UNSIGNED NOT NULL,
                `location` {$point},
                PRIMARY KEY (`listing_id`),
                SPATIAL INDEX `sp_listing_location` (`location`),
                CONSTRAINT `fk_listing_points_listing`
                    FOREIGN KEY (`listing_id`) REFERENCES `{$listings}` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Seed from coordinates already on file so "near me" works against the
        // existing directory immediately, without waiting for a re-geocode.
        $seedPoint = $srid
            ? 'ST_SRID(POINT(`longitude`, `latitude`), 4326)'
            : 'POINT(`longitude`, `latitude`)';

        $this->db->query(
            "INSERT IGNORE INTO `{$table}` (`listing_id`, `location`)
             SELECT `id`, {$seedPoint}
             FROM `{$listings}`
             WHERE `latitude` IS NOT NULL AND `longitude` IS NOT NULL
               AND NOT (`latitude` = 0 AND `longitude` = 0)
               AND `deleted_at` IS NULL"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_points', true);
    }
}
