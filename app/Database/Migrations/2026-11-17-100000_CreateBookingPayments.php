<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 8 of online bookings: deposits (docs/bookings/data-model.md, D6).
 *
 * Each business takes deposits into its own PayFast or Paystack account; we
 * never hold the money. The account itself is a row in
 * xs_booking_integrations (channel 'payment'), encrypted like the SMS ones.
 *
 * - xs_booking_payments: one row per attempt to pay (reference
 *   WSB-BOOK-000123-P1, P2 ...), and one per deposit the business marks as
 *   paid by hand (gateway 'manual'). `test` keeps sandbox and test key
 *   payments apart from real money.
 * - Appointments get `payment_due_by`: until then an unpaid deposit holds the
 *   time; after it `bookings:payments` lets the time go.
 */
class CreateBookingPayments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'appointment_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'hash'                  => ['type' => 'CHAR', 'constraint' => 32],
            'reference'             => ['type' => 'VARCHAR', 'constraint' => 40],
            'gateway'               => ['type' => 'VARCHAR', 'constraint' => 16],
            'amount_cents'          => ['type' => 'INT', 'unsigned' => true],
            'currency'              => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ZAR'],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'started'],
            'test'                  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'gateway_id'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'detail'                => ['type' => 'TEXT', 'null' => true],
            'recorded_by_member_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'paid_at'               => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'refunded_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('reference');
        $this->forge->addUniqueKey('hash');
        $this->forge->addKey(['listing_id', 'status', 'created_at']);
        $this->forge->addKey('appointment_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('appointment_id', 'booking_appointments', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_payments');

        $this->forge->addColumn('booking_appointments', [
            'payment_due_by' => ['type' => 'DATETIME', 'null' => true, 'default' => null, 'after' => 'payment_amount_cents'],
        ]);
        $this->db->query('CREATE INDEX idx_booking_appt_payment_due ON ' . $this->db->prefixTable('booking_appointments') . ' (payment_status, payment_due_by)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_booking_appt_payment_due ON ' . $this->db->prefixTable('booking_appointments'));
        $this->forge->dropColumn('booking_appointments', 'payment_due_by');
        $this->forge->dropTable('booking_payments', true);
    }
}
