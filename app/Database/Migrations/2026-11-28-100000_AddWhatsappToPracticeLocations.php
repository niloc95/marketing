<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A WhatsApp number per branch (owner, 18 Nov 2026):
 * `directory_practice_locations.whatsapp`.
 *
 * A practice with rooms at two hospitals has a WhatsApp line per set of rooms
 * (first asked for by Dr A. D. Bhatta, profile 392). Stored as wa.me digits,
 * like the listing's own `whatsapp`, written only by PracticeLocationService.
 *
 * Additive: old code neither reads nor writes it. Run it before the code copy.
 */
class AddWhatsappToPracticeLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_practice_locations', [
            'whatsapp' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_practice_locations', 'whatsapp');
    }
}
