<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDirectoryPracticeLocations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'address_line' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'suburb'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'city'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'province'     => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'is_primary'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'sort_order'   => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_practice_locations', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_practice_locations', true);
    }
}
