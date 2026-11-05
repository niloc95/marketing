<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 9 of online bookings: connections and the API
 * (docs/bookings/data-model.md, parity.md §11).
 *
 * - xs_booking_api_keys: keys for /api/v1, each bound to one membership. Only
 *   a prefix (the lookup handle) and a SHA-256 of the secret are stored; the
 *   key is shown once.
 * - xs_booking_outbox: work for the outside world, done by bookings:dispatch,
 *   never inside a request: signed webhooks (one row per event, the body
 *   frozen when it happened), and Google Calendar and Zoom syncs (one queued
 *   row per appointment, read fresh when it runs). Doubles as the delivery
 *   log the business sees.
 * - Appointments get the Zoom meeting id and the Google Calendar event id, so
 *   a new time or a cancellation updates what was made before.
 *
 * Zoom, Jitsi, Google Calendar and the webhook endpoint are rows in
 * xs_booking_integrations (channels zoom, jitsi, google, webhook), encrypted
 * like the SMS and payment accounts.
 */
class CreateBookingIntegrations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'member_id'    => ['type' => 'INT', 'unsigned' => true],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 80],
            'prefix'       => ['type' => 'CHAR', 'constraint' => 12],
            'secret_hash'  => ['type' => 'CHAR', 'constraint' => 64],
            'scopes'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'last_used_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'expires_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'revoked_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('prefix');
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('member_id', 'business_members', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_api_keys');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'target'          => ['type' => 'VARCHAR', 'constraint' => 16],
            'appointment_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'event'           => ['type' => 'VARCHAR', 'constraint' => 40],
            'payload'         => ['type' => 'MEDIUMTEXT', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'queued'],
            'attempts'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'next_attempt_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'response_code'   => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'last_error'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'done_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'next_attempt_at']);
        $this->forge->addKey(['listing_id', 'target', 'created_at']);
        $this->forge->addKey(['appointment_id', 'target', 'status']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('appointment_id', 'booking_appointments', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_outbox');

        $this->forge->addColumn('booking_appointments', [
            'video_meeting_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null, 'after' => 'video_link'],
            'google_event_id'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null, 'after' => 'video_meeting_id'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('booking_appointments', ['video_meeting_id', 'google_event_id']);
        $this->forge->dropTable('booking_outbox', true);
        $this->forge->dropTable('booking_api_keys', true);
    }
}
