<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The welcome pack (owner, 23 Nov 2026): a branded PDF of the profile with a
 * QR poster, emailed once when Verified or Plus starts
 * (App\Services\WelcomePackService, the only writer of both columns).
 *
 * welcome_pack_token holds the SHA-256 of the emailed download link's token
 * (TokenHash), never the raw value. welcome_pack_sent_at is the "sent once"
 * guard: a renewal or a second activation never mails the pack again.
 *
 * Two nullable columns and a unique index, additive only, so it is safe to
 * run before the code copy at release.
 */
class AddWelcomePackToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'welcome_pack_token'   => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'welcome_pack_sent_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->db->query('CREATE UNIQUE INDEX directory_listings_welcome_pack_token ON '
            . $this->db->prefixTable('directory_listings') . ' (welcome_pack_token)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX directory_listings_welcome_pack_token ON '
            . $this->db->prefixTable('directory_listings'));
        $this->forge->dropColumn('directory_listings', ['welcome_pack_token', 'welcome_pack_sent_at']);
    }
}
