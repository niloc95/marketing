<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 10 of online bookings: the Bookings plan (decision D1, owner 5 Nov
 * 2026: a paid plan with a free trial). Our own subscription, separate from a
 * profile's Verified badge or Plus (xs_directory_verifications holds one row
 * per profile, and a business may have both).
 *
 * - xs_booking_plans: one row per business. state trial, active or
 *   cancelled; trial_ends_at; paid_until (UTC); the Paystack mandate.
 * - xs_booking_plan_payments: one row per charge, unique on Paystack's id, so
 *   the webhook and the browser's return can both arrive and only one counts.
 */
class CreateBookingPlans extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'state'                      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'trial'],
            'trial_ends_at'              => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'paid_until'                 => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'amount_cents'               => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'payment_reference'          => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'payment_attempts'           => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'paystack_customer_code'     => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'paystack_plan_code'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'paystack_subscription_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'paystack_email_token'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'reminded_at'                => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'cancelled_at'               => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'note'                       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_at'                 => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'                 => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('listing_id');
        $this->forge->addKey('payment_reference');
        $this->forge->addKey(['paystack_customer_code', 'paystack_plan_code']);
        $this->forge->addKey('paystack_subscription_code');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_plans');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'plan_id'      => ['type' => 'INT', 'unsigned' => true],
            'gateway_id'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'reference'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'amount_cents' => ['type' => 'INT', 'unsigned' => true],
            'paid_at'      => ['type' => 'DATETIME'],
            'created_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('gateway_id');
        $this->forge->addKey('plan_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'booking_plans', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_plan_payments');
    }

    public function down(): void
    {
        $this->forge->dropTable('booking_plan_payments', true);
        $this->forge->dropTable('booking_plans', true);
    }
}
