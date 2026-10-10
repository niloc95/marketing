<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A venue's own profile: the Place listing (type = place) that the venue is.
 * The Wanderers Club has a profile of its own, with its facilities, and the
 * karate school and restaurant inside it are separate listings on the venue.
 * This column is what lets each side link to the other.
 *
 * Admin-set only, like listings.venue_id. Unique, because one profile is one
 * place. ON DELETE SET NULL: deleting the profile leaves the venue page and
 * every business on it alone.
 *
 * Additive and nullable; old code never reads it. Run before the code copy.
 */
class AddListingIdToVenues extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_venues', [
            'listing_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'slug',
                'comment'    => 'This place\'s own profile; admin-set',
            ],
        ]);

        $table = $this->db->prefixTable('directory_venues');
        $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `uniq_venue_listing` (`listing_id`)");
        $this->db->query(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `fk_venues_listing`"
            . ' FOREIGN KEY (`listing_id`) REFERENCES `' . $this->db->prefixTable('directory_listings') . '` (`id`)'
            . ' ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_venues');
        $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `fk_venues_listing`");
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `uniq_venue_listing`");
        $this->forge->dropColumn('directory_venues', 'listing_id');
    }
}
