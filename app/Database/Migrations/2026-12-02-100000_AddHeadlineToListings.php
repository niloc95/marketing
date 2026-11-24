<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The profile's second title (owner, 24 Nov 2026): an optional line under the
 * practice or business name, e.g. "Specialist Orthopaedic Surgeon in Durban,
 * Partner at BMR Orthopaedics". Shown only when filled in.
 *
 * One nullable column, additive only, so it is safe to run before the code
 * copy at release.
 */
class AddHeadlineToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'headline' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'headline');
    }
}
