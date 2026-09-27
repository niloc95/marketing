<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A listed business answering a "service required" post.
 *
 * The unique (post_id, listing_id) key is the one-response-per-business rule;
 * the cap of five per post lives in App\Services\JobBoardService, as Bark caps
 * its leads, so a requester is not flooded. Both cascade: a response means
 * nothing without its post or its business.
 */
class CreateDirectoryJobResponses extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'post_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'message'    => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['post_id', 'listing_id']);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('post_id', 'directory_job_posts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_job_responses', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_job_responses', true);
    }
}
