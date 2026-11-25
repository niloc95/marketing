<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Returning patients prove their email before the booking form is filled in
 * for them (owner, 25 Nov 2026, decision T37). ReturningPatientService
 * keeps one emailed code and one emailed link per customer here: both
 * stored hashed, both ending at login_expires (15 minutes), both used once,
 * and login_tries counts wrong codes (5, then the code is dead).
 *
 * Four nullable columns, additive only, so it is safe to run before the
 * code copy at release.
 */
class AddReturningLoginToBookingCustomers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('booking_customers', [
            'login_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'login_code_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'login_expires'    => ['type' => 'DATETIME', 'null' => true],
            'login_tries'      => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => true],
        ]);
        $this->db->query('CREATE INDEX login_token_hash ON ' . $this->db->prefixTable('booking_customers') . ' (login_token_hash)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX login_token_hash ON ' . $this->db->prefixTable('booking_customers'));
        $this->forge->dropColumn('booking_customers', ['login_token_hash', 'login_code_hash', 'login_expires', 'login_tries']);
    }
}
