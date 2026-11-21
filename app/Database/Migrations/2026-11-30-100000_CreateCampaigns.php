<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Communications (owner, 21 Nov 2026): emails the admin sends to a chosen
 * group of profiles, in daily batches, from /admin/communications.
 *
 * - xs_campaigns: one row per campaign. `kind` is a key of Config\Campaigns
 *   (the template), `subject` and `body` the admin's edited wording (plain
 *   text paragraphs, merge fields filled per profile), `audience` the JSON
 *   segment and filters, `daily_limit` how many go out per day.
 * - xs_campaign_recipients: the send log. One row per campaign and profile,
 *   written when the campaign starts (the snapshot), so nobody is mailed the
 *   same campaign twice whatever happens to the cron job. `email` is the
 *   address at queue time, normalised.
 * - xs_directory_listings.comms_opt_out_at / comms_opt_out_source: the one
 *   opt out for every campaign, written only by MarketingConsentService.
 *
 * Additive: old code neither reads nor writes any of it. Run before the code copy.
 */
class CreateCampaigns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'kind'         => ['type' => 'VARCHAR', 'constraint' => 40],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120],
            'subject'      => ['type' => 'VARCHAR', 'constraint' => 160],
            'body'         => ['type' => 'TEXT'],
            'audience'     => ['type' => 'TEXT'],
            'daily_limit'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 150],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'draft'],
            'paused_reason' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'test_sent_at' => ['type' => 'DATETIME', 'null' => true],
            'started_at'   => ['type' => 'DATETIME', 'null' => true],
            'finished_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('campaigns');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'campaign_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'listing_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 254],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'queued'],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'attempts'    => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'sent_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['campaign_id', 'listing_id']);
        $this->forge->addKey(['campaign_id', 'status']);
        $this->forge->addKey(['campaign_id', 'sent_at']);
        $this->forge->addForeignKey('campaign_id', 'campaigns', 'id', '', 'CASCADE');
        $this->forge->createTable('campaign_recipients');

        $this->forge->addColumn('directory_listings', [
            'comms_opt_out_at'     => ['type' => 'DATETIME', 'null' => true],
            'comms_opt_out_source' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['comms_opt_out_at', 'comms_opt_out_source']);
        $this->forge->dropTable('campaign_recipients', true);
        $this->forge->dropTable('campaigns', true);
    }
}
