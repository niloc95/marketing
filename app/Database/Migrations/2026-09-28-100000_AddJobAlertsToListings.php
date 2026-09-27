<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lead alerts: a listed business is emailed when someone nearby posts a
 * service request in its category (JobBoardService::alertMatchingBusinesses).
 *
 * On by default, unlike marketing_opt_in. These are requests for the
 * business's own services, sent because it listed itself to be found for
 * them, not promotion of ours. Every alert carries a one-click stop link and
 * the dashboard has a toggle.
 *
 * job_alerts_token is the stop link's token. Stored plain, like
 * marketing_token, because all it can do is turn alerts off. Neither column
 * is in DirectoryListingModel::OWNER_EDITABLE: the dashboard toggle has its
 * own endpoint.
 */
class AddJobAlertsToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'job_alerts' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
                'after'      => 'marketing_token',
                'comment'    => 'Email this business about matching service requests',
            ],
            'job_alerts_token' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'job_alerts',
                'comment'    => 'Lead-alert stop link token; can only switch alerts off',
            ],
        ]);

        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `uq_job_alerts_token` (`job_alerts_token`)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `uq_job_alerts_token`");
        $this->forge->dropColumn('directory_listings', ['job_alerts', 'job_alerts_token']);
    }
}
