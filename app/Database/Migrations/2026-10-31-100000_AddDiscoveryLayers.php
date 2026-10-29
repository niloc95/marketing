<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Layers 2 to 4 of the discovery model (DiscoveryModelService, the only
 * writer of all four):
 *
 *   - discovery_related: words that go together on profiles ("cancer" →
 *     "chemotherapy"), rebuilt nightly, used to widen a search.
 *   - discovery_vocab: every word the platform uses, rebuilt nightly, so a
 *     misspelt word ("oncoligist") can be corrected from our own words.
 *   - discovery_blocks: links the owner removed at /admin/discovery, which
 *     the nightly rebuild must never bring back. The only table here that is
 *     not a cache.
 *   - discovery_events: what was typed in Discover and which result was
 *     opened. No IP, no user id, no session; purged after 180 days.
 *
 * Additive: old code neither reads nor writes them. Run it before the code copy.
 */
class AddDiscoveryLayers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'site'     => ['type' => 'VARCHAR', 'constraint' => 8],
            'term'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'related'  => ['type' => 'VARCHAR', 'constraint' => 64],
            'score'    => ['type' => 'DECIMAL', 'constraint' => '8,4'],
            'profiles' => ['type' => 'INT', 'unsigned' => true],
            'built_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['site', 'term', 'related']);
        $this->forge->createTable('directory_discovery_related');

        $this->forge->addField([
            'site'     => ['type' => 'VARCHAR', 'constraint' => 8],
            'term'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'profiles' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['site', 'term']);
        $this->forge->createTable('directory_discovery_vocab');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'site'       => ['type' => 'VARCHAR', 'constraint' => 8],
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 10],
            'term'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'target'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['site', 'kind', 'term', 'target']);
        $this->forge->createTable('directory_discovery_blocks');

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'site'       => ['type' => 'VARCHAR', 'constraint' => 8],
            'query'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'listing_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'position'   => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'results'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['site', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('directory_discovery_events');

        // Words compare exactly. The default collation is accent and case
        // blind, so "maré" and "mare" collided on the unique keys and rolled
        // the whole nightly rebuild back. discovery_terms (released 29 Oct)
        // had the same weakness; it is a nightly cache, so this is safe live.
        foreach ([
            'directory_discovery_terms'   => ['term'],
            'directory_discovery_related' => ['term', 'related'],
            'directory_discovery_vocab'   => ['term'],
            'directory_discovery_blocks'  => ['term', 'target'],
        ] as $table => $columns) {
            $modify = array_map(static fn ($c) => "MODIFY `{$c}` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL", $columns);
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable($table) . ' ' . implode(', ', $modify));
        }
    }

    public function down(): void
    {
        foreach (['directory_discovery_events', 'directory_discovery_blocks', 'directory_discovery_vocab', 'directory_discovery_related'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
