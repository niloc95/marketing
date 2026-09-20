<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A second paid plan: the International Listing.
 *
 * A South African address lists for free and always will. A business anywhere
 * else may still list, but its listing publishes only while a monthly
 * subscription is paid. This migration is what lets one listing hold more than
 * one subscription, and gives the listings table the render cache the publish
 * gate reads.
 *
 * Two changes, and the second is the one to be careful about.
 *
 * 1. `plan` on xs_directory_verifications. Everything already in the table is
 *    the badge, hence the DEFAULT — existing rows are correct without being
 *    touched, and the column can be added to a live table without a backfill.
 *
 * 2. listing_id stops being UNIQUE on its own and becomes UNIQUE with `plan`.
 *    The old constraint said "one application per listing", which was true
 *    while the badge was the only thing to apply for. Leaving it would mean a
 *    business in Germany paying to be listed could never also buy the badge —
 *    its second subscription would collide with its first. The invariant it
 *    protected is preserved exactly, one row per listing PER PLAN, so
 *    re-submitting after a rejection still resets a row rather than piling up
 *    attempts.
 *
 * The listings table gets `hosting_paid_until`, which is to this plan what
 * `verified_until` is to the badge: a render cache so the publish gate and the
 * sweep do not have to join, with xs_directory_verifications still the source
 * of truth. It is written by exactly one method — see
 * VerificationService::applyHostingPaidUntil() — and, like verified_until, is
 * absent from OWNER_EDITABLE and from DirectoryAdminService::upsert()'s
 * privileged block, so no form post of any kind can reach it.
 *
 * NULL means "never had one". A past date means "had one, it lapsed" — kept
 * rather than nulled so win-back mail can tell the two apart, same as the badge.
 *
 * Nothing existing is affected by any of this: every row in the table has
 * country = 'South Africa', and the gate keys off country being something else.
 */
class AddInternationalListingPlan extends Migration
{
    /**
     * Does this index exist right now?
     *
     * Every index change below is guarded by this, and it is not defensive
     * programming for its own sake. This migration swaps a UNIQUE constraint,
     * so it is the one migration in the set that cannot simply be re-run from
     * a half-applied state: MySQL has no DROP INDEX IF EXISTS, so a `down()`
     * that assumes its own `up()` completed will throw and take the whole
     * rollback with it — which in the test suite means every later test class
     * fails at the refresh step, with an error naming an index rather than
     * anything to do with the test.
     *
     * Asking the schema what is actually there costs one cheap query and makes
     * both directions idempotent.
     */
    private function hasIndex(string $table, string $index): bool
    {
        $table = $this->db->prefixTable($table);

        return $this->db->query(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        )->getRowArray() !== null;
    }

    /** Does this column exist right now? Same reasoning as hasIndex(). */
    private function hasColumn(string $table, string $column): bool
    {
        $table = $this->db->prefixTable($table);

        return $this->db->query(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            [$table, $column]
        )->getRowArray() !== null;
    }

    public function up(): void
    {
        if (! $this->hasColumn('directory_verifications', 'plan')) {
            $this->forge->addColumn('directory_verifications', [
                'plan' => [
                    // Appended, not placed — see AddRegionToListings on why
                    // `after` forfeits ALGORITHM=INSTANT and rebuilds the table.
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'badge',
                    'null'       => false,
                ],
            ]);
        }

        // Swap the constraint, not just add one: the old single-column unique
        // index is exactly what has to stop applying.
        //
        // Add before drop, and not as a matter of taste. listing_id carries a
        // foreign key, InnoDB requires an index it can use for that key, and
        // it refuses to drop the last one that qualifies (errno 150). The new
        // composite has listing_id as its leftmost column, so once it exists
        // the FK is covered and the old index is free to go.
        $table = $this->db->prefixTable('directory_verifications');
        if (! $this->hasIndex('directory_verifications', 'uniq_listing_plan')) {
            $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `uniq_listing_plan` (`listing_id`, `plan`)");
        }
        if ($this->hasIndex('directory_verifications', 'listing_id')) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `listing_id`");
        }

        if (! $this->hasColumn('directory_listings', 'hosting_paid_until')) {
            $this->forge->addColumn('directory_listings', [
                'hosting_paid_until' => [
                    'type' => 'DATE', 'null' => true,
                ],
            ]);
        }

        $listings = $this->db->prefixTable('directory_listings');
        if (! $this->hasIndex('directory_listings', 'idx_hosting_paid_until')) {
            $this->db->query("ALTER TABLE `{$listings}` ADD INDEX `idx_hosting_paid_until` (`hosting_paid_until`)");
        }
    }

    public function down(): void
    {
        $listings = $this->db->prefixTable('directory_listings');
        if ($this->hasIndex('directory_listings', 'idx_hosting_paid_until')) {
            $this->db->query("ALTER TABLE `{$listings}` DROP INDEX `idx_hosting_paid_until`");
        }
        if ($this->hasColumn('directory_listings', 'hosting_paid_until')) {
            $this->forge->dropColumn('directory_listings', 'hosting_paid_until');
        }

        $table = $this->db->prefixTable('directory_verifications');

        // Rolling this back means the International Listing plan never existed,
        // so its rows cannot stay: the constraint being restored below is
        // UNIQUE(listing_id) alone, and a listing holding both an international
        // subscription and a badge has two rows that the old schema has no way
        // to represent. Leaving them would make the rollback fail outright —
        // which is exactly what it did, and it took every later test's database
        // refresh down with it.
        //
        // This is data loss, and deliberate. It is the only honest reading of
        // "undo the plan", the rows are meaningless without the column that
        // identifies them, and the listings themselves are untouched. Anyone
        // rolling this back in production should cancel the live subscriptions
        // in PayFast first — the mandates are held there, not here.
        if ($this->hasColumn('directory_verifications', 'plan')) {
            $this->db->query("DELETE FROM `{$table}` WHERE `plan` <> 'badge'");
        }

        // Same reasoning as up(), in reverse — the FK must stay covered
        // throughout, so add before dropping.
        if (! $this->hasIndex('directory_verifications', 'listing_id')) {
            $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `listing_id` (`listing_id`)");
        }
        if ($this->hasIndex('directory_verifications', 'uniq_listing_plan')) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `uniq_listing_plan`");
        }
        if ($this->hasColumn('directory_verifications', 'plan')) {
            $this->forge->dropColumn('directory_verifications', 'plan');
        }
    }
}
