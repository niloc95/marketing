<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bring a branch up to the same field set as the listing's own address.
 *
 * xs_directory_practice_locations was created with the six columns an
 * "Other locations" text line needed. The profile now renders a branch the way
 * it renders the primary — mini map, Get directions, trading hours, contact
 * details — and every one of those renderers already exists and is generic over
 * an array. They just address it by key.
 *
 * **The column names here are load-bearing.** map_point(), map_address_text(),
 * map_destination() and ListingGeocoder::columns() all read `latitude`,
 * `longitude`, `geocode_precision`, `address_line`, `postal_code` and so on
 * straight off whatever array they are handed. Naming a branch's latitude
 * `lat`, or its hours `hours`, would mean writing a second copy of each of
 * those functions for branches. So these mirror xs_directory_listings exactly,
 * in name and in type, and the profile gets its parity for free.
 *
 * Every column is nullable. Existing rows are real branches that predate this
 * and must keep rendering — an address and a phone number, with the map and
 * hours panels simply absent, which is the same fallback a listing that never
 * geocoded already gets.
 *
 * No spatial row and no index: branches deliberately stay out of
 * xs_directory_listing_points, so one listing remains one search result. See
 * that table's migration for what changing that would involve.
 */
class ExpandPracticeLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_practice_locations', [
            // --- address, matching the listing's own block
            'address_line_2' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'address_line'],
            'postal_code'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'province'],
            // Defaulted like the listing's: the geocoder appends a country to
            // its query, and a branch with none resolves against the whole world.
            'country'        => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'South Africa', 'after' => 'postal_code'],

            // --- contact. No website and no socials: those are business-wide in
            // practice, and a per-branch copy would only go stale against the
            // listing's own.
            'contact_person' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'name'],
            'phone_alt'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'phone'],
            'email'          => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'phone_alt'],

            // --- geo. Same DECIMAL(10,7) as the listing, so a coordinate keeps
            // the same precision wherever it is stored.
            'latitude'          => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true, 'after' => 'country'],
            'longitude'         => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true, 'after' => 'latitude'],
            'geocode_precision' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'longitude'],
            'geocoded_at'       => ['type' => 'DATETIME', 'null' => true, 'after' => 'geocode_precision'],
            'geocoded_address'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'geocoded_at'],
            'geocoding_status'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'geocoded_address'],

            // --- hours. TEXT holding the same JSON shape hours_encode() writes
            // for the listing, so hours_decode() and the hours panel need no
            // branch-specific case.
            'trading_hours' => ['type' => 'TEXT', 'null' => true, 'after' => 'geocoding_status'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_practice_locations', [
            'address_line_2', 'postal_code', 'country',
            'contact_person', 'phone_alt', 'email',
            'latitude', 'longitude', 'geocode_precision',
            'geocoded_at', 'geocoded_address', 'geocoding_status',
            'trading_hours',
        ]);
    }
}
