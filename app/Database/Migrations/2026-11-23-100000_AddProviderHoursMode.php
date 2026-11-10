<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Whose hours a person works (owner, 10 Nov 2026): `profile` follows the
 * profile's opening hours as they are now, so a change there reaches
 * booking at once; `own` is the person's own periods in
 * booking_provider_hours (still kept inside opening hours). Rows stay when
 * a person switches to `profile`, so switching back brings them back.
 *
 * `hours_seen` is a fingerprint of the opening hours (profile and branches)
 * when someone last set or kept a person's own hours. When the opening hours
 * change, the team page and the setup check ask whether to follow them.
 *
 * Existing people keep `own`: nothing they book changes. New default
 * providers start on `profile` (TeamService::ensureDefaultProvider()).
 */
class AddProviderHoursMode extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('booking_providers', [
            'hours_mode' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => false, 'default' => 'own', 'after' => 'bookable'],
            'hours_seen' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null, 'after' => 'hours_mode'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('booking_providers', ['hours_mode', 'hours_seen']);
    }
}
