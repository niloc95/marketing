<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The structured sections a vacancy is posted with, as on /careers (see
 * App\Libraries\PostingSections): You may be a good fit if you, Strong
 * candidates may also have experience with, Representative projects, and
 * Logistics. `description` stays and becomes "About the role".
 *
 * Additive and nullable: existing posts have none of these and keep showing
 * their description alone, so this runs before the code copy.
 */
class AddSectionsToJobPosts extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_job_posts', [
            'good_fit'          => ['type' => 'TEXT', 'null' => true, 'after' => 'description', 'comment' => 'You may be a good fit if you; one per line'],
            'strong_candidates' => ['type' => 'TEXT', 'null' => true, 'after' => 'good_fit', 'comment' => 'One per line'],
            'projects'          => ['type' => 'TEXT', 'null' => true, 'after' => 'strong_candidates', 'comment' => 'Representative projects; one per line'],
            'logistics'         => ['type' => 'TEXT', 'null' => true, 'after' => 'projects', 'comment' => 'Label: value lines'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_job_posts', ['good_fit', 'strong_candidates', 'projects', 'logistics']);
    }
}
