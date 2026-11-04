<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Professional registration moves from the profile onto each person (owner,
 * 4 Nov 2026): a practice has several doctors, each with their own HPCSA
 * number, and the team is a Verified (Plus on .app) feature, so the numbers are
 * too. Which regulator applies still comes from the listing's category
 * (Config\Registrations); only the number, ORCID, ResearchGate link and the
 * admin's "checked against the register" mark are per person.
 *
 * A profile's number that was switched on is copied onto its person: the team
 * member with the profile's own name, else its only team member. Nothing else
 * is guessed. The listing columns stay as they were, unused, so down() loses
 * nothing.
 *
 * Additive and nullable: run it before the code copy.
 */
class AddProfessionalRegistrationToTeam extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listing_team', [
            'reg_number'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'orcid'            => ['type' => 'CHAR', 'constraint' => 19, 'null' => true],
            'researchgate_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'reg_checked_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $listings = $this->db->table('directory_listings')
            ->select('id, display_name, reg_number, orcid, researchgate_url, reg_checked_at')
            ->where('registration_visible', 1)
            ->where('deleted_at', null)
            ->groupStart()
                ->where("COALESCE(reg_number, '') <>", '')
                ->orWhere("COALESCE(orcid, '') <>", '')
                ->orWhere("COALESCE(researchgate_url, '') <>", '')
            ->groupEnd()
            ->get()->getResultArray();

        $plain = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $s)));

        foreach ($listings as $l) {
            $members = $this->db->table('directory_listing_team')
                ->select('id, name')->where('listing_id', $l['id'])->orderBy('sort_order')->get()->getResultArray();
            $target = null;
            foreach ($members as $m) {
                if ($plain((string) $m['name']) === $plain((string) $l['display_name'])) {
                    $target = (int) $m['id'];
                    break;
                }
            }
            if ($target === null && count($members) === 1) {
                $target = (int) $members[0]['id'];
            }
            if ($target === null) {
                continue;
            }
            $this->db->table('directory_listing_team')->where('id', $target)->update([
                'reg_number'       => $l['reg_number'] ?: null,
                'orcid'            => $l['orcid'] ?: null,
                'researchgate_url' => $l['researchgate_url'] ?: null,
                'reg_checked_at'   => $l['reg_checked_at'] ?: null,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listing_team', ['reg_number', 'orcid', 'researchgate_url', 'reg_checked_at']);
    }
}
