<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the discovery model learned from the profiles: for each word, the
 * categories it is typical of ("cancer" → Oncologist), per site. Written only
 * by DiscoveryModelService, rebuilt in full every night by discovery:learn,
 * so the rows are a cache of the profiles and nothing is lost by dropping it.
 *
 * No foreign key to categories: the lookup joins them on is_active, and a
 * deleted category simply stops matching until the next rebuild.
 *
 * Additive: old code neither reads nor writes it. Run it before the code copy.
 */
class AddDiscoveryTerms extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'site'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'term'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'category_id' => ['type' => 'INT', 'unsigned' => true],
            'score'       => ['type' => 'DECIMAL', 'constraint' => '8,4'],
            'profiles'    => ['type' => 'INT', 'unsigned' => true],
            'built_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['site', 'term', 'category_id']);
        $this->forge->createTable('directory_discovery_terms');
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_discovery_terms', true);
    }
}
