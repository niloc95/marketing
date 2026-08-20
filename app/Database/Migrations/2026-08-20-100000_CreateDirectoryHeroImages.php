<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The photographs behind the home page hero.
 *
 * A table rather than a config array or a directory scan, because the two
 * things that make the hero useful are editorial, not technical: which category
 * a photo is meant to represent, and what order the rotation runs in. Both are
 * decisions an operator makes about content, and neither should need a deploy.
 *
 * `category_id` is the caption's link target rather than a free-text URL. An
 * operator picking from the category list cannot caption a photo "Architects"
 * and point it at a slug that no longer exists; ON DELETE SET NULL means
 * removing a category downgrades the caption to plain text instead of leaving a
 * link into a 404.
 *
 * Two path columns because the hero is the page's LCP element and a phone has
 * no use for a 1600px file. `path_sm` is nullable so a photo whose second
 * rendition failed to encode still works — the srcset simply degrades to one
 * source. Nothing here is secret and nothing here is per-visitor, so this table
 * is cached whole; see App\Services\HeroImageService.
 */
class CreateDirectoryHeroImages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'path'        => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'FCPATH-relative webp, long edge 1600'],
            'path_sm'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'Same photo at 800; null when the second encode failed'],
            'width'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'height'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'caption'     => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'comment' => 'What is in the photo, e.g. "Architects"'],
            'category_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'comment' => 'FK xs_directory_categories — where the caption links'],
            'credit'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'comment' => 'Photographer'],
            'credit_url'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'sort_order'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['is_active', 'sort_order']);
        $this->forge->addForeignKey('category_id', 'directory_categories', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('directory_hero_images', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_hero_images', true);
    }
}
