<?php
/**
 * "Move my payment to Paystack" for an active subscription still billed by
 * PayFast, on the owner dashboard (_verification_panel.php, _plus_panel.php),
 * once Paystack takes new payments. The same offer the move email makes.
 *
 * Links to Manage::move(), a checkout for the same amount and cycle that
 * starts after the time already paid for; PayFast stops when it lands.
 *
 * @var array<string,mixed> $row     the verification row
 * @var bool                $payable VerificationService::canTakePayment()
 */
use App\Models\DirectoryVerificationModel;

$show = ($payable ?? false)
    && empty($row['cancelled_at'])
    && config('Directory')->paymentProvider() === DirectoryVerificationModel::PROVIDER_PAYSTACK
    && DirectoryVerificationModel::providerOf($row) === DirectoryVerificationModel::PROVIDER_PAYFAST
    && DirectoryVerificationModel::hasMandate($row);
?>
<?php if ($show): ?>
    <div class="yearly-switch">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            <strong>Please move your payment to Paystack.</strong> We have moved our payments from PayFast to Paystack.
            You pay the same price, it starts after the time you have already paid for, and PayFast stops as soon as it comes through.
        </p>
        <a class="btn btn-accent btn-xs mt-2" href="<?= base_url('manage/verification/move') ?>">Move my payment</a>
    </div>
<?php endif; ?>
