<?php
/**
 * Find my booking (Book::find): emails the links, never lists bookings.
 *
 * @var string $reference from /book/r/{reference}, shown only as a reminder
 */
$siteName = config('Directory')->siteName();
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Find your booking | ' . $siteName, 'description' => 'Get the links to change or cancel your bookings.', 'canonical' => base_url('book/find'), 'robots' => 'noindex, follow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Find your booking</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">Enter the email you booked with and we will email you the links to see, change or cancel your bookings.<?= $reference !== '' ? ' Booking ' . esc($reference) . '.' : '' ?></p>
            <form method="post" action="<?= base_url('book/find') ?>">
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <div class="field">
                    <label for="find-email">Email</label>
                    <input id="find-email" type="email" name="email" required maxlength="190" autocomplete="email">
                </div>
                <button type="submit" class="btn btn-accent btn-block">Email me my links</button>
            </form>
            <p class="mt-4 text-xs text-slate-500">Booked with a phone number only? Please contact the business.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
