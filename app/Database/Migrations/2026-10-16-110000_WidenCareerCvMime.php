<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Room for a Word CV's MIME type. CVs may now be Word documents, and
 * "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
 * is 71 characters, past the 60 the column was made with for PDFs and
 * images.
 *
 * Widening only, so it is safe before the code copy and harmless to the
 * code already running.
 */
class WidenCareerCvMime extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('directory_career_applications', [
            'cv_mime' => ['name' => 'cv_mime', 'type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
        ]);
    }

    public function down(): void
    {
        // A Word CV's type no longer fits; cut it rather than fail the rollback.
        $this->db->query('UPDATE ' . $this->db->prefixTable('directory_career_applications') . ' SET cv_mime = LEFT(cv_mime, 60)');

        $this->forge->modifyColumn('directory_career_applications', [
            'cv_mime' => ['name' => 'cv_mime', 'type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
        ]);
    }
}
