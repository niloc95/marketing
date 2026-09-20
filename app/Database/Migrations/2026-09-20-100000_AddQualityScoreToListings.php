<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How complete a listing's profile is, 0-100, as a sort key.
 *
 * Search results used to be ordered `is_featured DESC, published_at DESC`, so
 * recency was the only real driver and an empty listing created yesterday
 * outranked a rich one from last month. The score replaces recency as the
 * second key; see App\Services\ListingQualityService for the rubric and
 * DirectoryService::browse() for the ordering.
 *
 * The rule that binds the rubric and therefore this column: nothing behind the
 * paid Verified Business badge scores. _plan_cards.php and faq.php both tell
 * the public that paying never moves a business up the results, and the only
 * way that stays true is if the score cannot see anything money buys.
 *
 * TWO columns, not one, and the second is doing real work. `quality_score` is
 * NOT NULL DEFAULT 0 because it is a sort key, and a nullable sort key means
 * reasoning about where NULL lands in a DESC sort forever. That leaves no way
 * to tell "scored 0" from "never scored", which recent() needs — so
 * `quality_scored_at` carries that, NULL meaning "we have not judged this yet".
 *
 * Deploy order on this project is migration-then-code, which this is built for.
 * Old code never selects, orders by or writes either column, and both are
 * defaulted, so every existing INSERT keeps working between the two steps. Run
 * `php spark directory:quality:recalculate` in the gap to have real scores from
 * the first request on the new code.
 *
 * If that backfill is skipped the failure is graceful rather than ugly: every
 * row scores 0, so browse()'s order degenerates to `is_featured DESC,
 * published_at DESC` — exactly today's behaviour — and recent()'s
 * `quality_scored_at IS NULL` branch lets every listing through instead of
 * emptying the homepage strip. The nightly sweep then fixes it within a day.
 */
class AddQualityScoreToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            // No `after`, for the reason AddRegionToListings spells out: MySQL 8
            // only gets ALGORITHM=INSTANT when a column is appended at the end
            // of the row, and asking for a position silently downgrades this to
            // a full table copy — a rebuild of the live table on deploy, and a
            // rebuild per test in the suite.
            'quality_score' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
                'comment'  => 'Profile completeness 0-100 — see ListingQualityService',
            ],
            'quality_scored_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'NULL = never scored, which reads as "do not judge yet"',
            ],
        ]);

        // Forge has no clean add-index-to-an-existing-table path, so this goes
        // through raw SQL the same way CreateDirectoryListings adds its
        // FULLTEXT index.
        //
        // Column order is the ORDER BY order: `status` is an equality constant
        // in every public query and the other three are the sort keys, all
        // DESC, so MySQL 8 can satisfy the unfiltered browse page with a
        // backward index scan and no filesort. There was no composite index on
        // this table before — the main search sort was already a filesort — so
        // this is a net win even setting the new column aside.
        //
        // It deliberately does not cover recent(): the range on quality_score
        // there stops the index reaching published_at anyway. If the homepage
        // ever shows up in the slow-query log, (status, published_at) is the
        // index to add.
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query(
            "ALTER TABLE `{$table}` ADD KEY `idx_listings_rank`"
            . ' (`status`, `is_featured`, `quality_score`, `published_at`)'
        );
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP KEY `idx_listings_rank`");
        $this->forge->dropColumn('directory_listings', ['quality_score', 'quality_scored_at']);
    }
}
