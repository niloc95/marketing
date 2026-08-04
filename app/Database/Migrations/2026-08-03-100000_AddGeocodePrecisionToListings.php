<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How good is a listing's pin, and when was it last worked out?
 *
 * OpenStreetMap's South African coverage is thin enough that most listings
 * resolve to their street or their suburb rather than their exact door.
 * Storing which of those happened lets the public profile zoom its map to
 * match and say "approximate location" instead of implying a rooftop-accurate
 * pin. geocoded_at makes a stale or never-attempted lookup visible without
 * having to infer it from a NULL coordinate.
 */
class AddGeocodePrecisionToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'geocode_precision' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'longitude'],
            'geocoded_at'       => ['type' => 'DATETIME', 'null' => true, 'after' => 'geocode_precision'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['geocode_precision', 'geocoded_at']);
    }
}
