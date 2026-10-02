<?= $this->extend('layouts/public') ?>

<?php
/**
 * The "don't contact me again" link from a recommendation invite.
 *
 * @var array<string,mixed>|null $referral null when the link is not recognised
 * @var string                   $token
 * @var bool                     $done
 */
$siteName = config('Directory')->siteName();
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Stop invitations — ' . $siteName,
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Email preferences</span>

            <?php if ($referral === null): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">This link isn't valid</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    It may have expired or been copied incompletely. To ask us not to contact you,
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('contact') ?>">get in touch</a>.
                </p>
            <?php elseif ($done): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">We won't contact you again</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Your address is blocked from any future recommendation invite, whoever sends one.
                    If you change your mind, you can still
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(signup_cta()['url']) ?>">create your business profile</a> yourself.
                </p>
            <?php else: ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Stop invitations from <?= esc($siteName) ?>?</h1>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    We emailed you because someone recommended <strong><?= esc($referral['business_name']) ?></strong>.
                    We will not send another invite either way. This makes sure nobody else's recommendation leads to one.
                </p>
                <form method="post" action="<?= esc(base_url('recommend/stop/' . $token)) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-accent btn-block">Don't contact me again</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
