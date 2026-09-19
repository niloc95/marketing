<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Venues — the complex, mall or building a set of businesses share.
 *
 * Prompted by the Oriental Plaza import: 262 shops that all carry
 * `address_line_2 = "Oriental Plaza, 62 Bree Street"`, differ only by shop
 * number, and geocode to one identical point (ListingGeocoder deliberately
 * leaves address_line_2 out of the lookup). Nothing tied them together, so
 * there was no way to answer "what else is in this building?".
 *
 * A record of its own rather than a tag, for two reasons the tag table cannot
 * meet: DirectoryTagModel::syncListingTags() replaces a listing's whole tag set
 * on every save, and the owner form posts tags as free text — so an owner
 * editing their profile would silently drop the venue — and a venue needs its
 * own address and coordinate to put a single pin on its page instead of 262
 * stacked ones.
 *
 * ON DELETE SET NULL on the listing side: removing a venue ungroups the
 * businesses, it must never delete them.
 */
class CreateDirectoryVenues extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 160],
            'slug' => ['type' => 'VARCHAR', 'constraint' => 180],
            // The venue's own address. Its listings keep theirs — the shop
            // number in address_line is what gets a customer to the door.
            'address_line' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'suburb'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'city'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'province'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'postal_code'  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            // One pin for the whole venue. Set by hand or copied off the
            // listings by directory:venue-assign, never geocoded here.
            'latitude'  => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('directory_venues', true);

        $this->forge->addColumn('directory_listings', [
            'venue_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'booking_url',
                'comment'    => 'Complex/mall/building this business sits in; admin-set',
            ],
        ]);

        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_venue_id` (`venue_id`)");
        $this->db->query(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `fk_listings_venue`"
            . ' FOREIGN KEY (`venue_id`) REFERENCES `' . $this->db->prefixTable('directory_venues') . '` (`id`)'
            . ' ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `fk_listings_venue`");
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `idx_venue_id`");
        $this->forge->dropColumn('directory_listings', 'venue_id');
        $this->forge->dropTable('directory_venues', true);
    }
}
