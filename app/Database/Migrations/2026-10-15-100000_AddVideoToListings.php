<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One profile video, a Verified Business perk.
 *
 * A link to a YouTube or Vimeo video, stored parsed rather than as the raw
 * paste: the provider and id are all the player needs, and keeping the paste
 * would mean re-parsing it, and re-trusting it, on every render. video_hash is
 * the private key an unlisted Vimeo link carries; without it the player
 * refuses to play.
 *
 * video_thumb_path is a local copy of the provider's thumbnail, so a profile
 * page fetches nothing from Google or Vimeo until someone presses play.
 *
 * All nullable and written only by ListingVideoService, behind the badge
 * gate. A lapsed badge hides the video and keeps these, like team rows.
 */
class AddVideoToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'video_provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'booking_url',
                'comment'    => 'youtube or vimeo',
            ],
            'video_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'after'      => 'video_provider',
            ],
            'video_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'after'      => 'video_id',
                'comment'    => 'Private key of an unlisted Vimeo video',
            ],
            'video_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => true,
                'after'      => 'video_hash',
            ],
            'video_thumb_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'video_title',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['video_provider', 'video_id', 'video_hash', 'video_title', 'video_thumb_path']);
    }
}
