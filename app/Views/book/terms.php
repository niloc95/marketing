<?php
/**
 * A business's own booking terms (Book::terms, Bookings' /booking/legal).
 *
 * @var array<string,mixed> $listing
 * @var array<string,mixed> $policies
 */
$siteName = config('Directory')->siteName();
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Booking terms of ' . $listing['display_name'] . ' | ' . $siteName, 'description' => 'How booking with ' . $listing['display_name'] . ' works.', 'canonical' => base_url('directory/' . $listing['slug'] . '/book/terms'), 'robots' => 'noindex, follow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <p class="mb-2 text-sm"><a class="underline" href="<?= base_url('directory/' . $listing['slug'] . '/book') ?>">Book online with <?= esc($listing['display_name']) ?></a></p>
        <h1 class="mb-6 text-2xl font-extrabold text-slate-900 dark:text-white">Booking terms</h1>
        <?php foreach (['terms' => 'Terms', 'cancellation' => 'Cancelling', 'rescheduling' => 'Changing a booking'] as $key => $heading): ?>
            <?php if (trim($policies[$key]) !== ''): ?>
                <h2 class="mb-2 mt-6 font-bold"><?= $heading ?></h2>
                <p class="whitespace-pre-line text-slate-700 dark:text-slate-200"><?= esc($policies[$key]) ?></p>
            <?php endif ?>
        <?php endforeach ?>
        <h2 class="mb-2 mt-6 font-bold">Online</h2>
        <ul class="list-disc pl-5 text-slate-700 dark:text-slate-200">
            <li><?= $policies['reschedule_hours'] > 0 ? 'You can change a booking online up to ' . (int) $policies['reschedule_hours'] . ((int) $policies['reschedule_hours'] === 1 ? ' hour' : ' hours') . ' before it starts.' : 'Bookings cannot be changed online. Please contact the business.' ?></li>
            <li><?= $policies['cancel_hours'] > 0 ? 'You can cancel online up to ' . (int) $policies['cancel_hours'] . ((int) $policies['cancel_hours'] === 1 ? ' hour' : ' hours') . ' before it starts.' : 'Bookings cannot be cancelled online. Please contact the business.' ?></li>
        </ul>
        <?php if ($policies['terms_url'] !== '' || $policies['privacy_url'] !== ''): ?>
            <p class="mt-6 text-sm">
                <?php if ($policies['terms_url'] !== ''): ?><a class="underline" href="<?= esc($policies['terms_url']) ?>" target="_blank" rel="noopener nofollow">Full terms of <?= esc($listing['display_name']) ?></a><?php endif ?>
                <?php if ($policies['privacy_url'] !== ''): ?><?= $policies['terms_url'] !== '' ? ' · ' : '' ?><a class="underline" href="<?= esc($policies['privacy_url']) ?>" target="_blank" rel="noopener nofollow">Their privacy policy</a><?php endif ?>
            </p>
        <?php endif ?>
        <p class="mt-6 text-sm text-slate-500">These are the business's own terms. WebScheduler Local runs the booking page; our <a class="underline" href="<?= base_url('terms') ?>">terms</a> and <a class="underline" href="<?= base_url('privacy') ?>">privacy policy</a> cover how.</p>
    </div>
</section>
<?= $this->endSection() ?>
