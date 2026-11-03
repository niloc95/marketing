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
$move      = $move ?? false;
$paidUntil = $paidUntil ?? null;
// Who takes the payment: 'Paystack' (the button posts to Manage::pay()) or
// 'PayFast' (the signed fields post straight to PayFast).
$gateway   = $gateway ?? 'PayFast';
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
        <?php // Two columns, as a checkout should read: the choice and the button on
              // the left, the order summary on the right (under the button on a
              // phone). With Paystack the button opens Paystack's card window over
              // this page (directory.js, "paystack checkout"); nobody is sent away. ?>
        <div class="checkout-layout">
            <div class="checkout-main">
                <span class="eyebrow">Checkout</span>
                <h1 class="checkout-title"><?= $product === 'badge' ? 'Activate your badge' : 'Activate ' . esc($what) ?></h1>
                <p class="checkout-lead">Here is exactly what you are agreeing to. Nothing has been charged yet.</p>

                <?php if ($sandbox): ?>
                    <p class="alert alert-warning"><?= $gateway === 'Paystack' ? 'Test mode' : 'Sandbox mode' ?>. No real money will move.</p>
                <?php endif; ?>

                <?php if ($choices !== []):
                    $monthlyPrice = $choices['monthly'] ?? null;
                    $yearlyPrice  = $choices['yearly'] ?? null;
                    $saving       = ($monthlyPrice !== null && $yearlyPrice !== null) ? round((float) $monthlyPrice * 12 - (float) $yearlyPrice, 2) : 0.0;
                    $planQuery    = $product === 'plus' ? 'plan=plus&' : '';
                ?>
                    <?php // Links that look like radio buttons: picking the other cycle
                          // reloads the page priced for it (Manage::checkout() and
                          // priceForCheckout()), so the summary can never disagree with
                          // what is charged. ?>
                    <h2 class="checkout-step">How often to pay</h2>
                    <div class="cycle-cards" role="radiogroup" aria-label="How often to pay">
                        <a class="cycle-card<?= $yearly ? '' : ' is-picked' ?>" role="radio" aria-checked="<?= $yearly ? 'false' : 'true' ?>" href="<?= base_url('manage/verification/checkout?' . $planQuery . 'cycle=monthly') ?>" data-track="checkout_cycle" data-track-label="monthly">
                            <span class="cycle-radio" aria-hidden="true"></span>
                            <span class="cycle-card-name">Monthly</span>
                            <span class="cycle-card-price"><?= esc($quoteOf($monthlyPrice)) ?> <span class="hint">a month</span></span>
                        </a>
                        <a class="cycle-card<?= $yearly ? ' is-picked' : '' ?>" role="radio" aria-checked="<?= $yearly ? 'true' : 'false' ?>" href="<?= base_url('manage/verification/checkout?' . $planQuery . 'cycle=yearly') ?>" data-track="checkout_cycle" data-track-label="yearly">
                            <span class="cycle-radio" aria-hidden="true"></span>
                            <span class="cycle-card-name">Yearly</span>
                            <span class="cycle-card-price"><?= esc($quoteOf($yearlyPrice)) ?> <span class="hint">a year</span></span>
                            <?php if ($saving > 0): ?>
                                <span class="cycle-card-save">Save <?= esc($quoteOf(number_format($saving, 2, '.', ''))) ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($move): ?>
                    <p class="alert alert-info">
                        <strong>Moving your payment to Paystack.</strong> You pay for your next <?= $period ?> today<?= $paidUntil ? ', and it starts after the time you have already paid for, which runs to ' . esc($paidUntil) : '' ?>.
                        Your PayFast payments stop as soon as this payment comes through, so you are never charged twice.
                    </p>
                <?php elseif ($switch): ?>
                    <p class="alert alert-info">
                        <strong>Switching to yearly.</strong> You pay for the year today<?= $paidUntil ? ', and it starts after the time you have already paid for, which runs to ' . esc($paidUntil) : '' ?>.
                        Your monthly payments stop as soon as this payment comes through.
                    </p>
                <?php endif; ?>

                <?php if ($product === 'plus'): ?>
                    <?php // Said before the card window, not discovered on a statement:
                          // the gateway charges rand only. See Legal terms section 4. ?>
                    <p class="alert alert-info">
                        <strong>Billed in South African rand.</strong> WebScheduler is a South African company,
                        so <?= esc($gateway) ?> charges it in rand every <?= $period ?> and your bank converts it to your own currency. The amount on your statement may differ slightly from
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
                </ul>

                <?php
                    // PayFast (the rollback): the array PayFast::subscriptionFields()
                    // built, emitted in order, because the signature covers the
                    // fields in submission order; no CSRF field, which the signature
                    // would not cover.
                    //
                    // Paystack: posts to our own Manage::pay(), with the CSRF token.
                    // data-paystack-checkout makes directory.js ask for JSON and open
                    // the card window over this page; without JavaScript the same
                    // POST redirects to Paystack's hosted page.
                ?>
                <form method="post" action="<?= esc($processUrl) ?>" class="checkout-form"<?= $gateway === 'Paystack' ? ' data-paystack-checkout' : '' ?>>
                    <?php if ($gateway === 'Paystack'): ?>
                        <?= csrf_field() ?>
                    <?php endif; ?>
                    <?php foreach ($fields as $name => $value): ?>
                        <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-accent btn-block" data-checkout-button><?= $product === 'plus' ? 'Subscribe to Plus' : 'Pay ' . esc(money($amount)) ?></button>
                    <p class="checkout-status" data-checkout-status role="status" aria-live="polite" hidden></p>
                </form>
                <p class="checkout-secure">Secure payment by <?= esc($gateway) ?>. We never see your card details.</p>

                <p class="mt-4 text-center text-sm">
                    <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage/edit') ?>">Cancel and go back</a>
                </p>
            </div>

            <?php // The order summary. Every figure comes from the verification row, so
                  // the page cannot say one thing while the gateway is told another. ?>
            <aside class="checkout-side" aria-labelledby="order-summary">
                <h2 class="checkout-side-title" id="order-summary">Order summary</h2>
                <p class="checkout-item">
                    <strong><?= esc($what) ?></strong>
                    <span class="hint">for <?= esc($listing['display_name']) ?></span>
                </p>
                <div class="checkout-summary">
                    <div class="checkout-line">
                        <span class="checkout-label">Price</span>
                        <?php if ($product === 'plus' && ! empty($plusUsd)): ?>
                            <span><strong><?= esc($quote) ?></strong> a <?= $period ?>, billed in South African rand</span>
                        <?php else: ?>
                            <span><strong><?= esc($quote) ?></strong> a <?= $period ?></span>
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
                        <span class="checkout-label">Due today</span>
                        <span><strong><?= esc($quote) ?></strong></span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
