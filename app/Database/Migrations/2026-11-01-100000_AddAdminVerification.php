<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An admin building a profile for a customer picks Free or Verified, and can
 * verify it from the edit page (VerificationService::grantByAdmin()).
 *
 * - listings.admin_plan: 'free' or 'verified' (null is free). Verified opens
 *   the team, branches and video on the admin form and the previews
 *   (DirectoryAdminService::asBuilt()); the public page still waits for a
 *   live badge. Admin only, never in OWNER_EDITABLE.
 * - submissions.admin_note: how the admin checked the business ("documents
 *   by email", "CIPC checked"). With no documents attached it is the only
 *   record of why the badge was given, so it lives beside the decision.
 *
 * Additive and nullable: old code neither reads nor writes them. Run it before
 * the code copy.
 */
class AddAdminVerification extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'admin_plan' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
        ]);
        $this->forge->addColumn('directory_verification_submissions', [
            'admin_note' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'admin_plan');
        $this->forge->dropColumn('directory_verification_submissions', 'admin_note');
    }
}
