<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Five profile types that are not businesses: ngo, community, foundation,
 * project and place. Keys match DirectoryListingModel::TYPES.
 *
 * Widening an ENUM is backward compatible: old code only ever writes the first
 * three values. Run it before the code copy, or a signup choosing a new type
 * fails on the insert.
 *
 * down() is deliberately a no-op. Narrowing the ENUM would blank every row on
 * a new type, and the wider column is harmless to the old code a rollback
 * puts back. The test suite rolls every migration back between tests, so a
 * down() that refused would break it too.
 */
class ExtendListingTypes extends Migration
{
    private const NEW = ['person', 'practice', 'facility', 'ngo', 'community', 'foundation', 'project', 'place'];

    public function up(): void
    {
        $this->setTypes(self::NEW);
    }

    public function down(): void
    {
    }

    /** @param list<string> $types */
    private function setTypes(array $types): void
    {
        $this->forge->modifyColumn('directory_listings', [
            'type' => ['type' => 'ENUM', 'constraint' => $types, 'null' => false, 'default' => 'person'],
        ]);
    }
}
