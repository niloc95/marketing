<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Daily totals per profile: how often it was opened, shown in discovery
 * results and landing pages, and how often a visitor tapped to make contact.
 * Written only by ListingStatsService.
 *
 * Totals only, by design: no IP, cookie or visitor id, so a row can never say
 * who looked (Privacy policy, "Profile statistics"). One row per profile per
 * day keeps it small: 2,000 profiles for 25 months is under 1.5 million rows
 * at the very worst, and most profiles are not seen every day.
 *
 * No site column: a profile's country_code already decides its site. No
 * foreign key: listings are soft deleted, and a stray row costs nothing.
 *
 * New table, so it can run before the code copy.
 */
class CreateListingStats extends Migration
{
    public function up(): void
    {
        $counter = ['type' => 'INT', 'unsigned' => true, 'default' => 0];

        $this->forge->addField([
            'listing_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'day'             => ['type' => 'DATE'],
            'views'           => $counter,
            'appearances'     => $counter,
            'taps_call'       => $counter,
            'taps_whatsapp'   => $counter,
            'taps_website'    => $counter,
            'taps_directions' => $counter,
            'taps_booking'    => $counter,
            'taps_email'      => $counter,
        ]);
        $this->forge->addPrimaryKey(['listing_id', 'day']);
        $this->forge->addKey('day');
        $this->forge->createTable('directory_listing_stats');
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_stats', true);
    }
}
