<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customer reviews of a business profile: 1–5 stars and a written review.
 *
 * Anyone can review, but nothing is public until two things have happened:
 * the reviewer clicked the emailed confirm link (unverified → pending) and an
 * admin approved it (pending → published). App\Services\ReviewService owns
 * the rules.
 *
 * The unique (listing_id, email_hash) key is one review per person per
 * business. email_hash is sha256 of the lower-cased address, so the key
 * still holds after prune() wipes reviewer_email from a rejected review.
 *
 * reviewer_email is never rendered. The page shows the first name and last
 * initial built from reviewer_name.
 *
 * ON DELETE CASCADE: a review is about one business and means nothing
 * without it. Listings are soft-deleted, so this only fires on a purge.
 */
class CreateDirectoryReviews extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'rating'         => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'comment' => '1-5'],
            'body'           => ['type' => 'TEXT'],

            'reviewer_name'  => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'reviewer_email' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'email_hash'     => ['type' => 'CHAR', 'constraint' => 64],
            'ip_hash'        => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],

            'status'         => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'unverified'],
            'reject_reason'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'flagged_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

            'verify_token'   => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'verify_expires' => ['type' => 'DATETIME', 'null' => true],

            'owner_reply'    => ['type' => 'TEXT', 'null' => true],
            'owner_reply_at' => ['type' => 'DATETIME', 'null' => true],

            'published_at'   => ['type' => 'DATETIME', 'null' => true],
            'decided_at'     => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Rejected or hidden; starts the retention clock'],
            'report_count'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],

            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['listing_id', 'status', 'published_at']);
        $this->forge->addKey('status');
        $this->forge->addKey('verify_token');
        $this->forge->addUniqueKey(['listing_id', 'email_hash']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_reviews', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_reviews', true);
    }
}
