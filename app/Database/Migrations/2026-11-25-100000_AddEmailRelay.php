<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Email a business through the platform (owner, 12 Nov 2026).
 *
 * - `directory_listings.email_public`: the owner's "Show my email address on
 *   my profile" switch. 0 for every profile, old and new: addresses are
 *   private unless an owner chooses otherwise. Visitors email the business
 *   through ListingMessageService whatever this says.
 * - `directory_listing_messages`: one row per message a visitor sent (or we
 *   refused), written only by ListingMessageService. The message text, name
 *   and address are never stored: the sender and IP are sha256 hashes, kept
 *   for the abuse limits and the owner's monthly count. Purged after 90 days.
 *
 * Additive: old code neither reads nor writes either. Run it before the code copy.
 */
class AddEmailRelay extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'email_public' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
        ]);

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => ['type' => 'INT', 'unsigned' => true],
            'location_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sender_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'ip_hash'     => ['type' => 'CHAR', 'constraint' => 64],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 12],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['listing_id', 'created_at']);
        $this->forge->addKey(['sender_hash', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('directory_listing_messages');
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_messages', true);
        $this->forge->dropColumn('directory_listings', 'email_public');
    }
}
