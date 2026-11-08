<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A person's day in periods, each at a place and for some services (decision
 * T29, owner 5 Nov 2026): a doctor works practice A 09:00 to 13:00 and
 * practice B 14:30 to 16:00 on the same Monday, maybe offering less at B.
 *
 * - `booking_provider_hours` loses its one row per weekday rule (the unique
 *   key on provider and weekday becomes a plain index) and gains
 *   `service_ids`, a JSON list of the services offered in that period; null
 *   is every service the person offers.
 * - `booking_services.length_checked`: 0 for a service copied from the
 *   profile on turning bookings on (T28), whose 30 minutes is a guess. Such a
 *   service is not offered online until the owner saves it. 1 for the rest.
 *
 * down() keeps the first period of each day, so the unique key fits again.
 */
class BookingSchedulePeriods extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('booking_provider_hours', [
            'service_ids' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true, 'default' => null, 'after' => 'location_id'],
        ]);
        $table = $this->db->prefixTable('booking_provider_hours');
        // The new index first: the provider foreign key needs one to lean on.
        $this->db->query("ALTER TABLE {$table} ADD INDEX provider_day (provider_id, weekday)");
        $this->db->query("ALTER TABLE {$table} DROP INDEX provider_id_weekday");

        $this->forge->addColumn('booking_services', [
            'length_checked' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1, 'after' => 'duration_min'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('booking_services', 'length_checked');

        $table = $this->db->prefixTable('booking_provider_hours');
        $this->db->query("DELETE h FROM {$table} h JOIN {$table} first ON first.provider_id = h.provider_id AND first.weekday = h.weekday AND first.id < h.id");
        $this->db->query("ALTER TABLE {$table} ADD UNIQUE INDEX provider_id_weekday (provider_id, weekday)");
        $this->db->query("ALTER TABLE {$table} DROP INDEX provider_day");
        $this->forge->dropColumn('booking_provider_hours', 'service_ids');
    }
}
