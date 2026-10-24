<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Pay for your profile setup | ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?php
/**
 * The profile setup checkout: what is being bought, then the button that
 * posts the signed fields to PayFast. Same shape as
 * verification_checkout.php, and exempt from the honeypot filter for the
 * same reason (see Config\Filters).
 *
 * @var array<string,mixed>  $order
 * @var int                  $days
 * @var string               $processUrl
 * @var array<string,string> $fields
 * @var bool                 $sandbox
 */
// On .app: the dollar price, then the rand PayFast actually charges.
$usd   = $usd ?? null;
$rand  = money(rtrim(rtrim((string) $order['amount'], '0'), '.'));
$price = $usd !== null ? money($usd, 'USD') : $rand;
?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Checkout</span>
            <h1 class="mb-1.5 mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">Pay for your profile setup</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Here is exactly what you are paying for. Nothing has been charged yet.
            </p>

            <?php if ($sandbox): ?>
                <p class="alert alert-warning">Sandbox mode. No real money will move.</p>
            <?php endif; ?>

            <div class="checkout-summary">
                <div class="checkout-line">
                    <span class="checkout-label">What</span>
                    <span>
                        Profile setup service<br>
                        <span class="hint">for <?= esc($order['business_name']) ?></span>
                    </span>
                </div>
                <div class="checkout-line">
                    <span class="checkout-label">Reference</span>
                    <span><?= esc($order['reference']) ?></span>
                </div>
                <div class="checkout-line checkout-total">
                    <span class="checkout-label">Due now</span>
                    <span><strong><?= esc($price) ?></strong>, paid once<?php if ($usd !== null): ?>, billed in South African rand<?php endif; ?></span>
                </div>
            </div>

            <ul class="checkout-terms">
                <li>We email you to collect your details, then complete your profile within <?= (int) $days ?> business days of having them.</li>
                <li>Your profile stays free and yours. Paying does not change where it appears in discovery results.</li>
                <li>PayFast takes the payment. <strong>We never see your card details.</strong></li>
            </ul>

            <?php // Emitted from PayFast::onceOffFields() in order: the signature
                  // covers the fields in submission order. ?>
            <form method="post" action="<?= esc($processUrl) ?>">
                <?php foreach ($fields as $name => $value): ?>
                    <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
                <?php endforeach; ?>
                <?php if ($usd !== null): ?>
                    <p class="alert alert-info">
                        <strong>Billed in South African rand.</strong> WebScheduler is a South African company,
                        so PayFast charges it in rand and your bank converts it to your own currency. The amount on your statement may differ slightly from <?= esc($price) ?>,
                        and some banks add a foreign transaction fee.
                    </p>
                <?php endif; ?>
                <button type="submit" class="btn btn-accent btn-block"><?= $usd !== null ? 'Pay with PayFast' : 'Pay ' . esc($rand) . ' with PayFast' ?></button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('profile-setup') ?>">Cancel and go back</a>
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
