<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Owner self-service: a short-lived, single-use magic-link token that lets the
 * person who controls a listing's email address edit it without an account.
 *
 * Separate from verify_token deliberately — that one publishes a listing on
 * first use and lives for 48h; this one only proves ownership and expires in an
 * hour. Reusing a single column would conflate the two effects.
 *
 * The email index supports the duplicate check in submitPublic(). It is
 * intentionally NOT unique: the column is nullable, and admin imports or
 * branches of one business may legitimately share or omit an address. The
 * one-listing-per-email rule lives in the service layer where it can evolve.
 */
class AddManageTokenToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'manage_token' => [
                'type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'verify_expires',
            ],
            'manage_expires' => [
                'type' => 'DATETIME', 'null' => true, 'after' => 'manage_token',
            ],
        ]);

        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_manage_token` (`manage_token`)");
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_email` (`email`)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `idx_manage_token`");
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `idx_email`");
        $this->forge->dropColumn('directory_listings', ['manage_token', 'manage_expires']);
    }
}
