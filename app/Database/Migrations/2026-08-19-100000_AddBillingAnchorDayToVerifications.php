<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The day of the month PayFast bills this subscription on.
 *
 * Needed because a renewal date cannot be derived from the previous one. PHP's
 * `+1 month` overflows a day the next month does not have — 31 January becomes
 * 3 March — while PayFast clamps to the last day of the short month and then
 * returns to the original day: 28 February, then 31 March, then 30 April.
 *
 * Storing only the last renewal date loses that original day the first time it
 * is clamped, and the loss is not harmless. A 31 January subscriber renews on
 * 28 February; computing one month on from *that* gives 28 March, while PayFast
 * charges on 31 March, and the badge of a paying customer comes down for three
 * days. Keeping the anchor separately is what makes the two agree.
 *
 * Written from the `billing_date` PayFast sends on the notification that opens
 * a subscription — the same one carrying `token` — so it is PayFast's own
 * answer rather than our reconstruction of it.
 *
 * NULL means "no anchor recorded": every row predating this migration, and
 * every badge activated by hand for an EFT payer, which has no PayFast mandate
 * and so no billing day to honour. VerificationService falls back to the day of
 * the month it is renewing from, which is the behaviour those rows have always
 * had.
 */
class AddBillingAnchorDayToVerifications extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_verifications', [
            'billing_anchor_day' => [
                'type'       => 'TINYINT',
                'constraint' => 2,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'pf_subscription_token',
                'comment'    => 'Day of month PayFast bills on, 1-31; NULL when there is no PayFast mandate',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_verifications', 'billing_anchor_day');
    }
}
