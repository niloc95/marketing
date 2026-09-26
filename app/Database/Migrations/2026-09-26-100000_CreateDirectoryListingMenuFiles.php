<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A food listing's uploaded menu: either one PDF or up to four photographed
 * pages, one row per file.
 *
 * Its own table rather than more rows in directory_listing_photos, because the
 * two differ in the ways that matter. A menu PDF is stored untouched under
 * writable/ and streamed through Directory::menu(); a gallery photo is a
 * re-encoded WebP under public/ that Apache serves. A menu is also replaced
 * wholesale — a new menu is never "page 5" of last month's — where the gallery
 * is additive. `kind` says which of the two storage roots `path` is relative to.
 *
 * ON DELETE CASCADE drops the rows with the listing; like the photos table, it
 * does not delete the files behind them.
 */
class CreateDirectoryListingMenuFiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'kind'          => ['type' => 'VARCHAR', 'constraint' => 10, 'comment' => 'pdf (under WRITEPATH) or image (under FCPATH)'],
            'path'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'bytes'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'width'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'height'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'sort_order'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_menu_files', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_menu_files', true);
    }
}
