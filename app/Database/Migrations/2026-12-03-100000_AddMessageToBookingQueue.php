<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A message the business writes to a customer about an appointment (owner,
 * 24 Nov 2026: Email in the calendar's details). The subject and text wait
 * on the queue row until bookings:dispatch sends them in the standard
 * booking email; bookings:prune deletes the row with the rest.
 *
 * Two nullable columns, additive only, so it is safe to run before the code
 * copy at release.
 */
class AddMessageToBookingQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('booking_notification_queue', [
            'subject' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'after' => 'schedule_fingerprint'],
            'body'    => ['type' => 'TEXT', 'null' => true, 'after' => 'subject'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('booking_notification_queue', ['subject', 'body']);
    }
}
