<?php

namespace App\Database\Migrations;

use App\Models\DirectoryCategoryGroupModel;
use CodeIgniter\Database\Migration;

/**
 * `directory_category_groups` — the main-category level, one row per
 * categories.group_name, so the groups can be shown in an order someone chose
 * rather than alphabetically (which put an admin-added "Alternative…" group at
 * the top of every picker).
 *
 * Joined by name, not id: see DirectoryCategoryGroupModel for why group_name
 * stays the key. Additive only — nothing reads this table until the code that
 * does ships, so it can run before the code copy.
 *
 * Backfilled from the group names already in use, placed by DEFAULT_ORDER.
 */
class CreateDirectoryCategoryGroups extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('directory_category_groups', true);

        $names = array_column(
            $this->db->table('directory_categories')
                ->distinct()
                ->select('group_name')
                ->where('group_name IS NOT NULL')
                ->where('group_name !=', '')
                ->get()->getResultArray(),
            'group_name'
        );

        $model = new DirectoryCategoryGroupModel($this->db);
        foreach ($names as $name) {
            $model->ensure((string) $name);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_category_groups', true);
    }
}
