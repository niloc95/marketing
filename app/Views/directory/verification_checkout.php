<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Activate your Verified Business badge — ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Checkout</span>
            <h1 class="mb-1.5 mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">Activate your badge</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Here is exactly what you are agreeing to. Nothing has been charged yet.
            </p>

            <?php if ($sandbox): ?>
                <p class="alert alert-warning">Sandbox mode &mdash; no real money will move.</p>
            <?php endif; ?>

            <?php // The order summary. Every figure comes from the verification row or
                  // from the fields being signed below, so the page cannot say one
                  // thing while PayFast is told another. ?>
            <div class="checkout-summary">
                <div class="checkout-line">
                    <span class="checkout-label">What</span>
                    <span>
                        Verified Business badge<br>
                        <span class="hint">for <?= esc($listing['display_name']) ?></span>
                    </span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">Price</span>
                    <span><strong>R<?= esc($amount) ?></strong> per month</span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">First charge</span>
                    <span>Today, <?= esc($firstCharge) ?></span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">Then</span>
                    <span>R<?= esc($amount) ?> on <?= esc($renewsOn) ?>, and monthly after that</span>
                </div>
                <div class="checkout-line checkout-total">
                    <span class="checkout-label">Due now</span>
                    <span><strong>R<?= esc($amount) ?></strong></span>
                </div>
            </div>

            <ul class="checkout-terms">
                <li>Cancel any time from your dashboard &mdash; your badge stays up until the month you have paid for ends.</li>
                <li>Your listing is free and stays free whether or not you buy this.</li>
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
            <form method="post" action="<?= esc($processUrl, 'attr') ?>">
                <?php foreach ($fields as $name => $value): ?>
                    <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-accent btn-block">Pay R<?= esc($amount) ?> with PayFast</button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage/edit') ?>">Cancel and go back</a>
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
