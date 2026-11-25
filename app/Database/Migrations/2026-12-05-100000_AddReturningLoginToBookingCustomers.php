<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Returning patients prove their email before the booking form is filled in
 * for them (owner, 25 Nov 2026, decision T37). ReturningPatientService
 * keeps one emailed code and one emailed link per customer here: both
 * stored hashed (the link SHA-256, the code HMAC SHA-256 keyed with
 * encryption.key), the link ending at login_expires (10 minutes) and the
 * code at login_code_expires (5 minutes), both used once, and login_tries
 * counts wrong codes for that code (5, then the code is dead).
 * login_failures and login_failed_at count wrong codes per patient across
 * codes and IPs, for the growing pauses (forgotten 48 hours after the last);
 * login_sent_at spaces the emails.
 *
 * Eight nullable columns, additive only, so it is safe to run before the
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
            'login_code_expires' => ['type' => 'DATETIME', 'null' => true],
            'login_tries'      => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => true],
            'login_failures'   => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
            'login_failed_at'  => ['type' => 'DATETIME', 'null' => true],
            'login_sent_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->db->query('CREATE INDEX login_token_hash ON ' . $this->db->prefixTable('booking_customers') . ' (login_token_hash)');
    }

    /** Drops only what exists, so it also undoes a half applied or older run. */
    public function down(): void
    {
        $table   = $this->db->prefixTable('booking_customers');
        $indexes = array_keys($this->db->getIndexData('booking_customers'));
        if (in_array('login_token_hash', $indexes, true)) {
            $this->db->query('DROP INDEX login_token_hash ON ' . $table);
        }
        $columns = array_values(array_filter(
            ['login_token_hash', 'login_code_hash', 'login_expires', 'login_code_expires', 'login_tries', 'login_failures', 'login_failed_at', 'login_sent_at'],
            fn (string $c): bool => $this->db->fieldExists($c, 'booking_customers'),
        ));
        if ($columns !== []) {
            $this->forge->dropColumn('booking_customers', $columns);
        }
    }
}
