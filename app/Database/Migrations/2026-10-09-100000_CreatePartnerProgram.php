<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The Partner Program: affiliates who promote WebScheduler Local with a
 * tracked link and earn a share of the Verified / International payments of
 * the businesses they bring in. Rules live in PartnerService.
 *
 * - `directory_partners`: the affiliates. Bank details are stored encrypted
 *   (CI4 Encrypter), because they are only ever read back to pay someone.
 * - `directory_partner_clicks`: one row per partner per day. Totals only, with
 *   no IP or user agent, since a click count needs neither (POPIA).
 * - `directory_partner_commissions`: one row per PayFast payment that earned
 *   commission. The unique key on pf_payment_id is the replay guard, as on
 *   directory_verification_events: a repeated notification or a reconcile run
 *   can never pay twice.
 * - `directory_partner_payouts`: one row per EFT an admin made.
 * - `directory_listings.partner_id`: who referred the business. Not
 *   owner editable.
 *
 * Additive and nullable: nothing reads any of it until the code that does
 * ships, so this runs before the code copy.
 */
class CreatePartnerProgram extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 120],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 190],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'company'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'website'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'promo_plan'      => ['type' => 'TEXT', 'null' => true, 'comment' => 'How they say they will promote us'],
            'code'            => ['type' => 'VARCHAR', 'constraint' => 40, 'comment' => 'The /p/{code} link'],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'applied', 'comment' => 'applied | approved | rejected | suspended'],
            'commission_rate' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true, 'comment' => 'Percent; NULL means Config\Partners::$commissionRate'],
            'bank_details'    => ['type' => 'TEXT', 'null' => true, 'comment' => 'Encrypted JSON: holder, bank, branch_code, account_number'],
            'login_token'     => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'comment' => 'TokenHash::hash() of the emailed dashboard link'],
            'login_expires'   => ['type' => 'DATETIME', 'null' => true],
            'decided_at'      => ['type' => 'DATETIME', 'null' => true],
            'decided_by'      => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'admin_note'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('email');
        $this->forge->addUniqueKey('login_token');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('directory_partners', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'day'        => ['type' => 'DATE'],
            'clicks'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['partner_id', 'day']);
        $this->forge->addForeignKey('partner_id', 'directory_partners', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_partner_clicks', true);

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'total'         => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'eft_reference' => ['type' => 'VARCHAR', 'constraint' => 120],
            'paid_at'       => ['type' => 'DATETIME'],
            'paid_by'       => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('partner_id');
        $this->forge->addForeignKey('partner_id', 'directory_partners', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('directory_partner_payouts', true);

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'listing_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'verification_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'pf_payment_id'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'payment_amount'  => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'rate'            => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'amount'          => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'state'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending', 'comment' => 'pending | available | paid | void'],
            'available_at'    => ['type' => 'DATETIME'],
            'payout_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'void_reason'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('pf_payment_id');
        $this->forge->addKey(['partner_id', 'state']);
        $this->forge->addKey(['state', 'available_at']);
        $this->forge->addForeignKey('partner_id', 'directory_partners', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('payout_id', 'directory_partner_payouts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('directory_partner_commissions', true);

        $this->forge->addColumn('directory_listings', [
            'partner_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'Partner Program affiliate who referred this business',
            ],
            'partner_attributed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_partner_id` (`partner_id`)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `idx_partner_id`");
        $this->forge->dropColumn('directory_listings', ['partner_id', 'partner_attributed_at']);
        $this->forge->dropTable('directory_partner_commissions', true);
        $this->forge->dropTable('directory_partner_payouts', true);
        $this->forge->dropTable('directory_partner_clicks', true);
        $this->forge->dropTable('directory_partners', true);
    }
}
