<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The people inside a business.
 *
 * A listing has always been one business with one contact person, which is
 * right for a plumber and wrong for a professional practice: an attorneys' firm
 * is five attorneys, each with their own position, qualifications and areas of
 * law. Until now that could only go into the free-text description, where it is
 * unstructured, unsearchable and unreadable.
 *
 * Two column choices here differ from how the listing itself stores the same
 * kinds of thing, both deliberately:
 *
 * - `specializations` is a plain comma-joined column, not a second tag pivot.
 *   xs_directory_listing_tags exists so a tag can be matched *across* listings;
 *   a member's areas of law are display text plus a LIKE target and nothing
 *   else. A pivot would mean a syncListingTags()-style delete-and-reinsert
 *   inside a transaction on every row edit, buying no query we intend to run.
 *
 * - `bio` is plain text, not HTML. The business description is the only
 *   rich-text field on the site and the only place RichText::sanitise() runs.
 *   Keeping bios escaped means this feature adds no new sanitiser surface.
 *
 * `slug` is written but not yet routed. The panel on the profile page is all
 * this change ships; a per-member page at /directory/{listing}/{member} is a
 * controller action and a view away, and having the column from the start means
 * it does not also need a migration against a populated table. The unique key is
 * on (listing_id, slug) rather than slug alone — two firms may both have a Jane
 * Smith, and only the pair has to address one row.
 *
 * The FK cascades on delete, which removes the rows but not the headshot files;
 * DirectoryAdminService::purge() unlinks those first, for the same reason it
 * already does with gallery photos — the row is the only thing that knows where
 * the file is.
 */
class CreateDirectoryListingTeam extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'listing_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 150],
            'slug'            => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'comment' => 'Reserved for a per-member public page'],
            'role'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'comment' => 'Position, e.g. Founding Partner'],
            'credentials'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'specializations' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'comment' => 'Comma-joined, normalised on write'],
            'bio'             => ['type' => 'TEXT', 'null' => true, 'comment' => 'Plain text, never HTML'],
            'photo_path'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'sort_order'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('listing_id');
        $this->forge->addUniqueKey(['listing_id', 'slug']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_team', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_team', true);
    }
}
