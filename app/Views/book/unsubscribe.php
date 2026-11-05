<?php
/**
 * Stop one business's booking emails (Book::unsubscribePage / unsubscribe).
 * There is no way back in from here on purpose: the POST is CSRF exempt for
 * RFC 8058, so it may only ever stop emails.
 *
 * @var array<string,mixed> $listing
 * @var string              $hash
 * @var bool                $done
 */
$siteName = config('Directory')->siteName();
$business = (string) $listing['display_name'];
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Booking emails | ' . $siteName, 'description' => 'Stop booking emails from ' . $business . '.', 'canonical' => base_url('book/find'), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <?php if ($done): ?>
                <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">Done</h1>
                <p class="text-sm text-slate-600 dark:text-slate-300">We will not email you about bookings with <?= esc($business) ?> any more. Your bookings stay as they are: see them on <a class="underline" href="<?= base_url('book/customer/' . $hash) ?>">your bookings page</a>.</p>
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">If you want these emails again, ask <?= esc($business) ?>.</p>
            <?php else: ?>
                <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">Stop emails from <?= esc($business) ?>?</h1>
                <p class="text-sm text-slate-600 dark:text-slate-300">You will no longer get confirmations, reminders or changes to your bookings with <?= esc($business) ?> by email. Your bookings stay as they are, and other businesses are not affected.</p>
                <form method="post" action="<?= base_url('book/unsubscribe/' . $hash) ?>" class="mt-4">
                    <button type="submit" class="btn btn-primary">Stop these emails</button>
                </form>
            <?php endif ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
