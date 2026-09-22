<?= $this->extend('layouts/public') ?>

<?php
/**
 * @var array<string,mixed>|null $listing null when the link is not recognised
 * @var string                   $token
 * @var bool                     $done
 */
$siteName = config('Directory')->siteName();
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Email preferences — ' . $siteName,
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Email preferences</span>

            <?php if ($listing === null): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">This link isn't valid</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    It may have been copied incompletely. You can change your email preferences any time from
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">Manage your profile</a>.
                </p>
            <?php elseif ($done): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">You're unsubscribed</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    We won't send monthly listing analytics to <?= esc($listing['display_name']) ?> any more.
                    Emails about the listing itself — edit links and any badge billing — still arrive.
                    Changed your mind? Opt back in from
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">Manage your profile</a>.
                </p>
            <?php else: ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Stop the monthly analytics email?</h1>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    This stops the monthly report on views and where your leads came from for
                    <strong><?= esc($listing['display_name']) ?></strong>. Your listing stays exactly as it is,
                    and emails it needs — edit links and any badge billing — still arrive.
                </p>
                <form method="post" action="<?= esc(base_url('unsubscribe/' . $token), 'attr') ?>">
                    <button type="submit" class="btn btn-accent btn-block">Unsubscribe</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
