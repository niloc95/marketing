<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The date a listing's paid "Verified Business" badge runs out.
 *
 * This is a render cache, not the source of truth — xs_directory_verifications
 * owns the application, its state and its payment history. The column exists
 * because the badge has to appear on every search card, and DirectoryService
 * selects xs_directory_listings.* at roughly fourteen query sites (browse,
 * featured, recent, map, related, sitemap). Joining the verifications table
 * into all of them to decide whether to draw one span would be a lot of churn
 * and a lot of new ways to get a query wrong. Copying one date across on the
 * few occasions state changes is cheaper and harder to break.
 *
 * Nothing writes it except VerificationService, inside the same transaction
 * that moves the verification row. Deliberately absent from OWNER_EDITABLE and
 * from DirectoryAdminService::upsert()'s privileged block: no form post of any
 * kind, owner or admin, can reach this column.
 *
 * NULL means "never had a badge". A past date means "had one, it lapsed" — we
 * keep it rather than nulling on lapse so the admin view and any future
 * win-back mail can tell the two apart.
 */
class AddVerifiedUntilToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'verified_until' => [
                'type' => 'DATE', 'null' => true, 'after' => 'is_featured',
            ],
        ]);

        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_verified_until` (`verified_until`)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `idx_verified_until`");
        $this->forge->dropColumn('directory_listings', 'verified_until');
    }
}
