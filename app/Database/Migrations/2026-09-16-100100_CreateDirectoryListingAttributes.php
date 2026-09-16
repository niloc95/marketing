<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Features & amenities" — the tick-box facts about a business.
 *
 * Only the key is stored. What keys exist, their labels and which category
 * group offers them live in Config\ListingAttributes, so rewording a label or
 * adding a feature is a code change with no migration. A key that is later
 * removed from the config simply stops rendering.
 *
 * The three older capability flags (accepts_card_payments, offers_delivery,
 * offers_online_booking) stay as listing columns — booking_url logic depends on
 * one of them — and are rendered alongside these, not duplicated here.
 *
 * attribute_key is indexed on its own so "every listing with wheelchair access"
 * can become a search filter without another migration.
 */
class CreateDirectoryListingAttributes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'listing_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'attribute_key' => ['type' => 'VARCHAR', 'constraint' => 60, 'comment' => 'Key from Config\ListingAttributes'],
        ]);
        $this->forge->addKey(['listing_id', 'attribute_key'], true);
        $this->forge->addKey('attribute_key');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_attributes', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_attributes', true);
    }
}
