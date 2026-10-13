<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Careers at WebScheduler Local itself: /careers and /careers/{slug}. Rules
 * live in CareersService.
 *
 * - `directory_career_roles`: our own vacancies, written from /admin/careers.
 *   Deliberately not rows in directory_job_posts: board posts expire, sit
 *   next to other employers' ads, and relay applications without keeping
 *   them, none of which suits hiring our own staff.
 * - `directory_career_applications`: one row per application, with the CV
 *   stored privately under writable/careers. role_id NULL is a general
 *   application. `spark careers:purge` wipes the personal fields and the
 *   file 12 months on, as the consent line on the form says.
 *
 * Additive: two new tables nothing reads until the code ships, so this runs
 * before the code copy.
 */
class CreateCareers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'slug'            => ['type' => 'VARCHAR', 'constraint' => 120],
            'title'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'team'            => ['type' => 'VARCHAR', 'constraint' => 20, 'comment' => 'Config\Careers::$teams key'],
            'summary'         => ['type' => 'VARCHAR', 'constraint' => 300],
            'description'     => ['type' => 'TEXT', 'comment' => 'About the role: paragraphs'],
            // The structured sections every role is posted with. One item per
            // line; CareersService::sections() turns them into lists.
            'good_fit'          => ['type' => 'TEXT', 'null' => true, 'comment' => 'You may be a good fit if you'],
            'strong_candidates' => ['type' => 'TEXT', 'null' => true, 'comment' => 'Strong candidates may also have experience with'],
            'projects'          => ['type' => 'TEXT', 'null' => true, 'comment' => 'Representative projects'],
            'logistics'         => ['type' => 'TEXT', 'null' => true, 'comment' => 'Label: value lines'],
            'employment_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'comment' => 'Config\JobBoard::$employmentTypes key'],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'province'        => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'is_remote'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'salary_min'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'salary_max'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'salary_period'   => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'comment' => 'Config\JobBoard::$salaryPeriods key'],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'draft', 'comment' => 'draft | open | closed'],
            'published_at'    => ['type' => 'DATETIME', 'null' => true, 'comment' => 'First opened; JobPosting datePosted'],
            'valid_through'   => ['type' => 'DATE', 'null' => true, 'comment' => 'Optional closing date'],
            'sort_order'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey(['status', 'sort_order']);
        $this->forge->createTable('directory_career_roles', true);

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'role_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'comment' => 'NULL = general application'],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'linkedin_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'message'      => ['type' => 'TEXT', 'null' => true],
            'cv_path'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'Relative to CareerApplicationModel::storageRoot()'],
            'cv_name'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'What the applicant called the file'],
            'cv_mime'      => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'cv_sha256'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'consent_at'   => ['type' => 'DATETIME', 'null' => true],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'new', 'comment' => 'new | reviewing | shortlisted | rejected | hired'],
            'admin_notes'  => ['type' => 'TEXT', 'null' => true],
            'purged_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey('role_id');
        $this->forge->addForeignKey('role_id', 'directory_career_roles', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('directory_career_applications', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_career_applications', true);
        $this->forge->dropTable('directory_career_roles', true);
    }
}
