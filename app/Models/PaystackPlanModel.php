<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The Paystack plan made for each product, cycle and amount.
 *
 * A Paystack plan bills one fixed amount, while our amounts are snapshotted
 * per subscriber (an old badge at R29.99, Plus at whatever the dollar price
 * came to in rand that day). So a plan is made the first time an amount is
 * sold and reused after that. Written only by
 * VerificationService::paystackPlanCode().
 */
class PaystackPlanModel extends Model
{
    protected $table          = 'xs_paystack_plans';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $allowedFields  = ['product', 'cycle', 'amount_cents', 'currency', 'plan_code'];

    public function codeFor(string $product, string $cycle, int $amountCents, string $currency = 'ZAR'): ?string
    {
        $row = $this->where('product', $product)
            ->where('cycle', $cycle)
            ->where('amount_cents', $amountCents)
            ->where('currency', $currency)
            ->first();

        return is_array($row) ? (string) $row['plan_code'] : null;
    }
}
