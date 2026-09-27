<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The Jobs board: one row per post, of two kinds.
 *
 *   job     → a vacancy. Gets JobPosting structured data, so Google for Jobs
 *             can list it; that is why valid_through is NOT NULL — Google
 *             penalises undated jobs, and a board full of stale ones can have
 *             every job it hosts removed.
 *   service → "I need a plumber in Durban". Answered by listed businesses
 *             only (directory_job_responses). Not a JobPosting, and noindex.
 *
 * listing_id NULL means the poster is not in the directory. Their contact
 * details live in the poster_* columns, are never rendered publicly, and are
 * nulled by `spark jobs:expire` a year after the post ends (POPIA retention).
 * ON DELETE SET NULL rather than CASCADE: a business leaving the directory
 * should not silently delete the applications trail an admin may need.
 *
 * Tokens are stored as sha256 hashes, as on the listings table — see
 * App\Libraries\TokenHash.
 *
 * price_cents is unused at launch (posting is free) and exists so a PayFast
 * posting fee can be switched on without another migration.
 */
class CreateDirectoryJobPosts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'kind'            => ['type' => 'VARCHAR', 'constraint' => 10, 'comment' => 'job | service'],
            'slug'            => ['type' => 'VARCHAR', 'constraint' => 190],
            'listing_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'comment' => 'FK xs_directory_listings; NULL = unlisted poster'],

            'poster_name'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'poster_email'    => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'poster_phone'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'company_name'    => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'comment' => 'Shown publicly'],

            'title'           => ['type' => 'VARCHAR', 'constraint' => 150],
            'description'     => ['type' => 'TEXT'],
            'category_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'province'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'is_remote'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],

            'employment_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'salary_min'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'salary_max'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'salary_period'   => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'apply_url'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'apply_email'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],

            'budget_text'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'needed_by'       => ['type' => 'DATE', 'null' => true],

            'status'          => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'unverified'],
            'reject_reason'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'flagged_reason'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

            'verify_token'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'verify_expires'  => ['type' => 'DATETIME', 'null' => true],
            'manage_token'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'manage_expires'  => ['type' => 'DATETIME', 'null' => true],

            'published_at'    => ['type' => 'DATETIME', 'null' => true],
            'valid_through'   => ['type' => 'DATE'],
            'reminded_at'     => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Expiry reminder sent'],
            'ended_at'        => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Closed, expired or rejected; starts the retention clock'],
            'report_count'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'response_count'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'price_cents'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],

            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'kind', 'valid_through']);
        $this->forge->addKey('listing_id');
        $this->forge->addKey('category_id');
        $this->forge->addKey('verify_token');
        $this->forge->addKey('manage_token');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('category_id', 'directory_categories', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('directory_job_posts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_job_posts', true);
    }
}
