<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 1 of online bookings (docs/bookings/data-model.md): who signs in, which
 * businesses they belong to, per business settings and the audit log.
 *
 * - `accounts`: a person who signs in to /bookings. Not tied to one business,
 *   so one person can work at two. Password and emailed tokens are stored only
 *   as hashes (password_hash(), TokenHash).
 * - `business_members`: account × profile × role. The profile is the business;
 *   there is no businesses table and never a `business_id = 1`.
 * - `booking_settings`: key value pairs per profile. Only keys listed in
 *   Config\Bookings::$settingDefaults are ever written.
 * - `booking_audit_logs`: who changed what inside a business. The IP is kept
 *   only as a keyed hash (TokenHash::ip()).
 * - `bookings_enabled` on listings: the profile's own switch, written only by
 *   BookingSetupService and deliberately outside OWNER_EDITABLE.
 *
 * Every table is additive and nullable where it touches listings, so the
 * migration can run before the code copy (see the deploy skill).
 */
class CreateBookingFoundation extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 254],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => ''],
            'password_hash'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],
            'reset_token_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'reset_expires'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'login_token_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'login_expires'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'password_changed_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'last_login_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey('reset_token_hash');
        $this->forge->addKey('login_token_hash');
        $this->forge->createTable('accounts');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'  => ['type' => 'INT', 'unsigned' => true],
            'listing_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'role'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],
            'invited_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'notify_on_appointments' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['account_id', 'listing_id']);
        $this->forge->addKey(['listing_id', 'status']);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('business_members');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 96],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'name']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_settings');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'account_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'action'      => ['type' => 'VARCHAR', 'constraint' => 64],
            'target_type' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'target_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'old_value'   => ['type' => 'TEXT', 'null' => true],
            'new_value'   => ['type' => 'TEXT', 'null' => true],
            'ip_hash'     => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['listing_id', 'created_at']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_audit_logs');

        $this->forge->addColumn('directory_listings', [
            'bookings_enabled'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'bookings_enabled_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['bookings_enabled', 'bookings_enabled_at']);
        $this->forge->dropTable('booking_audit_logs', true);
        $this->forge->dropTable('booking_settings', true);
        $this->forge->dropTable('business_members', true);
        $this->forge->dropTable('accounts', true);
    }
}
