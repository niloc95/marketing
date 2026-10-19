<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The brands a business sells, stocks or services, one row per logo: the
 * "Brands we carry" strip under the profile description.
 *
 * ON DELETE CASCADE for the reason the photo table gives — a brand row without
 * its listing means nothing. Like photos, this does not delete the WebP behind
 * it; ListingBrandService::delete() does that for a single brand.
 *
 * `name` is indexed because search matches it by prefix (applySearch()).
 */
class CreateDirectoryListingBrands extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 80],
            'logo_path'  => ['type' => 'VARCHAR', 'constraint' => 255],
            'width'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'height'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addKey('name');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_brands', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_brands', true);
    }
}
