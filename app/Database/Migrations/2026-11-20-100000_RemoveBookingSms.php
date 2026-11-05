<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SMS is gone from online bookings (owner, 5 Nov 2026, decision T20): email,
 * and WhatsApp on the business's own account. Clears what SMS left behind:
 * accounts, rules, wording, queued messages and opt outs. Sent history in the
 * delivery logs stays until bookings:prune. Nothing to restore on the way
 * down: the code no longer sends SMS.
 */
class RemoveBookingSms extends Migration
{
    public function up(): void
    {
        foreach (['booking_integrations', 'booking_notification_rules', 'booking_templates', 'booking_notification_queue', 'booking_opt_outs'] as $table) {
            $this->db->table($table)->where('channel', 'sms')->delete();
        }
    }

    public function down(): void
    {
    }
}
