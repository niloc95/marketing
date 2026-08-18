<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Every PayFast notification we refused, one row each — the counterpart to
 * directory_verification_events, which records the ones we accepted.
 *
 * This exists because of a real incident: a payment went through, PayFast
 * reported delivery as "Success", the badge never appeared, and there was
 * nothing anywhere to say why. Two things caused that. The handler always
 * answers an empty 200 — deliberately, so a refusal cannot make PayFast retry
 * forever — which means PayFast's dashboard reports success for a notification
 * we threw away. And the reason was a log line at a level production discards.
 * The log level is fixed; this table is the part that survives log rotation,
 * because "PayFast says they paid" deserves an answer from data.
 *
 * Why not reuse directory_verification_events. Three of its properties are
 * load-bearing there and wrong here:
 *
 *   - pf_payment_id is UNIQUE, because that insert IS the replay guard. PayFast
 *     re-sends a refused notification too, so refusals repeat by design and
 *     would collide. Worse, a colliding insert is indistinguishable from "we
 *     already banked this", which is the one thing that table must never be
 *     confused about.
 *   - verification_id is NOT NULL behind a foreign key, but the "no matching
 *     verification" refusal has no verification to point at — that is the whole
 *     content of the finding.
 *   - it cascades on delete, and an audit record that disappears with the row
 *     it indicts is not an audit record.
 *
 * So: nullable verification_id, indexed but with NO foreign key, on purpose.
 * pf_payment_id and m_payment_id indexed but NOT unique — repetition is itself
 * the signal, and several rows for one payment id means PayFast is retrying
 * into a wall.
 *
 * `payload` keeps the whole POST as JSON, on the same reasoning as the events
 * table: what PayFast actually sent is the only thing worth arguing from later.
 * Unlike events these have no natural ceiling, so VerificationSweep prunes them
 * on age — see App\Commands\VerificationSweep.
 */
class CreateDirectoryVerificationItnRejections extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'verification_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'comment' => 'xs_directory_verifications.id when known; no FK, see class docblock'],
            'reason'          => ['type' => 'VARCHAR', 'constraint' => 191, 'comment' => 'which check refused it'],
            'pf_payment_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'm_payment_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'payment_status'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'amount_gross'    => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'source_ip'       => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'comment' => 'IPv6-wide'],
            'payload'         => ['type' => 'TEXT', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('verification_id');
        $this->forge->addKey('pf_payment_id');
        $this->forge->addKey('m_payment_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('directory_verification_itn_rejections', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_verification_itn_rejections', true);
    }
}
