<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The directory was originally healthcare-only, so the taxonomy was modelled as
 * "professions". It now covers every kind of service business (legal, motor,
 * salons, spas, medical, trades…), so the taxonomy becomes "categories" and the
 * medical-specific "qualifications" field becomes the neutral "credentials".
 *
 * MySQL-specific (this app ships MySQLi only — the create migration already uses
 * a raw ALTER for its FULLTEXT index). RENAME COLUMN is used rather than CHANGE
 * so MySQL updates the dependent foreign key and FULLTEXT index definitions
 * itself, without dropping and recreating them.
 */
class RenameProfessionsToCategories extends Migration
{
    public function up(): void
    {
        $listings = $this->db->DBPrefix . 'directory_listings';

        $this->forge->renameTable('directory_professions', 'directory_categories');

        // FK xs_directory_listings.profession_id and the ft_directory_search
        // FULLTEXT index (which covers qualifications) follow these renames.
        $this->db->query("ALTER TABLE `{$listings}` RENAME COLUMN `profession_id` TO `category_id`");
        $this->db->query("ALTER TABLE `{$listings}` RENAME COLUMN `qualifications` TO `credentials`");
    }

    public function down(): void
    {
        $listings = $this->db->DBPrefix . 'directory_listings';

        $this->db->query("ALTER TABLE `{$listings}` RENAME COLUMN `credentials` TO `qualifications`");
        $this->db->query("ALTER TABLE `{$listings}` RENAME COLUMN `category_id` TO `profession_id`");

        $this->forge->renameTable('directory_categories', 'directory_professions');
    }
}
