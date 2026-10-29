<?= $this->extend('layouts/public') ?>

<?php // Directory::previewGone(): an unknown, expired or already approved
      // preview link. One page for all three, so the URL says nothing about
      // which profiles exist. ?>
<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Preview link | ' . $siteName,
    'description' => 'This preview link is no longer active.',
    'canonical'   => base_url(),
    'robots'      => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Business profile preview</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">This preview link is no longer active</h1>
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                Either the profile has already been approved and is live, or the link has expired.
            </p>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                If your profile is live, you can edit it any time from
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">Manage your profile</a>.
                Need a new preview? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('contact') ?>">Contact us</a> and we'll send one.
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
