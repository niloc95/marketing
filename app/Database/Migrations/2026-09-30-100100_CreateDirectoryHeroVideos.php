<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `directory_hero_videos` — the uploaded clips the home hero rotates through
 * when its background is set to video. Each plays to its end and cross-fades
 * into the next; with one clip, it loops.
 *
 * Replaces the single `hero_video_path` setting, which is carried over as the
 * first row if it pointed at a file that still exists. Additive: nothing reads
 * this table until the code that does ships.
 */
class CreateDirectoryHeroVideos extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'FCPATH-relative mp4/webm'],
            'credit'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'comment' => 'Who shot it, e.g. the Pexels creator'],
            'credit_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['is_active', 'sort_order']);
        $this->forge->createTable('directory_hero_videos', true);

        $old = $this->db->table('directory_settings')->where('name', 'hero_video_path')->get()->getRowArray();
        $path = trim((string) ($old['value'] ?? ''));
        if ($path !== '' && is_file(rtrim(FCPATH, '/') . '/' . $path)) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('directory_hero_videos')->insert([
                'path' => $path, 'sort_order' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_hero_videos', true);
    }
}
