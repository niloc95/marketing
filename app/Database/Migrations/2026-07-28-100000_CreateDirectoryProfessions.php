<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDirectoryProfessions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 160],
            'group_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('is_active');
        $this->forge->createTable('directory_professions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_professions', true);
    }
}
