<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extra description cards (owner, 19 Nov 2026): up to five more headed rich
 * text cards under "About our practice" ("Procedures", "Conditions we treat"),
 * so a profile can say more than one 3,000 character description holds. First
 * asked for by Dr A. D. Bhatta (profile 392). Verified and Plus only.
 *
 * Written only by ListingSectionService. `body` is RichText::sanitise() HTML,
 * `body_text` its plain text, kept for the FULLTEXT index Discover reads.
 * ON DELETE CASCADE, as photos and brands: a card means nothing without its
 * profile.
 *
 * Additive: old code neither reads nor writes it. Run it before the code copy.
 */
class CreateDirectoryListingSections extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'heading'    => ['type' => 'VARCHAR', 'constraint' => 120],
            'body'       => ['type' => 'MEDIUMTEXT'],
            'body_text'  => ['type' => 'TEXT'],
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_sections', true);

        $prefix = $this->db->getPrefix();
        $this->db->query('ALTER TABLE ' . $prefix . 'directory_listing_sections ADD FULLTEXT INDEX ft_section (heading, body_text)');
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_sections', true);
    }
}
