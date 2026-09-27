<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The daily report digest (JobBoardService::reportDigest) marks each report
 * it has told the admin about. Below the three-report threshold a report
 * hides nothing and emails nobody, so without the digest a single "fake
 * company" report was only visible to an admin who went looking for it.
 *
 * NULL means "not yet in a digest". Stamped only after the email goes out,
 * so a failed send is retried the next night.
 */
class AddDigestedAtToJobReports extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_job_reports', [
            'digested_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'ip_hash',
                'comment' => 'When the admin report digest included this report',
            ],
        ]);

        $table = $this->db->prefixTable('directory_job_reports');
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_digested_at` (`digested_at`)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_job_reports');
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `idx_digested_at`");
        $this->forge->dropColumn('directory_job_reports', 'digested_at');
    }
}
