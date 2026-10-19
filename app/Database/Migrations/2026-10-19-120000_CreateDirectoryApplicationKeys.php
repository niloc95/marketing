<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One application per vacancy per person, for the Jobs board and /careers.
 *
 * A keyed hash of the applicant's email and phone per vacancy, nothing else:
 * no name, no message, nothing that reads back. The UNIQUE key is the whole
 * point. On 19 Oct 2026 one phone sent the same application four times in
 * eight seconds; a check-then-insert would have let all four through, an
 * insert against a unique key lets exactly one.
 *
 * target_id is the job post id or the career role id (0 = a general careers
 * application). No foreign key: it points at two tables, and the rows are
 * cleared on their own schedule (ApplicationOnceService).
 */
class CreateDirectoryApplicationKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'scope'      => ['type' => 'ENUM', 'constraint' => ['job', 'career']],
            'target_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'kind'       => ['type' => 'ENUM', 'constraint' => ['email', 'phone']],
            'hash'       => ['type' => 'CHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['scope', 'target_id', 'kind', 'hash']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('directory_application_keys', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_application_keys', true);
    }
}
