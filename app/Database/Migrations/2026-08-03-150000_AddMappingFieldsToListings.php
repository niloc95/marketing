<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The address and geocoding fields the mapping module needs.
 *
 * `address_line_2` — unit, floor, building. Detail that helps a customer find
 * the door but only confuses a geocoder, so it is displayed and never sent in
 * the lookup query.
 *
 * `geocoded_address` — the provider's own normalised rendering of whatever the
 * coordinates actually resolved to. Without it, a pin in the wrong suburb can
 * only be diagnosed by re-running the lookup and hoping it fails identically;
 * with it, the row says "we matched you to Delta Road, Cape Town" and the
 * problem is obvious at a glance.
 *
 * `geocoding_status` — pending | ok | failed | manual. A NULL coordinate is
 * ambiguous: never attempted, attempted and unmatched, or attempted while
 * Nominatim was down? Each wants a different response, and only the last is
 * worth retrying in bulk. `spark directory:geocode --status failed` depends on
 * this being recorded.
 */
class AddMappingFieldsToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'address_line_2'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'address_line'],
            'geocoded_address' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'geocoded_at'],
            'geocoding_status' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'geocoded_address'],
        ]);

        // Backfill what is already knowable, so the column is trustworthy from
        // the first query rather than only for rows saved after this deploy.
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query(
            "UPDATE `{$table}` SET `geocoding_status` = CASE
                WHEN `geocode_precision` = 'manual' THEN 'manual'
                WHEN `latitude` IS NOT NULL AND `longitude` IS NOT NULL THEN 'ok'
                WHEN `geocoded_at` IS NOT NULL THEN 'failed'
                ELSE 'pending'
             END"
        );
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['address_line_2', 'geocoded_address', 'geocoding_status']);
    }
}
