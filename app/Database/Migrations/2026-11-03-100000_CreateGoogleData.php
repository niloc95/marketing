<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What Google says about the platform, pulled nightly by GoogleDataService
 * (the only writer of these tables). All of it is totals Google has already
 * aggregated; nothing names a person.
 *
 * - listing_search: Search Console per profile page per day (impressions,
 *   clicks, average position). For owners and admin.
 * - listing_search_queries: the words people searched in Google before their
 *   profile appeared, summed per month. Google hides rare queries, so these
 *   add up to less than listing_search.
 * - site_daily: per site per day, Search Console totals (sc_*) and GA4
 *   totals (ga_*). NULL means that source has not been pulled for the day,
 *   which is not the same as zero. Admin only.
 * - site_breakdown: per site per month, top Search Console queries
 *   (sc_query), GA4 sessions by channel (ga_channel) and by landing page
 *   (ga_landing). Admin only.
 * - google_pulls: which source, site and day has been pulled, so a night
 *   never adds the same day twice to the monthly sums and a missed night is
 *   caught up.
 *
 * Words and page paths compare exactly (utf8mb4_bin): the default collation
 * would make "café" and "cafe" one key and refuse the second insert.
 *
 * New tables, so they can run before the code copy.
 */
class CreateGoogleData extends Migration
{
    public function up(): void
    {
        $count = ['type' => 'INT', 'unsigned' => true, 'default' => 0];

        $this->forge->addField([
            'listing_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'day'         => ['type' => 'DATE'],
            'impressions' => $count,
            'clicks'      => $count,
            'position'    => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['listing_id', 'day']);
        $this->forge->addKey('day');
        $this->forge->createTable('directory_listing_search');

        $this->forge->addField([
            'listing_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'month'       => ['type' => 'DATE'],
            'query'       => ['type' => 'VARCHAR', 'constraint' => 190],
            'impressions' => $count,
            'clicks'      => $count,
        ]);
        $this->forge->addPrimaryKey(['listing_id', 'month', 'query']);
        $this->forge->addKey('month');
        $this->forge->createTable('directory_listing_search_queries');

        $this->forge->addField([
            'site'           => ['type' => 'VARCHAR', 'constraint' => 8],
            'day'            => ['type' => 'DATE'],
            'sc_impressions' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sc_clicks'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sc_position'    => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'ga_users'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ga_sessions'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ga_views'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['site', 'day']);
        $this->forge->createTable('directory_site_daily');

        $this->forge->addField([
            'site'   => ['type' => 'VARCHAR', 'constraint' => 8],
            'month'  => ['type' => 'DATE'],
            'kind'   => ['type' => 'VARCHAR', 'constraint' => 16],
            'label'  => ['type' => 'VARCHAR', 'constraint' => 190],
            'value'  => $count,
            'value2' => $count,
        ]);
        $this->forge->addPrimaryKey(['site', 'month', 'kind', 'label']);
        $this->forge->createTable('directory_site_breakdown');

        $this->forge->addField([
            'source'    => ['type' => 'VARCHAR', 'constraint' => 8],
            'site'      => ['type' => 'VARCHAR', 'constraint' => 8],
            'day'       => ['type' => 'DATE'],
            'rows'      => $count,
            'pulled_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey(['source', 'site', 'day']);
        $this->forge->createTable('directory_google_pulls');

        $prefix = $this->db->getPrefix();
        $this->db->query("ALTER TABLE `{$prefix}directory_listing_search_queries` MODIFY `query` VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
        $this->db->query("ALTER TABLE `{$prefix}directory_site_breakdown` MODIFY `label` VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
    }

    public function down(): void
    {
        foreach (['directory_google_pulls', 'directory_site_breakdown', 'directory_site_daily', 'directory_listing_search_queries', 'directory_listing_search'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
