<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 6 of online bookings: notifications (docs/bookings/data-model.md).
 *
 * - Rules and templates: one row per business, event, channel and recipient
 *   (customer or team) that the business changed; defaults stay in code, so
 *   a business with no rows gets the defaults.
 * - The queue keeps Bookings' contract: locks (`locked_at`, `lock_token`),
 *   attempts with backoff, `idempotency_key` unique per business, a
 *   `correlation_id` shared with the delivery logs, and for reminders the
 *   offset and the schedule fingerprint that cancels a stale reminder after
 *   a move. `member_id` instead of Bookings' `recipient_user_id`.
 * - Delivery logs keep a masked recipient only (decision D12).
 * - Opt outs are per business, channel and recipient, by a hash of the
 *   address or number, so the table holds no contact details.
 * - The inbox is the notification centre: one row per team member.
 * - Integrations hold SMS and WhatsApp credentials, encrypted with the CI4
 *   Encrypter (BookingIntegrations refuses to save without encryption.key).
 */
class CreateBookingNotifications extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 32],
            'channel'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'recipient'  => ['type' => 'VARCHAR', 'constraint' => 16],
            'enabled'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'event', 'channel', 'recipient']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_notification_rules');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 32],
            'channel'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'recipient'  => ['type' => 'VARCHAR', 'constraint' => 16],
            'subject'    => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'body'       => ['type' => 'TEXT'],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'event', 'channel', 'recipient']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_templates');

        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'appointment_id'       => ['type' => 'INT', 'unsigned' => true],
            'event'                => ['type' => 'VARCHAR', 'constraint' => 32],
            'channel'              => ['type' => 'VARCHAR', 'constraint' => 16],
            'recipient_type'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'customer'],
            'member_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'queued'],
            'attempts'             => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'max_attempts'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 5],
            'run_after'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'locked_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'lock_token'           => ['type' => 'CHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'last_error'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'sent_at'              => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'idempotency_key'      => ['type' => 'VARCHAR', 'constraint' => 128],
            'correlation_id'       => ['type' => 'CHAR', 'constraint' => 32],
            'reminder_offset_min'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'schedule_fingerprint' => ['type' => 'CHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'created_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'idempotency_key']);
        $this->forge->addKey(['status', 'run_after']);
        $this->forge->addKey(['listing_id', 'appointment_id']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('appointment_id', 'booking_appointments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('member_id', 'business_members', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_notification_queue');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'queue_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'correlation_id' => ['type' => 'CHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'appointment_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'event'          => ['type' => 'VARCHAR', 'constraint' => 32],
            'channel'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'recipient_type' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'customer'],
            'recipient'      => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'outcome'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'attempt'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'error'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['listing_id', 'created_at']);
        $this->forge->addKey('correlation_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('appointment_id', 'booking_appointments', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_delivery_logs');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'channel'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'recipient_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'source'         => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'link'],
            'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'channel', 'recipient_hash']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_opt_outs');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'member_id'      => ['type' => 'INT', 'unsigned' => true],
            'appointment_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'event'          => ['type' => 'VARCHAR', 'constraint' => 32],
            'title'          => ['type' => 'VARCHAR', 'constraint' => 190],
            'body'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'read_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['listing_id', 'member_id', 'read_at']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('member_id', 'business_members', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('appointment_id', 'booking_appointments', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_inbox');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'channel'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'provider'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'config'         => ['type' => 'TEXT', 'null' => true],
            'is_active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'health'         => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'unknown'],
            'last_tested_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'last_error'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'channel']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_integrations');
    }

    public function down(): void
    {
        foreach (['booking_integrations', 'booking_inbox', 'booking_opt_outs', 'booking_delivery_logs', 'booking_notification_queue', 'booking_templates', 'booking_notification_rules'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
