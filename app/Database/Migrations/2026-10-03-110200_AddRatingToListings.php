<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The published-review totals, kept on the listing so search cards and the
 * profile's JSON-LD need no join. ReviewService::recalculate() is the only
 * writer; neither column is in OWNER_EDITABLE.
 *
 * Deliberately NOT an ordering key anywhere. Like the badge, a rating must
 * never move a business up the results.
 *
 * Additive and defaulted: old code neither reads nor writes either column.
 * Run it before the code copy.
 */
class AddRatingToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'review_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false, 'default' => 0],
            'rating_avg'   => ['type' => 'DECIMAL', 'constraint' => '2,1', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['review_count', 'rating_avg']);
    }
}
