<?= $this->extend('layouts/public') ?>

<?php
// 'badge', 'international' (the co.za plan before local.webscheduler.app) or
// 'plus' (that plan on .app). Manage::checkout() decides; the wording follows.
$product = $product ?? 'badge';
$what    = ['badge' => 'Verified Business badge', 'international' => 'International Profile', 'plus' => 'WebScheduler Local Plus'][$product] ?? 'Verified Business badge';
// Monthly or yearly (Manage::renderCheckout()). $choices is empty when there
// is nothing to pick: yearly not on sale, or a switch, which is to yearly.
$cycle     = $cycle ?? 'monthly';
$yearly    = $cycle === 'yearly';
$period    = $yearly ? 'year' : 'month';
$choices   = $choices ?? [];
$switch    = $switch ?? false;
$paidUntil = $paidUntil ?? null;
// Plus is quoted in dollars, everything else in rand.
$quote     = $product === 'plus' && ! empty($plusUsd) ? money($plusUsd, 'USD') : money($amount);
$quoteOf   = static fn (?string $v): string => $v === null ? '' : ($product === 'plus' ? money($v, 'USD') : money($v));
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

            <?php if ($choices !== []):
                $monthlyPrice = $choices['monthly'] ?? null;
                $yearlyPrice  = $choices['yearly'] ?? null;
                $saving       = ($monthlyPrice !== null && $yearlyPrice !== null) ? round((float) $monthlyPrice * 12 - (float) $yearlyPrice, 2) : 0.0;
                $planQuery    = $product === 'plus' ? 'plan=plus&' : '';
            ?>
                <?php // Links, not a form: the PayFast fields below are signed for one
                      // cycle, so picking the other reloads the page priced for it. ?>
                <div class="cycle-cards" role="group" aria-label="How often to pay">
                    <a class="cycle-card<?= $yearly ? '' : ' is-picked' ?>" href="<?= base_url('manage/verification/checkout?' . $planQuery . 'cycle=monthly') ?>" data-track="checkout_cycle" data-track-label="monthly"<?= $yearly ? '' : ' aria-current="true"' ?>>
                        <span class="cycle-card-name">Monthly</span>
                        <span class="cycle-card-price"><?= esc($quoteOf($monthlyPrice)) ?> <span class="hint">a month</span></span>
                    </a>
                    <a class="cycle-card<?= $yearly ? ' is-picked' : '' ?>" href="<?= base_url('manage/verification/checkout?' . $planQuery . 'cycle=yearly') ?>" data-track="checkout_cycle" data-track-label="yearly"<?= $yearly ? ' aria-current="true"' : '' ?>>
                        <span class="cycle-card-name">Yearly</span>
                        <span class="cycle-card-price"><?= esc($quoteOf($yearlyPrice)) ?> <span class="hint">a year</span></span>
                        <?php if ($saving > 0): ?>
                            <span class="cycle-card-save">Save <?= esc($quoteOf(number_format($saving, 2, '.', ''))) ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($switch): ?>
                <p class="alert alert-info">
                    <strong>Switching to yearly.</strong> You pay for the year today<?= $paidUntil ? ', and it starts after the time you have already paid for, which runs to ' . esc($paidUntil) : '' ?>.
                    Your monthly payments stop as soon as this payment comes through.
                </p>
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
                        <span><strong><?= esc($quote) ?></strong> per <?= $period ?>, billed in South African rand</span>
                    <?php else: ?>
                        <span><strong><?= esc($quote) ?></strong> per <?= $period ?></span>
                    <?php endif; ?>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">First charge</span>
                    <span>Today, <?= esc($firstCharge) ?></span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">Then</span>
                    <span><?= esc($quote) ?> on <?= esc($renewsOn) ?>, and <?= $yearly ? 'yearly' : 'monthly' ?> after that</span>
                </div>
                <div class="checkout-line checkout-total">
                    <span class="checkout-label">Due now</span>
                    <span><strong><?= esc($quote) ?></strong></span>
                </div>
            </div>

            <?php if ($product === 'plus'): ?>
                <?php // Said before the card form, not discovered on a statement:
                      // PayFast charges rand only. See Legal terms section 4. ?>
                <p class="alert alert-info">
                    <strong>Billed in South African rand.</strong> WebScheduler is a South African company,
                    so PayFast charges it in rand every <?= $period ?> and your bank converts it to your own currency. The amount on your statement may differ slightly from
                    <?= esc(money((string) $plusUsd, 'USD')) ?>, and some banks add a foreign transaction fee.
                </p>
            <?php endif; ?>
            <ul class="checkout-terms">
                <?php if ($product === 'plus'): ?>
                    <li>Cancel any time from your dashboard: Plus stays on until the <?= $period ?> you have paid for ends.</li>
                    <li>Your business profile is free and stays published whether or not you buy Plus.</li>
                <?php elseif ($product === 'international'): ?>
                    <li>Cancel any time from your dashboard: your profile stays live until the <?= $period ?> you have paid for ends.</li>
                <?php else: ?>
                    <li>Cancel any time from your dashboard: your badge stays up until the <?= $period ?> you have paid for ends.</li>
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
