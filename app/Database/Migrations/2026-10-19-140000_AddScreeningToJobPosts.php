<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Screening questions on Jobs board vacancies (App\Libraries\Screening): a
 * Verified Business may set a level on a vacancy it posts, and each applicant
 * answers three questions drawn from it before applying.
 *
 * Only the level is stored. The marked answers go to the employer in the
 * application email and are never kept, as the board promises.
 *
 * Additive and nullable: existing posts ask nothing, so this runs before the
 * code copy.
 */
class AddScreeningToJobPosts extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_job_posts', [
            'screening_level' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true, 'after' => 'apply_email', 'comment' => 'basic, intermediate or advanced; NULL asks nothing'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_job_posts', ['screening_level']);
    }
}
