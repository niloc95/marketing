<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * An optional link per service, so "more about this" has a home that is not
 * the price box.
 *
 * Owners were pasting URLs into price_label (PinkDrive filled all six rows that
 * way), where the 40 character column cut them off and the page printed them
 * as plain text. The link gets its own 500 character column and the service
 * name becomes the link; the price stays free text ("R 450", "Pay and claim").
 *
 * The data step moves any price that is really a URL into link_url. down()
 * drops the column without moving them back: a URL in the price box was the
 * bug, not a state worth restoring.
 *
 * Additive and nullable: old code neither reads nor writes it. Run it before
 * the code copy.
 */
class AddLinkToListingServices extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listing_services', [
            'link_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ]);

        // MySQL assigns SET left to right, so link_url reads price_label
        // before it is cleared.
        $table = $this->db->prefixTable('directory_listing_services');
        $this->db->query(
            "UPDATE {$table}
                SET link_url = CASE WHEN price_label LIKE 'www.%' THEN CONCAT('https://', price_label) ELSE price_label END,
                    price_label = NULL
              WHERE price_label REGEXP '^(https?://|www\\\\.)'"
        );
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listing_services', 'link_url');
    }
}
