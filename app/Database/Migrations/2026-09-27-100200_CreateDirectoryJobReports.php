<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Report this post" clicks. The unique (post_id, ip_hash) key makes one
 * visitor count once, so three reports means three people, not one person
 * clicking three times. The IP is stored hashed: it is only ever compared.
 */
class CreateDirectoryJobReports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'post_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'reason'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ip_hash'    => ['type' => 'CHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['post_id', 'ip_hash']);
        $this->forge->addForeignKey('post_id', 'directory_job_posts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_job_reports', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_job_reports', true);
    }
}
