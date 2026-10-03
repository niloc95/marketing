<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Report this review" clicks, the same shape as directory_job_reports. The
 * unique (review_id, ip_hash) key makes one visitor count once. The IP is
 * stored hashed: it is only ever compared. digested_at is stamped when the
 * nightly digest has told the admin about the report.
 */
class CreateDirectoryReviewReports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'review_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ip_hash'     => ['type' => 'CHAR', 'constraint' => 64],
            'by_owner'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'digested_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['review_id', 'ip_hash']);
        $this->forge->addKey('digested_at');
        $this->forge->addForeignKey('review_id', 'directory_reviews', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_review_reports', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_review_reports', true);
    }
}
