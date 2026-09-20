<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The state, region or county of an address outside South Africa.
 *
 * Deliberately a new column rather than reusing `province`, which is the
 * obvious shortcut and the wrong one. `province` is whitelisted against
 * DirectoryService::SA_PROVINCES, slugified into the canonical
 * /directory/{category}/{province} URLs, indexed, counted by provinceCounts(),
 * and emitted into the sitemap once a province has landingMinListings entries.
 * Putting "Bavaria" in it would mint a landing page for a province that does
 * not exist, put it in the sitemap, and break the province filter's assumption
 * that every stored value is one of nine known strings.
 *
 * So the two are mutually exclusive by construction: a South African listing
 * has `province` and no `region`; everything else has `region` and no
 * `province`. Both are owner-editable — an owner correcting their own region is
 * as harmless as correcting their own province.
 *
 * `country` is the one that is NOT owner-editable, because after the
 * International Listing plan it decides whether a listing has to be paid for,
 * and a field that decides that cannot be writable by the person being charged.
 *
 * Not indexed: nothing filters or groups by it. The SEO landing tiers are South
 * African only, which is the whole point of the directory.
 */
class AddRegionToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            // No `after`. Column order here is cosmetic — nothing reads this
            // table by position — and asking for one costs real time: MySQL 8
            // can only use ALGORITHM=INSTANT when a column is appended at the
            // end of the row, so `after` silently downgrades this to a full
            // table copy. That is a rebuild of the live listings table on
            // deploy, and in the test suite it is a rebuild per test, which
            // took the run from minutes to hours.
            'region' => [
                'type' => 'VARCHAR', 'constraint' => 120, 'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'region');
    }
}
