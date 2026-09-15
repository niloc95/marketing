<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `booking_url` — the page where a customer books an appointment.
 *
 * `offers_online_booking` has been a bare yes/no since 2026-07-30, which left
 * the profile announcing "Online booking" with nowhere to send anyone, and the
 * map pop-up's Book button pointing at a #book anchor no page ever rendered.
 * The flag stays: a saved link forces it on (see the mutation and admin
 * services), but a business can still say it books online without one.
 *
 * Same width as `website`, and validated by the same normaliseUrl() — it is
 * rendered into an href, so it must never accept anything but http(s).
 */
class AddBookingUrlToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'booking_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'offers_online_booking',
                'comment'    => 'Online appointment booking page (http/https only)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'booking_url');
    }
}
