<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `position`: the contact person's role at the business (Owner, Founder, CEO…),
 * picked from DirectoryListingModel::POSITIONS. Private, like `title` beside
 * it: nothing on the public profile renders it.
 *
 * `by_appointment`: the answer to the now-compulsory opening hours for a
 * business that has none to give (mobile trades, practices that only see
 * booked clients). The profile's hours panel says "By appointment only".
 *
 * Additive, nullable / defaulted: old code neither reads nor writes either
 * column. Run it before the code copy.
 */
class AddPositionToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'position'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'title'],
            'by_appointment' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0, 'after' => 'trading_hours'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['position', 'by_appointment']);
    }
}
