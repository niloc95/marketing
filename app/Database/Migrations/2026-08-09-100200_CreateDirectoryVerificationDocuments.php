<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The evidence behind a Verified Business application: a company registration
 * document and a copy of the owner's ID.
 *
 * These files are PII of a kind the rest of the app never handles. They live
 * under writable/verification/<listing_id>/, outside the docroot, and reach an
 * admin only through a session-gated streaming route. `path` is relative to
 * that root, so moving the storage base is a config change rather than a
 * data migration.
 *
 * `original_name` is for display in the review queue only. It is whatever the
 * uploader's browser claimed and must never be used to build a filesystem
 * path — the stored name is generated, random and extension-checked.
 *
 * `mime` is the detected type, not the declared one, and it is what the
 * streaming route sends as Content-Type. Storing it means the route never has
 * to sniff a file it is about to hand to a browser.
 *
 * As with listing photos, ON DELETE CASCADE removes rows and not files. Disk
 * cleanup is explicit in VerificationService and in the admin purge path.
 */
class CreateDirectoryVerificationDocuments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'verification_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_verifications'],
            'kind'            => [
                'type'       => 'ENUM',
                'constraint' => ['company_registration', 'owner_id'],
            ],
            'path'          => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'relative to writable/verification/'],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'mime'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'bytes'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('verification_id');
        $this->forge->addForeignKey('verification_id', 'directory_verifications', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_verification_documents', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_verification_documents', true);
    }
}
