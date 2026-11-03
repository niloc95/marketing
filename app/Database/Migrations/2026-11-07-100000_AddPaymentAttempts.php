<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How many payment references a subscription row or setup order has been
 * given (App\Libraries\PaymentReference). Each new reference takes the next
 * attempt, so a row never offers a gateway a reference it has seen: the
 * first is WSL-VER-M-000045, the next WSL-VER-Y-000045-2.
 *
 * Additive and defaulted: run it before the code copy.
 */
class AddPaymentAttempts extends Migration
{
    public function up(): void
    {
        $column = ['payment_attempts' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => false, 'default' => 0]];
        $this->forge->addColumn('directory_verifications', $column);
        $this->forge->addColumn('directory_profile_setup_orders', $column);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_verifications', ['payment_attempts']);
        $this->forge->dropColumn('directory_profile_setup_orders', ['payment_attempts']);
    }
}
