<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The owner's switch for new reviews on their whole profile.
 *
 * Off only stops new reviews being written. Published reviews stay on the
 * profile, reviews already in the queue still go through, and only an admin
 * can take one down. ReviewService::setEnabled() is the only writer; the
 * column is not in OWNER_EDITABLE.
 *
 * Additive and defaulted to on: old code neither reads nor writes it.
 * Run it before the code copy.
 */
class AddReviewsEnabledToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'reviews_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 1],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'reviews_enabled');
    }
}
