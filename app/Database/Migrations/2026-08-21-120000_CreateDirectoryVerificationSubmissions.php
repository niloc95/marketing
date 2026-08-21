<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The history behind a Verified Business badge.
 *
 * Until now there was one mutable verification row per listing and one live set
 * of documents hanging off it. Re-applying — after a rejection, or after a lapse
 * — deleted the previous documents and nulled `reviewed_at` / `reviewed_by` /
 * `rejection_reason`. That made "how did we verify this business, and when, and
 * who signed it off" unanswerable for any listing that had applied more than
 * once, which is exactly the question the documents are kept to answer.
 *
 * So: one row here per *application*, holding the decision that was made on it,
 * frozen. `directory_verifications` keeps its existing meaning untouched — it is
 * the current state of the badge. This table is the record of how that state was
 * arrived at. The two are deliberately not derivable from each other: the first
 * is overwritten in place by design, and the second must never be.
 *
 * Documents gain three columns rather than moving table:
 *
 *   `submission_id` — which application the file belongs to. Nullable, because
 *      rows predating this migration belong to an attempt nobody recorded; the
 *      backfill below gives them one anyway so the history is never ragged.
 *   `superseded_at` — set when a newer application replaces this one. Replaces
 *      deletion; the file stays on disk for the life of the listing.
 *   `sha256`        — digest of the bytes as stored, written at upload. The
 *      point of keeping the file is to be able to say "this is what they sent
 *      us"; the digest is what makes that checkable rather than merely asserted.
 *
 * `verification_id` stays on the documents table. It is the ON DELETE CASCADE
 * path and what every existing query uses, and there is nothing to gain from
 * rewriting those to hop through the new table.
 */
class CreateDirectoryVerificationSubmissions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'verification_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_verifications'],
            'attempt_no'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 1, 'comment' => '1-based, per verification'],
            // Not the same vocabulary as directory_verifications.state, and not
            // meant to be: that column tracks a badge through paying and lapsing,
            // this one records what a reviewer decided about one pile of paper.
            'outcome' => [
                'type'       => 'ENUM',
                'constraint' => ['submitted', 'approved', 'rejected', 'superseded'],
                'default'    => 'submitted',
            ],
            'submitted_at'     => ['type' => 'DATETIME', 'null' => true],
            'reviewed_at'      => ['type' => 'DATETIME', 'null' => true],
            'reviewed_by'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('verification_id');
        $this->forge->addForeignKey('verification_id', 'directory_verifications', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_verification_submissions', true);

        $this->forge->addColumn('directory_verification_documents', [
            'submission_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'verification_id',
                'comment'    => 'FK xs_directory_verification_submissions',
            ],
            'sha256' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'bytes',
                'comment'    => 'sha256 of the stored bytes, written at upload',
            ],
            'superseded_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'sha256',
                'comment' => 'set when a later application replaced this one; the file is kept',
            ],
        ]);

        // Nullable rather than NOT NULL: a document written by a request that is
        // mid-flight while this runs would otherwise fail its insert.
        $this->db->query(
            'ALTER TABLE ' . $this->db->prefixTable('directory_verification_documents')
            . ' ADD CONSTRAINT fk_verification_documents_submission'
            . ' FOREIGN KEY (submission_id) REFERENCES '
            . $this->db->prefixTable('directory_verification_submissions') . '(id)'
            . ' ON DELETE SET NULL ON UPDATE CASCADE'
        );

        $this->backfill();
    }

    /**
     * Every verification that already has documents gets an attempt-1 row, built
     * from the decision currently on the verification itself — which is the only
     * decision that survived, and the reason this migration exists.
     */
    private function backfill(): void
    {
        $documents = $this->db->prefixTable('directory_verification_documents');
        $subs      = $this->db->prefixTable('directory_verification_submissions');
        $verif     = $this->db->prefixTable('directory_verifications');

        $rows = $this->db->query(
            "SELECT DISTINCT v.id, v.state, v.submitted_at, v.reviewed_at, v.reviewed_by, v.rejection_reason
             FROM {$verif} v INNER JOIN {$documents} d ON d.verification_id = v.id"
        )->getResultArray();

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $v) {
            // approved / active / lapsed all mean a reviewer said yes at some
            // point; only 'rejected' and 'submitted' say otherwise.
            $outcome = match ((string) $v['state']) {
                'rejected'  => 'rejected',
                'submitted' => 'submitted',
                default     => 'approved',
            };

            $this->db->table('directory_verification_submissions')->insert([
                'verification_id'  => (int) $v['id'],
                'attempt_no'       => 1,
                'outcome'          => $outcome,
                'submitted_at'     => $v['submitted_at'],
                'reviewed_at'      => $v['reviewed_at'],
                'reviewed_by'      => $v['reviewed_by'],
                'rejection_reason' => $v['rejection_reason'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $this->db->table('directory_verification_documents')
                ->where('verification_id', (int) $v['id'])
                ->update(['submission_id' => (int) $this->db->insertID()]);
        }
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE ' . $this->db->prefixTable('directory_verification_documents')
            . ' DROP FOREIGN KEY fk_verification_documents_submission'
        );
        $this->forge->dropColumn('directory_verification_documents', ['submission_id', 'sha256', 'superseded_at']);
        $this->forge->dropTable('directory_verification_submissions', true);
    }
}
