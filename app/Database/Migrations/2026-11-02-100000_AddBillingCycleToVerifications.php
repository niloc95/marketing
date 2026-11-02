<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Monthly or yearly billing for the badge and Plus.
 *
 * `billing_cycle` is snapshotted on the row with `amount`, for the same reason:
 * what a subscriber agreed to must not move when the operator edits a price.
 * The DEFAULT is 'monthly', which is what every existing row has always been,
 * so no backfill.
 *
 * `switch_m_payment_id` and `switch_amount` hold a pending switch from monthly
 * to yearly. The switch is a second PayFast subscription, paid now; until its
 * first notification arrives the monthly mandate keeps running and keeps its
 * own `pf_m_payment_id`, so the two have to be told apart. Both are NULL
 * whenever no switch is pending (VerificationService::recordPayment()).
 *
 * Additive and nullable or defaulted: run it before the code copy.
 */
class AddBillingCycleToVerifications extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_verifications', [
            'billing_cycle' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'null'       => false,
                'default'    => 'monthly',
                'comment'    => 'monthly or yearly, snapshotted with amount',
            ],
            'switch_m_payment_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'Pending monthly to yearly switch: the new checkout reference',
            ],
            'switch_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => true,
                'comment'    => 'Pending switch: the yearly amount it was offered at',
            ],
        ]);

        $this->db->query('CREATE INDEX idx_verifications_switch_mpid ON ' . $this->db->prefixTable('directory_verifications') . ' (switch_m_payment_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_verifications_switch_mpid ON ' . $this->db->prefixTable('directory_verifications'));
        $this->forge->dropColumn('directory_verifications', ['billing_cycle', 'switch_m_payment_id', 'switch_amount']);
    }
}
