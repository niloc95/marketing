<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 4 of online bookings: customers and the answers to a business's own
 * questions on an appointment (docs/bookings/data-model.md).
 *
 * - Customers belong to one business: unique (listing_id, email), so the same
 *   person at two businesses is two customers, by design. Email may be empty
 *   (a phone only customer), and MySQL allows many NULLs in a unique key.
 * - `hash` (64 hex) is the customer's link, never the id.
 * - `custom_fields` JSON keeps the latest answers per field key; each
 *   appointment keeps its own copy in xs_booking_appointment_fields.
 * - `purged_at` is for the retention rule (Phase 10).
 * - The appointment's customer FK is SET NULL: deleting a customer keeps the
 *   business's history of the time (CustomerService previews the impact).
 */
class CreateBookingCustomers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'hash'          => ['type' => 'CHAR', 'constraint' => 64],
            'first_name'    => ['type' => 'VARCHAR', 'constraint' => 80],
            'last_name'     => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'phone'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'address'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'notes'         => ['type' => 'TEXT', 'null' => true],
            'custom_fields' => ['type' => 'JSON', 'null' => true],
            'purged_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('hash');
        $this->forge->addUniqueKey(['listing_id', 'email']);
        $this->forge->addKey(['listing_id', 'last_name', 'first_name']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_customers');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'appointment_id' => ['type' => 'INT', 'unsigned' => true],
            'field_key'      => ['type' => 'VARCHAR', 'constraint' => 32],
            'value'          => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['appointment_id', 'field_key']);
        $this->forge->addForeignKey('appointment_id', 'booking_appointments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_appointment_fields');

        $prefix = $this->db->getPrefix();
        $this->db->query(
            'ALTER TABLE ' . $prefix . 'booking_appointments ADD CONSTRAINT ' . $prefix . 'booking_appointments_customer_id_foreign'
            . ' FOREIGN KEY (customer_id) REFERENCES ' . $prefix . 'booking_customers (id) ON UPDATE CASCADE ON DELETE SET NULL',
        );
    }

    public function down(): void
    {
        $prefix = $this->db->getPrefix();
        $this->db->query('ALTER TABLE ' . $prefix . 'booking_appointments DROP FOREIGN KEY ' . $prefix . 'booking_appointments_customer_id_foreign');
        $this->forge->dropTable('booking_appointment_fields', true);
        $this->forge->dropTable('booking_customers', true);
    }
}
