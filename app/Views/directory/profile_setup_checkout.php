<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Pay for your profile setup | ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?php
/**
 * The profile setup checkout: what is being bought, then the pay button, a
 * post to ProfileSetup::pay(), which opens Paystack's checkout. Same shape
 * as verification_checkout.php.
 *
 * @var array<string,mixed>  $order
 * @var int                  $days
 * @var string               $processUrl ProfileSetup::pay()
 * @var bool                 $sandbox    Paystack test mode
 */
// On .app: the dollar price, then the rand Paystack actually charges.
$usd   = $usd ?? null;
$rand  = money(rtrim(rtrim((string) $order['amount'], '0'), '.'));
$price = $usd !== null ? money($usd, 'USD') : $rand;
?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <?php // The same two column checkout as verification_checkout.php: the button
              // opens Paystack's card window over this page (directory.js). ?>
        <div class="checkout-layout">
            <div class="checkout-main">
                <span class="eyebrow">Checkout</span>
                <h1 class="checkout-title">Pay for your profile setup</h1>
                <p class="checkout-lead">Here is exactly what you are paying for. Nothing has been charged yet.</p>

                <?php if ($sandbox): ?>
                    <p class="alert alert-warning">Test mode. No real money will move.</p>
                <?php endif; ?>

                <?php if ($usd !== null): ?>
                    <p class="alert alert-info">
                        <strong>Billed in South African rand.</strong> WebScheduler is a South African company,
                        so Paystack charges it in rand and your bank converts it to your own currency. The amount on your statement may differ slightly from <?= esc($price) ?>,
                        and some banks add a foreign transaction fee.
                    </p>
                <?php endif; ?>

                <ul class="checkout-terms">
                    <li>We email you to collect your details, then complete your profile within <?= (int) $days ?> business days of having them.</li>
                    <li>Your profile stays free and yours. Paying does not change where it appears in discovery results.</li>
                </ul>

                <form method="post" action="<?= esc($processUrl) ?>" class="checkout-form" data-paystack-checkout>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-accent btn-block" data-checkout-button><?= $usd !== null ? 'Pay ' . esc($price) : 'Pay ' . esc($rand) ?></button>
                    <p class="checkout-status" data-checkout-status role="status" aria-live="polite" hidden></p>
                </form>
                <p class="checkout-secure">Secure payment by Paystack. We never see your card details.</p>

                <p class="mt-4 text-center text-sm">
                    <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('profile-setup') ?>">Cancel and go back</a>
                </p>
            </div>

            <aside class="checkout-side" aria-labelledby="order-summary">
                <h2 class="checkout-side-title" id="order-summary">Order summary</h2>
                <p class="checkout-item">
                    <strong>Profile setup service</strong>
                    <span class="hint">for <?= esc($order['business_name']) ?></span>
                </p>
                <div class="checkout-summary">
                    <div class="checkout-line">
                        <span class="checkout-label">Reference</span>
                        <span><?= esc($order['reference']) ?></span>
                    </div>
                    <div class="checkout-line">
                        <span class="checkout-label">Paid</span>
                        <span>Once, nothing further to pay</span>
                    </div>
                    <div class="checkout-line checkout-total">
                        <span class="checkout-label">Due today</span>
                        <span><strong><?= esc($price) ?></strong><?php if ($usd !== null): ?>, billed in South African rand<?php endif; ?></span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
