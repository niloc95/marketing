<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Trading hours (single JSON blob — a fixed 7-day shape only ever read/written
 * as a whole, so this matches the credentials/description TEXT treatment
 * rather than a normalized per-day child table) and three capability flags
 * shown as "pills" on the public profile.
 */
class AddTradingHoursAndPillsToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'trading_hours' => ['type' => 'TEXT', 'null' => true, 'after' => 'longitude'],
            'accepts_card_payments' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'trading_hours'],
            'offers_delivery' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'accepts_card_payments'],
            'offers_online_booking' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'offers_delivery'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', [
            'trading_hours', 'accepts_card_payments', 'offers_delivery', 'offers_online_booking',
        ]);
    }
}
