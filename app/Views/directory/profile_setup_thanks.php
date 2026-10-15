<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Thank you | ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?php
/**
 * Where PayFast returns the buyer. Anyone can open this URL, so it claims
 * nothing about payment: the receipt email is the confirmation, sent once
 * PayFast's own notification has been checked.
 *
 * @var int $days
 */
?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Profile setup</span>
            <h1 class="mb-1.5 mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">Thank you</h1>
            <p class="mb-4 text-slate-600 dark:text-slate-300">
                As soon as PayFast confirms your payment, we email you a receipt. It usually arrives within a few minutes.
            </p>
            <p class="mb-6 text-slate-600 dark:text-slate-300">
                We will then be in touch to collect your logo, photos and details, and we complete your profile within
                <?= (int) $days ?> business days of having them.
            </p>
            <p class="hint">
                No email after an hour? Check your spam folder, then <a href="<?= base_url('contact') ?>">contact us</a> with the name of your business.
            </p>
            <a class="btn btn-ghost mt-6" href="<?= base_url('/') ?>">Back to the home page</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
