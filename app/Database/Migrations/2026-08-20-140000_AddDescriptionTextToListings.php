<?php

namespace App\Database\Migrations;

use App\Libraries\RichText;
use CodeIgniter\Database\Migration;

/**
 * The listing description becomes rich text, so `description` starts holding
 * HTML. Two things consume that column and neither wants markup:
 *
 *  1. The FULLTEXT index ft_directory_search. Tag and class names are indexed
 *     as words, so once descriptions carry markup a search for "strong" matches
 *     every listing that uses bold, and "center" matches every centred
 *     paragraph. There is no way to strip tags at query time — the index is
 *     built from what is stored.
 *  2. schema_local_business()'s JSON-LD `description`, which Google expects to
 *     be plain text.
 *
 * So the plain text gets its own derived column and the index moves to it.
 * description_text is written by the services on every save and is deliberately
 * absent from DirectoryListingModel::OWNER_EDITABLE — it is derived, never
 * posted, so a crafted owner POST cannot make a listing's search text disagree
 * with what the page displays.
 *
 * Existing rows are plain text; up() converts them to the paragraph markup the
 * editor would now produce, so the `whitespace-pre-line` line breaks they relied
 * on survive the switch to HTML rendering.
 *
 * MySQL-specific, matching CreateDirectoryListings — this app ships MySQLi only
 * and the forge has no FULLTEXT support.
 */
class AddDescriptionTextToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'description_text' => [
                'type' => 'TEXT', 'null' => true, 'after' => 'description',
            ],
        ]);

        $table = $this->db->prefixTable('directory_listings');

        // Moved before the backfill: the index has to be off `description`
        // before that column starts holding markup.
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `ft_directory_search`");
        $this->db->query(
            "ALTER TABLE `{$table}` ADD FULLTEXT `ft_directory_search` "
            . '(`display_name`,`description_text`,`credentials`)'
        );

        foreach ($this->descriptions() as $row) {
            $html = RichText::sanitise((string) $row['description']);

            $this->db->table('directory_listings')
                ->where('id', $row['id'])
                ->update([
                    'description'      => $html,
                    'description_text' => RichText::toPlainText($html),
                ]);
        }
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');

        // Put the plain text back into `description` before the column that
        // holds it goes away, or rolling back would leave every profile showing
        // raw markup.
        foreach ($this->descriptions() as $row) {
            $this->db->table('directory_listings')
                ->where('id', $row['id'])
                ->update(['description' => RichText::toPlainText((string) $row['description'])]);
        }

        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `ft_directory_search`");
        $this->db->query(
            "ALTER TABLE `{$table}` ADD FULLTEXT `ft_directory_search` "
            . '(`display_name`,`description`,`credentials`)'
        );

        $this->forge->dropColumn('directory_listings', 'description_text');
    }

    /**
     * Every listing with something in `description`, soft-deleted ones included
     * — a restored listing must not come back with the old format.
     *
     * @return list<array{id:int|string,description:string}>
     */
    private function descriptions(): array
    {
        return $this->db->table('directory_listings')
            ->select('id, description')
            ->where('description IS NOT NULL', null, false)
            ->where("description !=", '')
            ->get()
            ->getResultArray();
    }
}
