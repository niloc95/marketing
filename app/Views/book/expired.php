<?php
$siteName = config('Directory')->siteName();
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Link not found | ' . $siteName, 'description' => 'This booking link no longer works.', 'canonical' => base_url('book/find'), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">This link no longer works</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300">Booking links change each time a booking moves, and end a while after it. <a class="underline" href="<?= base_url('book/find') ?>">Get a fresh link by email</a>.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
