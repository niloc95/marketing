<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When an owner cancelled their Verified Business subscription.
 *
 * A column rather than a sixth state, deliberately. A cancelled subscription is
 * still active until the month it paid for runs out — the badge must stay up,
 * because they paid for it. Modelling that as a state would force a choice
 * between taking the badge down early (wrong, and a refund conversation) and
 * teaching every "is this live?" query to say "active OR cancelled" (which is
 * the kind of condition someone eventually forgets in one place).
 *
 * As a date it answers a different question — "will this renew?" — and leaves
 * everything that asks "is this live?" alone. The sweep still lapses the row on
 * paid_until, whatever the reason renewal stopped.
 *
 * NULL means "renewing normally", which is the correct reading for every row
 * that existed before this migration.
 */
class AddCancelledAtToVerifications extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_verifications', [
            'cancelled_at' => [
                'type' => 'DATETIME', 'null' => true, 'after' => 'activated_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_verifications', 'cancelled_at');
    }
}
