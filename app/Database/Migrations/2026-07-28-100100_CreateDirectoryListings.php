<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDirectoryListings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'type'            => ['type' => 'ENUM', 'constraint' => ['person', 'practice', 'facility'], 'default' => 'person'],
            'display_name'    => ['type' => 'VARCHAR', 'constraint' => 200],
            'contact_person'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'title'           => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'profession_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'comment' => 'FK xs_directory_professions'],
            'qualifications'  => ['type' => 'TEXT', 'null' => true],
            'description'     => ['type' => 'TEXT', 'null' => true],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'website'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'social_facebook' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'social_instagram' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'social_linkedin' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'address_line'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'suburb'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'province'        => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'postal_code'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'country'         => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'South Africa'],
            'latitude'        => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitude'       => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'logo_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'slug'            => ['type' => 'VARCHAR', 'constraint' => 190],
            'status'          => ['type' => 'ENUM', 'constraint' => ['pending', 'published', 'unpublished', 'rejected'], 'default' => 'pending'],
            'is_verified'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'verify_token'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'verify_expires'  => ['type' => 'DATETIME', 'null' => true],
            'published_at'    => ['type' => 'DATETIME', 'null' => true],
            'is_featured'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'source'          => ['type' => 'ENUM', 'constraint' => ['public_form', 'webscheduler'], 'default' => 'public_form'],
            'source_url'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'Originating WebScheduler instance URL'],
            'claim_token'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('profession_id');
        $this->forge->addKey('province');
        $this->forge->addKey('city');
        $this->forge->addKey('status');
        $this->forge->addKey('verify_token');
        $this->forge->addForeignKey('profession_id', 'directory_professions', 'id', 'SET NULL', 'SET NULL');
        $this->forge->createTable('directory_listings', true);

        // FULLTEXT search index (InnoDB, MySQL 5.6+). Forge has no FULLTEXT helper.
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD FULLTEXT `ft_directory_search` (`display_name`,`description`,`qualifications`)");
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listings', true);
    }
}
