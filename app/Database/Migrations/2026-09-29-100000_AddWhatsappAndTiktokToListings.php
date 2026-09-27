<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `whatsapp` — the number a "Chat on WhatsApp" button opens a chat with, and
 * `social_tiktok` — a fourth social profile beside the three that have existed
 * since the table was created.
 *
 * whatsapp is its own column rather than a flag on `phone` because the number
 * a South African business answers WhatsApp on is very often a cellphone that
 * is not the landline it lists. It is stored as international digits only
 * ("27821234567"), which is what wa.me takes — see whatsapp_digits().
 *
 * social_tiktok is the same width as its siblings and goes through the same
 * social_profile_url() host check before it can reach an href.
 */
class AddWhatsappAndTiktokToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'whatsapp' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'phone_alt',
                'comment'    => 'WhatsApp number, international digits only (e.g. 27821234567)',
            ],
            'social_tiktok' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'social_linkedin',
                'comment'    => 'TikTok profile URL (https, tiktok.com only)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['whatsapp', 'social_tiktok']);
    }
}
