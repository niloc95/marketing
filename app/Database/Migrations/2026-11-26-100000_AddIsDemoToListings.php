<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Demo profiles (owner, 12 Nov 2026): `directory_listings.is_demo`.
 *
 * A demo is a made up business (Fernwood Dental Studio, `php spark
 * demo:fernwood`) that shows what a profile and online booking look like.
 * DirectoryService::published() leaves it out of every discovery read, the
 * profile and booking pages open by direct link only, carry a demo banner, are
 * noindex with no structured data, and Book::store() never takes a booking.
 * Set only by that command, never by a form (not in OWNER_EDITABLE).
 *
 * Additive: old code neither reads nor writes it. Run it before the code copy.
 */
class AddIsDemoToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'is_demo' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'is_demo');
    }
}
