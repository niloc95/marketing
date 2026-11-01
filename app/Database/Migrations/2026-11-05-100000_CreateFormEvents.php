<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Form health (FormHealthService, the only writer): one row per form
 * outcome, from the server (saved, invalid, throttled, CSRF, lost...) or the
 * browser (submit pressed, blocked by a required field, script error), plus
 * one row per alert email sent (source 'alert').
 *
 * No IP, email, session, cookie or typed value: `detail` holds field keys, an
 * exception class or a script error. Purged after 180 days by forms:digest.
 *
 * Additive: old code neither reads nor writes it. Run it before the code copy.
 */
class CreateFormEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'site'       => ['type' => 'VARCHAR', 'constraint' => 8],
            'form'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'outcome'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'source'     => ['type' => 'VARCHAR', 'constraint' => 8],
            'detail'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'path'       => ['type' => 'VARCHAR', 'constraint' => 160],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['site', 'created_at']);
        $this->forge->addKey(['form', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('directory_form_events');
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_form_events', true);
    }
}
