<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A person's "Qualifications and credentials" becomes rich text (owner,
 * 18 Nov 2026): the profile wide Credentials box is folded into the team
 * card, and its 500 characters plus markup no longer fit VARCHAR(255). The
 * cap is editorial now (TeamMemberService::MAX_CREDENTIALS_LENGTH, plain
 * text), like the description.
 *
 * Widening only, so it is safe to run before the code copy at release.
 */
class WidenTeamCredentials extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('directory_listing_team', [
            'credentials' => ['name' => 'credentials', 'type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        // Rows longer than 255 would be cut by a strict server; this only
        // runs after a backup (see the deploy-production skill).
        $this->forge->modifyColumn('directory_listing_team', [
            'credentials' => ['name' => 'credentials', 'type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
    }
}
