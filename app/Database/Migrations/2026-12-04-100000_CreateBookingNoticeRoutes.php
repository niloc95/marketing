<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Team notices per person (T36, owner 25 Nov 2026): each person who signs in
 * chooses whose bookings they get notices for, instead of every admin getting
 * everything.
 *
 * - xs_booking_notice_routes: member × provider, "gets notices for".
 * - xs_business_members.notices_all: gets notices for everyone (admins),
 *   including people added later.
 * - xs_business_members.replaces_member_id: an invitation sent because an
 *   admin changed someone's email; accepting it hands the old member's
 *   people and notices to the new one and removes the old member.
 * - xs_accounts pending_email + token: a person changing their own email
 *   confirms it from the new address.
 *
 * Backfill: who got notices before still gets them (admins all, a person's
 * own sign in, assigned reception). Additive only, so it is safe to run
 * before the code copy at release.
 */
class CreateBookingNoticeRoutes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'listing_id'  => ['type' => 'INT', 'unsigned' => true],
            'member_id'   => ['type' => 'INT', 'unsigned' => true],
            'provider_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['member_id', 'provider_id']);
        $this->forge->addKey(['listing_id', 'provider_id']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('member_id', 'business_members', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('provider_id', 'booking_providers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_notice_routes', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addColumn('business_members', [
            'notices_all'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'notify_on_appointments'],
            'replaces_member_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'notices_all'],
        ]);
        $this->forge->addColumn('accounts', [
            'pending_email'          => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => true, 'after' => 'email'],
            'email_change_hash'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'pending_email'],
            'email_change_expires'   => ['type' => 'DATETIME', 'null' => true, 'after' => 'email_change_hash'],
        ]);

        $db  = $this->db;
        $now = gmdate('Y-m-d H:i:s');
        $p   = $db->DBPrefix;
        $db->query("UPDATE {$p}business_members SET notices_all = 1 WHERE role = 'admin' AND status != 'removed'");
        // A person's own sign in.
        $db->query("INSERT IGNORE INTO {$p}booking_notice_routes (listing_id, member_id, provider_id, created_at, updated_at)
            SELECT pr.listing_id, pr.member_id, pr.id, ?, ? FROM {$p}booking_providers pr
            JOIN {$p}business_members m ON m.id = pr.member_id AND m.listing_id = pr.listing_id AND m.status != 'removed'", [$now, $now]);
        // Reception assigned to them.
        $db->query("INSERT IGNORE INTO {$p}booking_notice_routes (listing_id, member_id, provider_id, created_at, updated_at)
            SELECT s.listing_id, s.member_id, s.provider_id, ?, ? FROM {$p}booking_staff_assignments s
            JOIN {$p}business_members m ON m.id = s.member_id AND m.status != 'removed'", [$now, $now]);
    }

    public function down(): void
    {
        $this->forge->dropTable('booking_notice_routes', true);
        $this->forge->dropColumn('business_members', ['notices_all', 'replaces_member_id']);
        $this->forge->dropColumn('accounts', ['pending_email', 'email_change_hash', 'email_change_expires']);
    }
}
