<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campaign sequences (owner, 27 Nov 2026): a campaign can be a series of
 * emails per profile (the claim sequence A1 to A3, the Verified sequence B1
 * to B3), each step timed from the profile's own last email or claim.
 *
 * - xs_campaigns.sequence: NULL for a one off campaign (as before), else the
 *   sequence key of Config\Campaigns (claim, verified).
 * - xs_campaign_steps: each step's edited subject and paragraphs and its delay.
 * - xs_campaign_recipients.step / next_send_at / entered_at: how many steps
 *   went, when the next is due, when the profile joined.
 * - xs_campaign_messages: one row per email that went (any campaign). Holds
 *   the hash of a claim link, so every claim email's link works on its own
 *   until it expires or the profile is claimed.
 * - xs_directory_listings.claimed_at: when the owner confirmed the profile on
 *   the claim page. comms_paused_at / comms_paused_note: admin (or the owner's
 *   "something is wrong") holds every campaign email to the profile.
 *
 * Additive: old code neither reads nor writes any of it. Run before the code copy.
 */
class AddCampaignSequences extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('campaigns', [
            'sequence' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
        ]);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'campaign_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step'        => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true],
            'subject'     => ['type' => 'VARCHAR', 'constraint' => 160],
            'body'        => ['type' => 'TEXT'],
            'delay_days'  => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['campaign_id', 'step']);
        $this->forge->addForeignKey('campaign_id', 'campaigns', 'id', '', 'CASCADE');
        $this->forge->createTable('campaign_steps');

        $this->forge->addColumn('campaign_recipients', [
            'step'         => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'next_send_at' => ['type' => 'DATETIME', 'null' => true],
            'entered_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'campaign_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'recipient_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'listing_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step'         => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 1],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 254],
            'subject'      => ['type' => 'VARCHAR', 'constraint' => 190],
            'link_hash'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'link_expires' => ['type' => 'DATETIME', 'null' => true],
            'sent_at'      => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('link_hash');
        $this->forge->addKey(['campaign_id', 'sent_at']);
        $this->forge->addKey(['email', 'sent_at']);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('campaign_id', 'campaigns', 'id', '', 'CASCADE');
        $this->forge->createTable('campaign_messages');

        $this->forge->addColumn('directory_listings', [
            'claimed_at'        => ['type' => 'DATETIME', 'null' => true],
            'comms_paused_at'   => ['type' => 'DATETIME', 'null' => true],
            'comms_paused_note' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['claimed_at', 'comms_paused_at', 'comms_paused_note']);
        $this->forge->dropTable('campaign_messages', true);
        $this->forge->dropColumn('campaign_recipients', ['step', 'next_send_at', 'entered_at']);
        $this->forge->dropTable('campaign_steps', true);
        $this->forge->dropColumn('campaigns', ['sequence']);
    }
}
