<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How the profile video plays, the owner's two choices.
 *
 * video_autoplay: the video plays in its photo strip tile as the page opens,
 * silently and on a loop. Silently is not a choice: browsers refuse sound
 * until the visitor has tapped something.
 *
 * video_sound: the video starts with sound when a visitor opens it. It can,
 * because opening it is that tap.
 *
 * Both default off, so every video saved before this behaves as it did.
 */
class AddVideoPlaybackToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'video_autoplay' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'video_thumb_path',
            ],
            'video_sound' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'video_autoplay',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['video_autoplay', 'video_sound']);
    }
}
