<?= $this->extend('layouts/public') ?>

<?php
/**
 * Confirm switching off lead alerts. The GET only shows this page; the button
 * POSTs, so a mail scanner opening the link changes nothing.
 *
 * @var array<string,mixed> $listing
 * @var string              $token
 */
?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Stop request alerts — ' . config('Directory')->siteName(), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Request alerts</span>
            <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">Stop request emails?</h1>
            <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
                <strong><?= esc($listing['display_name']) ?></strong> will no longer be emailed when someone nearby
                posts a request for your kind of work. Your listing stays up, and you can still reply to requests on the
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('jobs?kind=service') ?>">Jobs board</a>.
            </p>
            <form method="post" action="<?= base_url('jobs/alerts/off/' . $token) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-accent btn-block">Stop request emails</button>
            </form>
            <?php if (! $listing['job_alerts']): ?>
                <p class="hint mt-3">They are already off.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
