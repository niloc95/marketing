<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A category's own schema.org type, set on /admin/categories. NULL means
 * automatic: schema_business_type() works it out from Config\SchemaTypes, as
 * every category did before this column.
 *
 * Additive and nullable: run it before the code copy, because the discovery
 * queries select p.schema_type from the first request.
 */
class AddSchemaTypeToCategories extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_categories', [
            'schema_type' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_categories', ['schema_type']);
    }
}
