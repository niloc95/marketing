<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When the one-off "finish your profile" email went to this listing's owner.
 *
 * directory:quality:nudge mails a profile still under Config\Directory::
 * $qualityTarget a few days after it went live, once — this stamp is the "once".
 * NULL means never sent. It is written only after Mailer reports success, so a
 * mail outage leaves the row due and the next nightly run tries again.
 *
 * Additive and nullable: old code never reads or writes it, so it is safe to
 * run before the code copy, which is the order this project deploys in.
 */
class AddQualityNudgeSentAtToListings extends Migration
{
    public function up(): void
    {
        // No `after`, for the ALGORITHM=INSTANT reason AddQualityScoreToListings gives.
        $this->forge->addColumn('directory_listings', [
            'quality_nudge_sent_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'One-off profile-strength email; NULL = not sent',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'quality_nudge_sent_at');
    }
}
