<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Screening questions on /careers (App\Libraries\Screening): a role may set
 * a level, each applicant answers three questions drawn from it, and each
 * application keeps its marked answers, score and time.
 *
 * Additive and nullable: existing roles ask nothing and existing
 * applications have no score, so this runs before the code copy.
 */
class AddScreeningToCareers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_career_roles', [
            'screening_level' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true, 'after' => 'logistics', 'comment' => 'basic, intermediate or advanced; NULL asks nothing'],
        ]);

        $this->forge->addColumn('directory_career_applications', [
            'screening_result' => ['type' => 'TEXT', 'null' => true, 'after' => 'message', 'comment' => 'JSON from Screening::consume(): answers, time, page leaves'],
            'screening_score'  => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => true, 'after' => 'screening_result'],
            'screening_total'  => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => true, 'after' => 'screening_score'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_career_applications', ['screening_result', 'screening_score', 'screening_total']);
        $this->forge->dropColumn('directory_career_roles', ['screening_level']);
    }
}
