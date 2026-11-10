<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One person, one record (owner, 10 Nov 2026, decision T31): the profile's
 * team card is the person, and a booking provider is that card plus the
 * booking parts (colour, bookable, hours, services, sign in).
 *
 * - `show_on_profile` lets a person work in Bookings without appearing on the
 *   public profile (reception, a locum). Cards made in the profile form are
 *   shown, people added in Bookings start hidden.
 * - Every provider that is not archived gets its own card: one with no card,
 *   or one sharing a card with an earlier provider (dev profile 308: Paul
 *   Smith pointed at Dr Bertus's card), gets a hidden card made from its own
 *   name, title and bio.
 * - Then one provider per card, enforced by a unique index. NULL stays
 *   allowed: the FK sets it on a deleted card, and archived providers may
 *   have none.
 */
class UnifyTeamPeople extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listing_team', [
            'show_on_profile' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1, 'after' => 'sort_order'],
        ]);

        helper('slug');
        $taken = [];
        $rows  = $this->db->table('booking_providers')->orderBy('id')->get()->getResultArray();
        foreach ($rows as $p) {
            $card = $p['team_member_id'] === null ? null : (int) $p['team_member_id'];
            if ($card !== null && ! isset($taken[$card])) {
                $taken[$card] = true;

                continue;
            }
            if ($p['archived_at'] !== null) {
                // Archived: no card needed, and none shared.
                $this->db->table('booking_providers')->where('id', $p['id'])->update(['team_member_id' => null]);

                continue;
            }
            $listingId = (int) $p['listing_id'];
            $this->db->table('directory_listing_team')->insert([
                'listing_id'      => $listingId,
                'name'            => $p['name'],
                'slug'            => $this->slug($listingId, (string) $p['name']),
                'role'            => $p['title'],
                'bio'             => $p['bio'],
                'sort_order'      => 100 + (int) $p['sort_order'],
                'show_on_profile' => 0,
                'created_at'      => gmdate('Y-m-d H:i:s'),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            $new          = (int) $this->db->insertID();
            $taken[$new]  = true;
            $this->db->table('booking_providers')->where('id', $p['id'])->update(['team_member_id' => $new]);
        }

        $prefix = $this->db->getPrefix();
        $this->db->query("ALTER TABLE {$prefix}booking_providers ADD UNIQUE KEY booking_providers_team_member_unique (team_member_id)");
    }

    public function down(): void
    {
        $prefix = $this->db->getPrefix();
        $this->db->query("ALTER TABLE {$prefix}booking_providers DROP INDEX booking_providers_team_member_unique");
        $this->forge->dropColumn('directory_listing_team', ['show_on_profile']);
    }

    /** A slug unique within the listing's team, as DirectoryListingTeamModel::uniqueSlug() makes. */
    private function slug(int $listingId, string $name): string
    {
        $base = slugify($name) ?: 'member';
        $slug = $base;
        for ($n = 2; $this->db->table('directory_listing_team')->where('listing_id', $listingId)->where('slug', $slug)->countAllResults() > 0; $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }
}
