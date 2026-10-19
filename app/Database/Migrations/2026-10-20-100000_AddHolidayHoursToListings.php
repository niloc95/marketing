<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Public holiday hours. The owner's answer itself lives in the trading_hours
 * JSON beside the days; these columns are derived from it so search can
 * filter in SQL (trading_hours is TEXT, and JSON_EXTRACT over legacy rows that
 * are not valid JSON would throw):
 *
 *  - holiday_hours: the general answer, closed / open / usual, NULL unanswered
 *  - holiday_open_dates: the holiday dates in the coming year it is open on,
 *    comma separated for FIND_IN_SET(); refreshed nightly by
 *    directory:holidays:remind as the year rolls on
 *  - holiday_reminded_at: when the owner was last emailed to answer
 *
 * Additive and nullable, so it runs before the code copy.
 */
class AddHolidayHoursToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'holiday_hours'       => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'after' => 'trading_hours'],
            'holiday_open_dates'  => ['type' => 'VARCHAR', 'constraint' => 400, 'null' => true, 'after' => 'holiday_hours'],
            'holiday_reminded_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'holiday_open_dates'],
        ]);
        $this->db->query('CREATE INDEX idx_listings_holiday_hours ON ' . $this->db->prefixTable('directory_listings') . ' (holiday_hours)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_listings_holiday_hours ON ' . $this->db->prefixTable('directory_listings'));
        $this->forge->dropColumn('directory_listings', ['holiday_hours', 'holiday_open_dates', 'holiday_reminded_at']);
    }
}
