<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Paystack beside PayFast, for the badge, Plus and the profile setup service.
 *
 * `provider` says which gateway holds a row's recurring mandate (or took an
 * order's payment). The DEFAULT is 'payfast', which is what every existing row
 * is, so no backfill. The PayFast columns keep their `pf_` names: a row moved
 * to Paystack keeps its history, and `pf_m_payment_id` was always our own
 * reference, which Paystack now receives as its `reference`.
 *
 * A Paystack subscription is identified by its customer and plan codes (what
 * a renewal's charge carries) and cancelled with its subscription code and
 * email token, so all four are kept. `switch_cycle` lets a pending switch keep
 * the cycle (the move from PayFast to Paystack) rather than always meaning
 * "to yearly"; NULL is read as yearly, which is what every pending switch
 * before this column was.
 *
 * `xs_paystack_plans` caches the Paystack plan made for each product, cycle
 * and amount, because a Paystack plan has one fixed amount and our amounts
 * are snapshotted per row (VerificationService::paystackPlanCode()).
 *
 * Additive and nullable or defaulted: run it before the code copy.
 */
class AddPaystackToPayments extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_verifications', [
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => 'payfast',
                'comment'    => 'payfast or paystack: who holds the recurring mandate',
            ],
            'switch_cycle' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'null'       => true,
                'comment'    => 'Pending switch: the cycle it moves to, NULL = yearly',
            ],
            'paystack_customer_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'paystack_plan_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'paystack_subscription_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'paystack_email_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'Needed with the subscription code to cancel',
            ],
        ]);
        $this->db->query('CREATE INDEX idx_verifications_paystack_customer ON ' . $this->db->prefixTable('directory_verifications') . ' (paystack_customer_code)');

        $this->forge->addColumn('directory_profile_setup_orders', [
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => 'payfast',
            ],
        ]);

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product'      => ['type' => 'VARCHAR', 'constraint' => 16],
            'cycle'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'amount_cents' => ['type' => 'INT', 'unsigned' => true],
            'currency'     => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ZAR'],
            'plan_code'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['product', 'cycle', 'amount_cents', 'currency']);
        $this->forge->addUniqueKey('plan_code');
        $this->forge->createTable('paystack_plans', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('paystack_plans', true);
        $this->forge->dropColumn('directory_profile_setup_orders', ['provider']);
        $this->db->query('DROP INDEX idx_verifications_paystack_customer ON ' . $this->db->prefixTable('directory_verifications'));
        $this->forge->dropColumn('directory_verifications', [
            'provider', 'switch_cycle', 'paystack_customer_code', 'paystack_plan_code',
            'paystack_subscription_code', 'paystack_email_token',
        ]);
    }
}
