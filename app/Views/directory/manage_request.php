<?= $this->extend('layouts/public') ?>

<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Manage your listing — ' . $siteName,
    'description' => 'Update the details of your business listing in the ' . $siteName . '.',
    'canonical'   => base_url('manage'),
    'robots'      => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Manage your listing</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Edit your business details</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Enter the email address on your listing and we'll send you a link to edit it.
                No password needed.
            </p>

            <form method="post" action="<?= base_url('manage') ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label>Email address</label>
                    <input type="email" name="email" required autofocus placeholder="you@yourbusiness.co.za">
                </div>
                <button type="submit" class="btn btn-accent btn-block">Email me a link</button>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
                Not listed yet? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('list-your-practice') ?>">Add your business — free</a>.
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
