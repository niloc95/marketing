<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 of online bookings: the appointments table, which availability and
 * double booking checks read (docs/bookings/data-model.md). The screens that
 * create appointments arrive in Phases 4 and 5; customers get their table in
 * Phase 4, which adds the foreign key on `customer_id`.
 *
 * - Times in UTC; `stored_timezone` is the zone the appointment belongs to
 *   (the business's), not the zone it was typed in (Bookings' rule).
 * - Snapshots (`service_name`, `service_duration_min`, `price_cents`,
 *   `provider_name`, `location_name`, `location_address`, `hold_before_min`,
 *   `hold_after_min`) keep an appointment readable and its time held exactly
 *   as booked, whatever later happens to the service or provider.
 * - `hold_before_min` / `hold_after_min`: the minutes kept free around it
 *   (the service's gaps plus the business's buffer at booking time).
 * - Index (provider_id, start_at, end_at) serves the overlap check.
 */
class CreateBookingAppointments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'reference'            => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'hash'                 => ['type' => 'CHAR', 'constraint' => 32],
            'provider_id'          => ['type' => 'INT', 'unsigned' => true],
            'service_id'           => ['type' => 'INT', 'unsigned' => true],
            'customer_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'location_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'start_at'             => ['type' => 'DATETIME'],
            'end_at'               => ['type' => 'DATETIME'],
            'hold_before_min'      => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'hold_after_min'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'stored_timezone'      => ['type' => 'VARCHAR', 'constraint' => 64],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
            'source'               => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'business'],
            'notes'                => ['type' => 'TEXT', 'null' => true],
            'delivery_mode'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'onsite'],
            'video_link'           => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'service_name'         => ['type' => 'VARCHAR', 'constraint' => 120],
            'service_duration_min' => ['type' => 'SMALLINT', 'unsigned' => true],
            'price_cents'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'currency'             => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ZAR'],
            'provider_name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'location_name'        => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'default' => null],
            'location_address'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'payment_status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'none'],
            'payment_amount_cents' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'public_token_hash'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'public_token_expires' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'reminder_sent'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_by_member_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'cancelled_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('hash');
        $this->forge->addUniqueKey('reference');
        $this->forge->addKey(['provider_id', 'start_at', 'end_at']);
        $this->forge->addKey(['listing_id', 'start_at']);
        $this->forge->addKey('public_token_hash');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        // RESTRICT: a provider or service with appointments is archived, never deleted.
        $this->forge->addForeignKey('provider_id', 'booking_providers', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('service_id', 'booking_services', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('location_id', 'directory_practice_locations', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_appointments');
    }

    public function down(): void
    {
        $this->forge->dropTable('booking_appointments', true);
    }
}
