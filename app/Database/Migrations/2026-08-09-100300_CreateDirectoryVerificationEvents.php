<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Every PayFast notification we accepted, one row each.
 *
 * The UNIQUE index on pf_payment_id is not bookkeeping — it is the replay
 * guard. PayFast re-sends a notification until it gets a 200, and a network
 * blip between our commit and our response is enough to produce a second
 * delivery of a payment we already banked. Rather than reason about that with
 * a flag, the handler inserts here first and treats a duplicate-key failure as
 * "already processed, stop": the database decides, and it decides atomically.
 *
 * That means this table must be written inside the same transaction as the
 * paid_until extension it authorises. An event row without its effect, or an
 * effect without its row, would both be worse than neither.
 *
 * `payload` keeps the whole POST as JSON. When a subscriber disputes a charge
 * months later, what PayFast actually sent is the only thing worth arguing
 * from, and it is far cheaper to keep than to reconstruct.
 */
class CreateDirectoryVerificationEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'verification_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_verifications'],
            'pf_payment_id'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'payment_status'  => ['type' => 'VARCHAR', 'constraint' => 32],
            'amount_gross'    => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'payload'         => ['type' => 'TEXT', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('pf_payment_id');
        $this->forge->addKey('verification_id');
        $this->forge->addForeignKey('verification_id', 'directory_verifications', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_verification_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_verification_events', true);
    }
}
