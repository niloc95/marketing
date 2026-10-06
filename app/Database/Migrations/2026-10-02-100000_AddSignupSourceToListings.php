<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `signup_source`: which channel brought a listing in. It is 'invite' (a
 * referral), a ?via= campaign tag, 'site' (one of the site's own "Get
 * verified" buttons) or 'direct' (someone who typed /add-profile). It tells
 * the admin which way of promoting the Verified Business badge actually
 * produces signups.
 *
 * Additive and nullable: rows from before this column have no source, and old
 * code neither reads nor writes it. Run it before the code copy.
 */
class AddSignupSourceToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'signup_source' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'status'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'signup_source');
    }
}
