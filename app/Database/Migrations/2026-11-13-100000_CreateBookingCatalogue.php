<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 2 of online bookings (docs/bookings/data-model.md): what a business
 * sells, who customers book, when they work and when they are away.
 *
 * - `booking_services` and `booking_providers` are their own tables with
 *   stable ids, not the profile's services list (ServiceMenuService deletes
 *   and reinserts those on every save) or its team cards (a Verified or Plus
 *   feature). A provider may remember the team card it was made from
 *   (`team_member_id`, SET NULL when the card goes).
 * - Nothing is hard deleted once appointments can point at it (Phase 4):
 *   services and providers are archived (`archived_at`).
 * - `booking_provider_hours`: one row per provider and weekday (1 Monday to
 *   7 Sunday), times in the business's local time, `breaks` a JSON list of
 *   {start,end}. `location_id` null is the profile's own address, otherwise a
 *   branch (xs_directory_practice_locations). Bookings' separate location days
 *   table is not carried: a branch is open on the days someone works there.
 * - `booking_blocked_times`: time off, UTC, `provider_id` null for the whole
 *   business. Bookings read this table but had no screen for it.
 * - Every table carries `listing_id`, including the two link tables, so all of
 *   them go through BookingScopedModel.
 * - `business_members` gains the invitation token (hash only, TokenHash).
 */
class CreateBookingCatalogue extends Migration
{
    public function up(): void
    {
        $listing = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true];
        $stamps  = [
            'created_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ];

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => $listing,
            'name'       => ['type' => 'VARCHAR', 'constraint' => 80],
            'color'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'slate'],
            'sort_order' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
        ] + $stamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_categories');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => $listing,
            'slug'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'duration_min'   => ['type' => 'SMALLINT', 'unsigned' => true],
            'price_cents'    => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'currency'       => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ZAR'],
            'deposit_pct'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'buffer_before'  => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'buffer_after'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'category_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'delivery_modes' => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => '["onsite"]'],
            'profile_service_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'sort_order'     => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'archived_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] + $stamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'slug']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('category_id', 'booking_categories', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_services');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'     => $listing,
            'team_member_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'member_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'slug'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'title'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'bio'            => ['type' => 'TEXT', 'null' => true],
            'color'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'ocean'],
            'bookable'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order'     => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'archived_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] + $stamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['listing_id', 'slug']);
        $this->forge->addKey('member_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('team_member_id', 'directory_listing_team', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('member_id', 'business_members', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_providers');

        $this->forge->addField([
            'listing_id'  => $listing,
            'provider_id' => ['type' => 'INT', 'unsigned' => true],
            'service_id'  => ['type' => 'INT', 'unsigned' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey(['provider_id', 'service_id']);
        $this->forge->addKey(['listing_id', 'service_id']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('provider_id', 'booking_providers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('service_id', 'booking_services', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_provider_services');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => $listing,
            'provider_id' => ['type' => 'INT', 'unsigned' => true],
            'weekday'     => ['type' => 'TINYINT', 'unsigned' => true],
            'start_time'  => ['type' => 'TIME'],
            'end_time'    => ['type' => 'TIME'],
            'breaks'      => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => '[]'],
            'location_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
        ] + $stamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['provider_id', 'weekday']);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('provider_id', 'booking_providers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('location_id', 'directory_practice_locations', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('booking_provider_hours');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => $listing,
            'provider_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id'   => ['type' => 'INT', 'unsigned' => true],
            'assigned_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
        ] + $stamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['provider_id', 'member_id']);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('provider_id', 'booking_providers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('member_id', 'business_members', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_staff_assignments');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => $listing,
            'provider_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'start_at'    => ['type' => 'DATETIME'],
            'end_at'      => ['type' => 'DATETIME'],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'default' => null],
            'created_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
        ] + $stamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['listing_id', 'start_at']);
        $this->forge->addKey(['provider_id', 'start_at', 'end_at']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('provider_id', 'booking_providers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_blocked_times');

        $this->forge->addColumn('business_members', [
            'invite_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'invite_expires'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->db->query('CREATE INDEX invite_token_hash ON ' . $this->db->prefixTable('business_members') . ' (invite_token_hash)');
    }

    public function down(): void
    {
        $this->forge->dropColumn('business_members', ['invite_token_hash', 'invite_expires']);
        foreach (['booking_blocked_times', 'booking_staff_assignments', 'booking_provider_hours', 'booking_provider_services', 'booking_providers', 'booking_services', 'booking_categories'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
