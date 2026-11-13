<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The signup category matcher ("What does your business do?",
 * CategoryMatchService, the only writer of both):
 *
 *   - directory_category_matches: what a business typed when it saved its
 *     profile, the categories we offered and the one it chose. The ones we
 *     could not match are listed on /admin/discovery. Purged after 180 days
 *     by discovery:learn.
 *   - directory_category_words: wording an admin linked to a category on
 *     /admin/discovery, so the next business that types it gets that
 *     category first. Not a cache: the nightly rebuild never touches it.
 *
 * Additive: old code neither reads nor writes them. Run it before the code copy.
 */
class AddCategoryMatches extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'site'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'text'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'text_key'    => ['type' => 'VARCHAR', 'constraint' => 120],
            'listing_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'category_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'offered'     => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => ''],
            'matched'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['site', 'matched', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('directory_category_matches');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'site'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'phrase'      => ['type' => 'VARCHAR', 'constraint' => 120],
            'category_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['site', 'phrase']);
        $this->forge->addForeignKey('category_id', 'directory_categories', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_category_words');

        // Wording compares exactly, as the discovery tables do (see
        // AddDiscoveryLayers): "maré" and "mare" are two phrases.
        foreach (['directory_category_matches' => ['text', 'text_key'], 'directory_category_words' => ['phrase']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->db->query('ALTER TABLE ' . $this->db->prefixTable($table) . ' MODIFY ' . $column . ' VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_category_words', true);
        $this->forge->dropTable('directory_category_matches', true);
    }
}
