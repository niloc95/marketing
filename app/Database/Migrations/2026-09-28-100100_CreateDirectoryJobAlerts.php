<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which businesses were alerted about which service request.
 *
 * The unique (post_id, listing_id) key is what makes alerting safe to repeat:
 * a renewal, a re-approval or a crashed run never emails a business twice
 * about the same request. The rows also back the per-business daily cap.
 */
class CreateDirectoryJobAlerts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'post_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['post_id', 'listing_id']);
        $this->forge->addKey(['listing_id', 'created_at']);
        $this->forge->addForeignKey('post_id', 'directory_job_posts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_job_alerts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_job_alerts', true);
    }
}
