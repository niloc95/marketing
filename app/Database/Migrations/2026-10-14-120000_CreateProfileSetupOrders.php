<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Orders for the profile setup service at /profile-setup: a business pays a
 * once-off fee through PayFast and we complete its profile for it. Rules
 * live in ProfileSetupService.
 *
 * Its own table rather than rows in directory_verifications, which model a
 * monthly subscription tied to a listing. An order here is a single payment,
 * and the buyer may not have a profile yet.
 *
 * `reference` is the m_payment_id sent to PayFast and the key the
 * notification is matched on. `amount` is the price the order was sold at,
 * so a later price change cannot make a paid order fail its amount check.
 *
 * Additive: a new table nothing reads until the code ships, so this runs
 * before the code copy.
 */
class CreateProfileSetupOrders extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'reference'     => ['type' => 'VARCHAR', 'constraint' => 40, 'comment' => 'PayFast m_payment_id'],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 120],
            'business_name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 190],
            'phone'         => ['type' => 'VARCHAR', 'constraint' => 40],
            'profile_url'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'Their existing profile, if any'],
            'notes'         => ['type' => 'TEXT', 'null' => true],
            'amount'        => ['type' => 'DECIMAL', 'constraint' => '8,2'],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'awaiting_payment', 'comment' => 'awaiting_payment | paid | in_progress | done | cancelled | refunded'],
            'pf_payment_id' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'paid_at'       => ['type' => 'DATETIME', 'null' => true],
            'terms_at'      => ['type' => 'DATETIME', 'null' => true, 'comment' => 'When the buyer accepted the terms'],
            'admin_notes'   => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('reference');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('directory_profile_setup_orders', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_profile_setup_orders', true);
    }
}
