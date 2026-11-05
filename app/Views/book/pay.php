<?php
/**
 * The step before PayFast (Book::payDeposit()): a button that posts the signed
 * form to PayFast. No honeypot field here (Config\Filters): PayFast refuses
 * a payment with a field its signature does not cover.
 *
 * @var array<string,mixed>                                       $listing
 * @var array<string,mixed>                                       $a
 * @var array{action:string,fields:array<string,string>}          $form
 * @var string                                                    $back the booking page's path
 */
helper('money');
$siteName = config('Directory')->siteName();
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Pay your deposit | ' . $siteName, 'description' => 'Pay the deposit for your booking with ' . $listing['display_name'] . '.', 'canonical' => base_url('book/find'), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">Pay your deposit</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300"><?= esc(money((int) $a['payment_amount_cents'] / 100, 'ZAR')) ?> to <?= esc($listing['display_name']) ?> for <?= esc((string) $a['service_name']) ?>. You pay on PayFast's own page, and come back here when it is done.</p>
            <form method="post" action="<?= esc($form['action'], 'attr') ?>" class="mt-4">
                <?php foreach ($form['fields'] as $name => $value): ?>
                    <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
                <?php endforeach ?>
                <button type="submit" class="btn btn-accent btn-block">Continue to PayFast</button>
            </form>
            <p class="mt-4 text-sm"><a class="underline" href="<?= base_url($back) ?>">Back to your booking</a></p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
