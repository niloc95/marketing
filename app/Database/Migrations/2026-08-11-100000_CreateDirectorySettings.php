<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Settings an operator can change from the admin panel instead of by editing
 * .env on a live server.
 *
 * Only non-secret, operational values belong here: the Verified Business price
 * and whether the badge is offered at all. Credentials stay in .env
 * deliberately — PayFast's passphrase is a signing secret, and a row editable
 * through a web form means one stolen admin session could re-key payments
 * rather than merely change a price. Reading .env needs filesystem access,
 * which is a meaningfully higher bar.
 *
 * The table starts empty and is never seeded. An absent row means "not set
 * here", which is what lets the fallback chain work: database, then .env, then
 * the committed default in Config\Directory. A fresh deploy therefore runs on
 * sensible values before anyone has opened the settings page.
 *
 * `name`, not `key`: KEY is reserved in MySQL, and a column called that turns
 * every hand-written query into an exercise in remembering backticks.
 */
class CreateDirectorySettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'updated_by' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'comment' => 'admin@<ip>'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('directory_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_settings', true);
    }
}
