<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDirectoryTags extends Migration
{
    public function up(): void
    {
        // Tags (areas of specialisation)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 140],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('directory_tags', true);

        // Listing ↔ Tag pivot
        $this->forge->addField([
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tag_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ]);
        $this->forge->addKey(['listing_id', 'tag_id'], true); // composite PK
        $this->forge->addKey('tag_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'directory_tags', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_tags', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_tags', true);
        $this->forge->dropTable('directory_tags', true);
    }
}
