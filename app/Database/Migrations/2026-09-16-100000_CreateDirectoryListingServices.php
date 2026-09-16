<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Services & prices" — what a business actually sells, one row per service.
 *
 * Until now this only existed as prose inside the description, which is why
 * descriptions ran to thousands of characters and still did not answer "do you
 * do X, and roughly what does it cost". A short list answers both at a glance.
 *
 * `price_label` is free text, not a DECIMAL: service businesses quote "from
 * R150", "R350/hour" or "POA", and forcing those into a number would either
 * lose the qualifier or push owners back into the description to explain it.
 *
 * No `slug` and no stable ids in the form: rows carry no files, so the owner
 * form replaces the whole set on save, the way tags do.
 */
class CreateDirectoryListingServices extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'price_label' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'comment' => 'Free text: R250, from R150, POA'],
            'sort_order'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_services', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_services', true);
    }
}
