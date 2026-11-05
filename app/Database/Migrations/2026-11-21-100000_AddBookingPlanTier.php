<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The Bookings plan's tier (Solo, Team, Business, decision T23) on the row,
 * since bookings came to local.webscheduler.app (D2): there the price is in
 * US dollars charged in rand, so the rand amount moves with the rate and
 * cannot tell which tier was bought. `checkout_tier` is what the open
 * checkout is for; it becomes `tier` when that payment comes in.
 */
class AddBookingPlanTier extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('booking_plans', [
            'tier'          => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'default' => null, 'after' => 'amount_cents'],
            'checkout_tier' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'default' => null, 'after' => 'tier'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('booking_plans', ['tier', 'checkout_tier']);
    }
}
