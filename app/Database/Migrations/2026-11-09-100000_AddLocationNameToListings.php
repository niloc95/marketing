<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The primary location's own name ("Sandton branch", "Main practice"), the
 * counterpart of xs_directory_practice_locations.name for the listing's own
 * address. NULL means unnamed: profile_location_label() then falls back to the
 * suburb, as every primary location did before this column.
 *
 * Additive and nullable: run it before the code copy, because the model writes
 * it on the first save.
 */
class AddLocationNameToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'location_name' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['location_name']);
    }
}
