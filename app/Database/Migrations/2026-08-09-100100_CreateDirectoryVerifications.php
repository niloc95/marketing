<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One "Verified Business" application per listing.
 *
 * The badge is a paid monthly subscription: the owner uploads a company
 * registration document and an owner ID document, an admin reviews them, and
 * only then is the owner invited to pay. Documents before money, so a rejected
 * application never needs a refund.
 *
 * listing_id is UNIQUE. A listing has one application that moves through
 * states, not a pile of attempts — re-submitting after a rejection resets this
 * row rather than adding another, which keeps "what is this listing's status?"
 * a lookup instead of a sort.
 *
 * amount is snapshotted here rather than read from config at ITN time. PayFast
 * tells us what was charged and we have to check it matches what we asked for;
 * if that comparison used the current configured price, raising the price would
 * start rejecting every existing subscriber's renewal.
 */
class CreateDirectoryVerifications extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'state'      => [
                'type'       => 'ENUM',
                'constraint' => ['submitted', 'approved', 'active', 'lapsed', 'rejected'],
                'default'    => 'submitted',
            ],
            'paid_until'            => ['type' => 'DATE', 'null' => true],
            'amount'                => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'pf_subscription_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'pf_m_payment_id'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'rejection_reason'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'submitted_at'          => ['type' => 'DATETIME', 'null' => true],
            'reviewed_at'           => ['type' => 'DATETIME', 'null' => true],
            'reviewed_by'           => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'activated_at'          => ['type' => 'DATETIME', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('listing_id');
        $this->forge->addKey('state');
        $this->forge->addKey('pf_m_payment_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_verifications', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_verifications', true);
    }
}
