<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * job_posts.at_listing_address: the vacancy is at the hiring profile's own
 * address. The job page then shows that address and the JobPosting markup
 * carries its streetAddress and postalCode, which Search Console flagged as
 * missing (JobBoardService::listingAddress()). 0 keeps the town and province
 * only, as before.
 *
 * Additive with a default: old code neither reads nor writes it. Run it before
 * the code copy.
 */
class AddJobAtListingAddress extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_job_posts', [
            'at_listing_address' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_job_posts', 'at_listing_address');
    }
}
