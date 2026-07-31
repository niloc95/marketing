<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gallery photos, one row per uploaded photo. ON DELETE CASCADE because an
 * orphaned photo row (listing gone) is meaningless — note this does not
 * delete the on-disk webp file, same pre-existing gap as logo_path today
 * (nothing cleans up a replaced logo file either).
 */
class CreateDirectoryListingPhotos extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'path'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'width'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'height'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'sort_order'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_photos', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_photos', true);
    }
}
