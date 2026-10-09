<?= $this->extend('layouts/public') ?>

<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Partner sign in | ' . $siteName,
    'description' => 'Sign in to your ' . $siteName . ' partner dashboard.',
    'canonical'   => base_url('partners/login'),
    'robots'      => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Partner Program</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Sign in to your dashboard</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Enter the email you applied with and we'll send you a sign in link. No password needed.
            </p>

            <form method="post" action="<?= base_url('partners/login') ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="pl-email">Email address</label>
                    <input type="email" id="pl-email" name="email" required autofocus>
                </div>
                <button type="submit" class="btn btn-accent btn-block">Email me a link</button>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
                Not a partner yet? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('partners') ?>#apply">Apply here</a>.
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
