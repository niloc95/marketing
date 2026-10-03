<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Mobile / service-area businesses: where customers meet the business, whether
 * the stored address is shown publicly, and the areas it travels to.
 *
 * The address itself stays required and stored. show_address only decides what
 * the public pages render — listing_public_view() is the one place that applies
 * it — and it can only be 0 when customer_location is 'travel'.
 *
 * service_areas is one area per line, not a table: a short display list with no
 * taxonomy, searched with LIKE. Not tags, because syncListingTags() replaces the
 * whole tag set on every save.
 *
 * Additive and defaulted to today's behaviour (visit / shown / none): old code
 * neither reads nor writes these columns. Run it before the code copy —
 * mapPoints() names its columns.
 */
class AddServiceAreaToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'customer_location' => ['type' => 'ENUM', 'constraint' => ['visit', 'travel', 'both'], 'null' => false, 'default' => 'visit'],
            'show_address'      => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'service_areas'     => ['type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['customer_location', 'show_address', 'service_areas']);
    }
}
