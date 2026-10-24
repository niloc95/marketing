<?= $this->extend('layouts/public') ?>

<?php
// 'badge', 'international' (the co.za plan before local.webscheduler.app) or
// 'plus' (that plan on .app). Manage::checkout() decides; the wording follows.
$product = $product ?? 'badge';
$what    = ['badge' => 'Verified Business badge', 'international' => 'International Profile', 'plus' => 'WebScheduler Local Plus'][$product] ?? 'Verified Business badge';
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Activate your ' . $what . ' | ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Checkout</span>
            <h1 class="mb-1.5 mt-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?= $product === 'badge' ? 'Activate your badge' : 'Activate ' . esc($what) ?></h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Here is exactly what you are agreeing to. Nothing has been charged yet.
            </p>

            <?php if ($sandbox): ?>
                <p class="alert alert-warning">Sandbox mode. No real money will move.</p>
            <?php endif; ?>

            <?php // The order summary. Every figure comes from the verification row or
                  // from the fields being signed below, so the page cannot say one
                  // thing while PayFast is told another. ?>
            <div class="checkout-summary">
                <div class="checkout-line">
                    <span class="checkout-label">What</span>
                    <span>
                        <?= esc($what) ?><br>
                        <span class="hint">for <?= esc($listing['display_name']) ?></span>
                    </span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">Price</span>
                    <?php if ($product === 'plus' && ! empty($plusUsd)): ?>
                        <span><strong><?= esc(money($plusUsd, 'USD')) ?></strong> per month, billed in South African rand</span>
                    <?php else: ?>
                        <span><strong><?= esc(money($amount)) ?></strong> per month</span>
                    <?php endif; ?>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">First charge</span>
                    <span>Today, <?= esc($firstCharge) ?></span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">Then</span>
                    <span><?= esc($product === 'plus' && ! empty($plusUsd) ? money($plusUsd, 'USD') : money($amount)) ?> on <?= esc($renewsOn) ?>, and monthly after that</span>
                </div>
                <div class="checkout-line checkout-total">
                    <span class="checkout-label">Due now</span>
                    <span><strong><?= esc($product === 'plus' && ! empty($plusUsd) ? money($plusUsd, 'USD') : money($amount)) ?></strong></span>
                </div>
            </div>

            <?php if ($product === 'plus'): ?>
                <?php // Said before the card form, not discovered on a statement:
                      // PayFast charges rand only. See Legal terms section 4. ?>
                <p class="alert alert-info">
                    <strong>Billed in South African rand.</strong> WebScheduler is a South African company,
                    so PayFast charges it in rand every month and your bank converts it to your own currency. The amount on your statement may differ slightly from
                    <?= esc(money((string) $plusUsd, 'USD')) ?>, and some banks add a foreign transaction fee.
                </p>
            <?php endif; ?>
            <ul class="checkout-terms">
                <?php if ($product === 'plus'): ?>
                    <li>Cancel any time from your dashboard: Plus stays on until the month you have paid for ends.</li>
                    <li>Your business profile is free and stays published whether or not you buy Plus.</li>
                <?php elseif ($product === 'international'): ?>
                    <li>Cancel any time from your dashboard: your profile stays live until the month you have paid for ends.</li>
                <?php else: ?>
                    <li>Cancel any time from your dashboard: your badge stays up until the month you have paid for ends.</li>
                    <li>Your business profile is free and stays free whether or not you buy this.</li>
                <?php endif; ?>
                <li>PayFast takes the payment. <strong>We never see your card details.</strong></li>
            </ul>

            <?php
                // Emitted by iterating the array PayFast::subscriptionFields() built,
                // never by listing the fields here. The signature covers the fields in
                // submission order, so a hand-written form that happened to differ
                // would fail with nothing but "signature mismatch" to go on.
                //
                // No auto-submit script: the button is the control. That also means
                // this page needs no inline JavaScript and so no CSP nonce.
            ?>
            <form method="post" action="<?= esc($processUrl) ?>">
                <?php foreach ($fields as $name => $value): ?>
                    <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-accent btn-block"><?= $product === 'plus' ? 'Pay with PayFast' : 'Pay ' . esc(money($amount)) . ' with PayFast' ?></button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage/edit') ?>">Cancel and go back</a>
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
