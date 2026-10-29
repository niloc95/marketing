<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A regulated professional's registration number, ORCID and ResearchGate
 * link, and the owner's switch for showing them.
 *
 * Which regulator applies (HPCSA, Legal Practice Council, SACAP, …) is not
 * stored: Config\Registrations derives it from the category, so a doctor who
 * moves category keeps the number and simply stops showing it.
 *
 * registration_visible defaults to off: nothing appears on a profile until the
 * owner turns it on. reg_checked_at is the admin's "checked against the
 * register" mark; ProfessionalRegistration::columns() clears it whenever the
 * owner changes the number. None of these are in OWNER_EDITABLE.
 *
 * Additive and nullable (or defaulted): old code neither reads nor writes
 * them. Run it before the code copy.
 */
class AddProfessionalRegistrationToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'reg_number'           => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'orcid'                => ['type' => 'CHAR', 'constraint' => 19, 'null' => true],
            'researchgate_url'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'registration_visible' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0],
            'reg_checked_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', ['reg_number', 'orcid', 'researchgate_url', 'registration_visible', 'reg_checked_at']);
    }
}
